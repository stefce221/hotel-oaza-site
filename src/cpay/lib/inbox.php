<?php
/* Hotel Oaza — raw copies of what arrives at the cPay addresses registered with CaSys (/cpay/ok.php, /cpay/fail.php):
   one JSON file per notice in <private>/cpay-inbox, with the channel it came through and whether it was verified
   (or, for a body that holds no field, the start of that body).
   cPay's notices carry no card numbers. Keeping a copy never decides an answer: lib/notice.php verifies and marks
   the payment first, and a copy that cannot be kept is only logged. */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/api/lib/bootstrap.php';
require_once __DIR__ . '/payments.php'; /* payments_quietly() */

/* These addresses are public, so a stranger could post to them in a loop. Limits keep the hosting quota safe. */
const CPAY_MAX_BYTES = 16384;
const CPAY_MAX_FIELDS = 50;
const CPAY_MAX_VALUE = 1024;
const CPAY_MAX_FILES = 500;
const CPAY_MAX_PER_HOUR = 20;
/* The hotel charges cards in batches (two dozen payments within half an hour, each pushed at least twice),
   so pushes from cPay's own addresses (cpay.push_ips in the config) skip the hourly cap and get more room. */
const CPAY_MAX_FILES_FROM_CPAY = 5000;
/* The kind is part of the copy's file name. */
const CPAY_COPY_KIND = '/\A[a-z]{1,10}\z/';

/** True when the request comes straight from one of cPay's addresses in the config. Forwarded headers never count. */
function from_cpay(#[\SensitiveParameter] array $config, array $server): bool
{
    $listed = is_array($config['cpay'] ?? null) ? ($config['cpay']['push_ips'] ?? []) : [];
    $address = visitor_address((string) ($server['REMOTE_ADDR'] ?? ''));
    return $address !== 'unknown' && is_array($listed) && in_array($address, $listed, true);
}

/** The first CPAY_MAX_VALUE characters of a value; of a value that is not UTF-8, its first CPAY_MAX_VALUE bytes. */
function cut_cpay_value(string $value): string
{
    if (strlen($value) <= CPAY_MAX_VALUE) {
        return $value;
    }
    return preg_match('/\A.{0,' . CPAY_MAX_VALUE . '}/us', $value, $match) === 1 ? $match[0] : substr($value, 0, CPAY_MAX_VALUE);
}

/** At most CPAY_MAX_FIELDS fields with string values, each value cut to CPAY_MAX_VALUE characters. */
function limit_cpay_fields(array $fields): array
{
    $kept = [];
    foreach ($fields as $name => $value) {
        if (count($kept) >= CPAY_MAX_FIELDS) {
            break;
        }
        if (is_string($value)) {
            $kept[$name] = cut_cpay_value($value);
        }
    }
    return $kept;
}

/** Why a copy that keeps the limits must not be kept: a log code ('' when it may be). Never contains a field value. */
function cpay_refusal(#[\SensitiveParameter] array $config, string $inbox, bool $fromCpay): string
{
    $maxFiles = $fromCpay ? CPAY_MAX_FILES_FROM_CPAY : CPAY_MAX_FILES;
    if (is_dir($inbox) && count(scandir($inbox) ?: []) - 2 >= $maxFiles) {
        return 'cpay_inbox_full';
    }
    if ($fromCpay) {
        return ''; /* a batch of charges must be kept whole */
    }
    try {
        $limited = rate_limited(
            $config['private_dir'] . '/cpay-ratelimit.json',
            visitor_key($config['rate']['salt'], $_SERVER),
            CPAY_MAX_PER_HOUR,
            3600,
            time(),
        );
    } catch (Throwable $e) {
        log_error($config['private_dir'], 'cpay_rate_unavailable');
        return ''; /* a broken counter must not drop a payment notice */
    }
    return $limited ? 'cpay_rate_limited' : '';
}

/** Writes one copy as <inbox>/YYYYMMDD-HHMMSS-<kind>-<random>.json, readable by the owner only.
    Returns '' or the code of what went wrong: cpay_inbox (the folder cannot be made), cpay_write (the file cannot be written). */
function write_cpay_copy(string $inbox, string $kind, array $copy): string
{
    if (preg_match(CPAY_COPY_KIND, $kind) !== 1) {
        return 'cpay_write';
    }
    if (!is_dir($inbox) && !payments_quietly(static fn (): bool => mkdir($inbox, 0700, true)) && !is_dir($inbox)) {
        return 'cpay_inbox';
    }
    $file = sprintf('%s/%s-%s-%s.json', $inbox, date('Ymd-His'), $kind, bin2hex(random_bytes(4)));
    $json = json_encode($copy, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    if (payments_quietly(static fn () => file_put_contents($file, $json)) !== strlen($json)) {
        return 'cpay_write';
    }
    payments_quietly(static fn (): bool => chmod($file, 0600));
    return '';
}

/**
 * Keeps one raw copy in <private>/cpay-inbox: {received, kind, via, verified, fields}.
 * $kind 'ok' | 'fail' (the address that was called); $via 'push' | 'browser' | 'unlisted'; $verified is what the
 * copy says. An $exempt copy is kept whatever its sender, past the hourly cap and the file ceilings: the caller
 * exempts a verified notice that joined its payment's record, or whose record could not be written (design 4.7).
 * Every other copy, verified or not, keeps the limits these addresses always had: 20 an hour and 500 files for
 * strangers, 5000 files for cPay's own addresses ($via 'push'). The size limit (CPAY_MAX_BYTES) is the caller's to
 * check, and of more than CPAY_MAX_FIELDS fields the first are kept: the caller puts first what the copy must not lose.
 * Never throws: a refusal or a failure is logged by its code, and anything else by its class name only.
 */
function save_cpay_notice(#[\SensitiveParameter] array $config, string $kind, array $fields, string $via, bool $verified, bool $exempt = false): void
{
    $copy = ['received' => date('c'), 'kind' => $kind, 'via' => $via, 'verified' => $verified, 'fields' => limit_cpay_fields($fields)];
    keep_cpay_copy($config, $kind, $copy, $exempt);
}

/**
 * Keeps the raw copy of a body that was read and holds no field: {received, kind, via, verified: false, fields: [],
 * body}, with the body's first CPAY_MAX_VALUE characters. Never exempt: it keeps the limits of everything unverified
 * (see save_cpay_notice()). The size limit is the caller's to check. Never throws.
 */
function save_cpay_body(#[\SensitiveParameter] array $config, string $kind, string $body, string $via): void
{
    $copy = ['received' => date('c'), 'kind' => $kind, 'via' => $via, 'verified' => false, 'fields' => [], 'body' => cut_cpay_value($body)];
    keep_cpay_copy($config, $kind, $copy, false);
}

/** Writes a copy of save_cpay_notice() or save_cpay_body(), unless the limits refuse it; never throws (see save_cpay_notice()). */
function keep_cpay_copy(#[\SensitiveParameter] array $config, string $kind, array $copy, bool $exempt): void
{
    try {
        $inbox = $config['private_dir'] . '/cpay-inbox';
        $refusal = $exempt ? '' : cpay_refusal($config, $inbox, $copy['via'] === 'push');
        $problem = $refusal !== '' ? $refusal : write_cpay_copy($inbox, $kind, $copy);
        if ($problem !== '') {
            log_error($config['private_dir'], $problem);
        }
    } catch (Throwable $e) {
        error_log('Hotel Oaza cPay notice: raw copy not kept, ' . get_class($e));
    }
}
