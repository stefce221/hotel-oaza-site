<?php
/* Hotel Oaza — placeholder for the cPay addresses registered with CaSys (/cpay/ok.php, /cpay/fail.php).
   Answers 200 so CaSys neither retries nor reports a failed merchant notification, and keeps each push
   in the private folder until the cPay project replaces these files. cPay pushes carry no card numbers. */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/api/lib/bootstrap.php';

/* These addresses are public, so a stranger could post to them in a loop. Limits keep the hosting quota safe. */
const CPAY_MAX_BYTES = 16384;
const CPAY_MAX_FIELDS = 50;
const CPAY_MAX_VALUE = 1024;
const CPAY_MAX_FILES = 500;
const CPAY_MAX_PER_HOUR = 20;
/* The hotel charges cards in batches (two dozen payments within half an hour, each pushed at least twice),
   so pushes from cPay's own addresses (cpay.push_ips in the config) skip the hourly cap and get more room. */
const CPAY_MAX_FILES_FROM_CPAY = 5000;

/** True when the request comes straight from one of cPay's addresses in the config. Forwarded headers never count. */
function from_cpay(array $config, array $server): bool
{
    $listed = is_array($config['cpay'] ?? null) ? ($config['cpay']['push_ips'] ?? []) : [];
    $address = visitor_address((string) ($server['REMOTE_ADDR'] ?? ''));
    return $address !== 'unknown' && is_array($listed) && in_array($address, $listed, true);
}

/** Keeps the push (when it has fields and is within the limits) and shows a short bilingual page, whatever happens. */
function cpay_placeholder(string $kind): void
{
    if ($_POST !== []) {
        save_cpay_push($kind, $_POST, (int) ($_SERVER['CONTENT_LENGTH'] ?? 0));
    }
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    echo cpay_page();
}

/** At most CPAY_MAX_FIELDS fields with string names and values, each value cut to CPAY_MAX_VALUE characters. */
function limit_cpay_fields(array $fields): array
{
    $kept = [];
    foreach ($fields as $name => $value) {
        if (count($kept) >= CPAY_MAX_FIELDS) {
            break;
        }
        if (is_string($value)) {
            $kept[$name] = function_exists('mb_substr')
                ? mb_substr($value, 0, CPAY_MAX_VALUE, 'UTF-8')
                : substr($value, 0, CPAY_MAX_VALUE);
        }
    }
    return $kept;
}

/** Why this push must not be saved: a log code ('' when it may be). Never contains a field value. */
function cpay_refusal(array $config, string $inbox, int $length, bool $fromCpay): string
{
    if ($length > CPAY_MAX_BYTES) {
        return 'cpay_too_large';
    }
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
        return ''; /* a broken counter must not drop a payment notice */
    }
    return $limited ? 'cpay_rate_limited' : '';
}

/** private/cpay-inbox/YYYYMMDD-HHMMSS-<kind>-<random>.json; a failure is logged, never shown. */
function save_cpay_push(string $kind, array $fields, int $length): void
{
    try {
        $config = load_config(config_path());
    } catch (Throwable $e) {
        error_log('Hotel Oaza cPay placeholder: ' . ($e instanceof RuntimeException ? $e->getMessage() : get_class($e) . ' on line ' . $e->getLine()));
        return;
    }
    try {
        $dir = $config['private_dir'] . '/cpay-inbox';
        $refusal = cpay_refusal($config, $dir, $length, from_cpay($config, $_SERVER));
        if ($refusal !== '') {
            log_error($config['private_dir'], $refusal);
            return;
        }
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
            throw new RuntimeException('cpay_inbox');
        }
        $record = ['received' => date('c'), 'kind' => $kind, 'fields' => limit_cpay_fields($fields)];
        $file = sprintf('%s/%s-%s-%s.json', $dir, date('Ymd-His'), $kind, bin2hex(random_bytes(4)));
        $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        if (file_put_contents($file, $json) === false) {
            throw new RuntimeException('cpay_write');
        }
        chmod($file, 0600);
    } catch (Throwable $e) {
        error_log('Hotel Oaza cPay placeholder: ' . ($e instanceof RuntimeException ? $e->getMessage() : get_class($e) . ' on line ' . $e->getLine()));
    }
}

function cpay_page(): string
{
    return <<<'HTML'
<!doctype html>
<html lang="mk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Хотел Оаза — статус на плаќањето</title>
<style>body{font:1rem/1.6 system-ui,sans-serif;max-width:36rem;margin:4rem auto;padding:0 1rem;color:#1d2a30}a{color:#0a6f99}hr{margin:2rem 0}</style>
</head>
<body>
<h1>Го примивме статусот на плаќањето.</h1>
<p>Ви благодариме. За прашања околу плаќањето, контактирајте го хотелот: <a href="tel:+38970310300">+389 70 310 300</a>, <a href="mailto:info@oazahotel.com.mk">info@oazahotel.com.mk</a>.</p>
<p><a href="/">Хотел Оаза — почетна</a></p>
<hr>
<div lang="en">
<h2>Payment status received.</h2>
<p>Thank you. For any question about your payment, contact the hotel: <a href="tel:+38970310300">+389 70 310 300</a>, <a href="mailto:info@oazahotel.com.mk">info@oazahotel.com.mk</a>.</p>
<p><a href="/en/">Hotel Oaza — home</a></p>
</div>
</body>
</html>
HTML;
}
