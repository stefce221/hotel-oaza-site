<?php
/* Hotel Oaza — what /cpay/ok.php and /cpay/fail.php do: verify what cPay reports about a payment, mark the
   payment's record, keep a raw copy and answer (design sections 4.5 to 4.7 of
   docs/superpowers/specs/2026-11-11-cpay-vcc-charging-design.md).
   The outcome is not signed: which address was called is the only statement of success, so the record list helps
   staff, and the cPay portal and the bank decide about money. The sender's address is never used for trust: it
   only names the channel and chooses between a short OK (cPay's own push) and a page (everyone else).
   The log gets codes only, never anything that was sent; the one exception is the address of an unlisted sender. */
declare(strict_types=1);

require_once __DIR__ . '/entry.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/charge.php';
require_once __DIR__ . '/payments.php';
require_once __DIR__ . '/pages.php';
require_once __DIR__ . '/inbox.php';
require_once __DIR__ . '/notice-pages.php';

/* How these addresses name themselves in PHP's log. */
const NOTICE_WHO = 'cPay notice';
/* cPayPaymentRef: the specification says numeric, up to 10 digits; up to 20 are taken (design 4.5, point 6). */
const NOTICE_CPAY_REF = '/\A[0-9]{1,20}\z/';
/** Everything ok.php ('ok') and fail.php ('fail') do. Anything unexpected ends as 500 with the unconfirmed page, so cPay tries again. */
function cpay_notice(string $kind): void
{
    guard_uncaught(NOTICE_WHO, static function (): void {
        send_page(500, unconfirmed_page());
    });
    if (!in_array($kind, PAYMENT_NOTICE_KINDS, true)) {
        throw new InvalidArgumentException('notice_kind');
    }
    $input = notice_input($_POST, $_GET, $_SERVER, 'notice_read_body');
    if ($input['fields'] === [] && $input['body'] === '') {
        send_page(200, neutral_page()); /* someone opened the address */
        return;
    }
    $config = try_load_config(NOTICE_WHO);
    if ($config === null) {
        send_notice_answer(notice_shaped($input['fields']) ? notice_answer(500, 'unconfirmed') : notice_answer(200, 'neutral'));
        return;
    }
    send_notice_answer($input['fields'] === []
        ? notice_unparsed_outcome($kind, $input['body'], $config, $_SERVER)
        : notice_outcome($kind, $input['fields'], $config, $_SERVER, strlen($input['body'])));
}

/** What was sent: the POST fields, or the query string when nothing was posted (the specification allows both for the
    browser's return). String values only. */
function notice_fields(array $post, array $get): array
{
    return array_filter($post !== [] ? $post : $get, 'is_string');
}

/**
 * What was sent, with the body that was read for it. fields: as notice_fields() takes them, except that a POST of
 * which PHP read no text field (no Content-Type, text/plain, application/octet-stream, a form of lists only, a
 * multipart type PHP would not parse) has its body read through $read and parsed as a form body is. A multipart body
 * that PHP did parse leaves php://input empty, so reading it finds nothing and changes nothing; no Content-Type rule
 * is needed. A body that was read decides alone, also when it holds no field: the query string
 * counts only for a request without a body. A form that PHP read text fields from keeps them, and is read again
 * only when no Content-Length states its size (a body sent in chunks), to be measured (design 4.7).
 * body: what was read, '' when nothing was; at most CPAY_MAX_BYTES + 1 bytes are asked for, so a body past the limit
 * shows as one. cPay posts form fields; without this, a push whose Content-Type went missing on the way would be
 * answered 200 with the neutral page, which stops cPay's retries, and leave no trace (design 4.6).
 * @param callable(int): string $read reads at most that many bytes of the body: notice_read_body(), or a test's own
 * @return array{fields: array<int|string, string>, body: string}
 */
function notice_input(array $post, array $get, array $server, callable $read): array
{
    $readable = ($server['REQUEST_METHOD'] ?? '') === 'POST';
    $parsed = array_filter($post, 'is_string');
    $unmeasured = (string) ($server['CONTENT_LENGTH'] ?? '') === '';
    $body = $readable && ($parsed === [] || $unmeasured) ? $read(CPAY_MAX_BYTES + 1) : '';
    $body = is_string($body) ? $body : '';
    if ($parsed !== [] || $body === '') {
        return ['fields' => notice_fields($post, $get), 'body' => $body];
    }
    return ['fields' => notice_body_fields($body), 'body' => $body];
}

/** At most $most bytes of the request's body as PHP received it; '' when there is none or it cannot be read. */
function notice_read_body(int $most): string
{
    $body = payments_quietly(static fn () => file_get_contents('php://input', false, null, 0, $most));
    return is_string($body) ? $body : '';
}

/** The fields of a body PHP did not parse, read as PHP reads a form body: string values only (lists are dropped).
    No warning escapes: parse_str() raises one past max_input_vars, and keeps the fields before it. */
function notice_body_fields(string $body): array
{
    $fields = payments_quietly(static function () use ($body): array {
        parse_str($body, $parsed);
        return $parsed;
    });
    return array_filter($fields, 'is_string');
}

/** True for fields that carry a ReturnCheckSum and a ReturnCheckSumHeader: what cPay sends, as opposed to anything else. */
function notice_shaped(array $fields): bool
{
    foreach (['ReturnCheckSum', 'ReturnCheckSumHeader'] as $name) {
        if (!is_string($fields[$name] ?? null) || $fields[$name] === '') {
            return false;
        }
    }
    return true;
}

/** push for one of cPay's own addresses ($fromCpay); otherwise browser when the request carries an Origin or
    Sec-Fetch-Site header, else unlisted. */
function notice_channel(bool $fromCpay, array $server): string
{
    if ($fromCpay) {
        return 'push';
    }
    foreach (['HTTP_ORIGIN', 'HTTP_SEC_FETCH_SITE'] as $header) {
        if (is_string($server[$header] ?? null) && $server[$header] !== '') {
            return 'browser';
        }
    }
    return 'unlisted';
}

/**
 * The size of what was sent, in bytes: the largest of the body as its Content-Length states it, the query string,
 * the names and values of the text fields as PHP read them, and the body as the handler read it ($bodyBytes, for a
 * body PHP did not parse). The fields are counted because a header can be missing or say less: a body sent in chunks
 * has no Content-Length. Their count is never more than the body or the query string they were read from (decoded
 * names and values, without separators), so it refuses nothing that an honest Content-Length would let through.
 * @param array<int|string, mixed> $fields notice_fields(): what is verified and kept
 */
function notice_bytes(array $server, array $fields, int $bodyBytes = 0): int
{
    $read = 0;
    foreach ($fields as $name => $value) {
        $read += is_string($value) ? strlen((string) $name) + strlen($value) : 0;
    }
    return max((int) ($server['CONTENT_LENGTH'] ?? 0), strlen((string) ($server['QUERY_STRING'] ?? '')), $read, $bodyBytes);
}

/**
 * Points 1 to 3 of design section 4.5 and the shape of the reference: the checksum holds for the configured key;
 * the header names PayToMerchant, and it is this merchant; MerchantName and AmountCurrency, when named, are this
 * merchant's name and MKD; the header names Details2, and it has the shape of a payment's reference (checked
 * before any file name is built from it); the header names AmountToPay (an amount that is posted but not signed
 * is no amount: the code is the one of an amount that differs from the record's). Only values the header names
 * are looked at.
 * @param array<string, mixed> $settings cpay_settings(): key, merchant_id, merchant_name
 * @return array{code: string, signed: array<int|string, string>} code '' | 'cpay_bad_checksum' | 'cpay_wrong_merchant' |
 *         'cpay_bad_reference' | 'cpay_amount_mismatch'; signed is the name => value map of the header, and empty
 *         whenever there is a code
 */
function checked_notice(array $fields, #[\SensitiveParameter] array $settings): array
{
    $refused = static fn (string $code): array => ['code' => $code, 'signed' => []];
    $signed = verified_return($fields, (string) ($settings['key'] ?? ''));
    if ($signed === null) {
        return $refused('cpay_bad_checksum');
    }
    $id = $settings['merchant_id'] ?? '';
    $name = $settings['merchant_name'] ?? '';
    $merchant = $id !== '' && ($signed['PayToMerchant'] ?? null) === $id
        && ($signed['MerchantName'] ?? $name) === $name
        && ($signed['AmountCurrency'] ?? CPAY_CURRENCY) === CPAY_CURRENCY;
    if (!$merchant) {
        return $refused('cpay_wrong_merchant');
    }
    if (!valid_reference($signed['Details2'] ?? '')) {
        return $refused('cpay_bad_reference');
    }
    if (!array_key_exists('AmountToPay', $signed)) {
        return $refused('cpay_amount_mismatch');
    }
    return ['code' => '', 'signed' => $signed];
}

/**
 * The fields of a verified notice in the order its raw copy keeps them: the return header and the checksum, then
 * the values the header names, in its order, then whatever else was posted. A copy holds the first CPAY_MAX_FIELDS
 * fields, so what was verified comes first: fields posted in front of a notice would otherwise push it out of
 * its own copy.
 * @param array<int|string, string> $signed the signed values of checked_notice()
 */
function notice_copy_fields(array $fields, array $signed): array
{
    return array_intersect_key($fields, ['ReturnCheckSumHeader' => true, 'ReturnCheckSum' => true]) + $signed + $fields;
}

/**
 * Points 4 to 6 of design section 4.5, then the mark on the record: the reference names a record, AmountToPay is
 * the record's amount times 100, compared as text (an AmountToPay the header does not name counts as another
 * amount here too), an OK notice carries a cPayPaymentRef of 1 to 20 digits. Only then is the notice applied to
 * the record, under the store's lock; a fail notice takes its cPayPaymentRef along, when it has one.
 * @param array<int|string, string> $signed the signed values of checked_notice()
 * @return array{code: string, record: array|null, added: bool} code '' | 'cpay_unknown_payment' | 'cpay_amount_mismatch' |
 *         'cpay_ok_without_ref' | 'cpay_record_write'; record is the record as it now stands, and null whenever there
 *         is a code; added says whether the notice joined the record's list of notices (a record takes 20)
 */
function applied_notice(string $dir, string $kind, string $via, array $signed): array
{
    $refused = static fn (string $code): array => ['code' => $code, 'record' => null, 'added' => false];
    $cpayRef = $signed['cPayPaymentRef'] ?? '';
    $record = find_payment($dir, $signed['Details2'] ?? '');
    if ($record === null) {
        return $refused('cpay_unknown_payment');
    }
    if (($signed['AmountToPay'] ?? '') !== amount_to_pay($record['amount'])) {
        return $refused('cpay_amount_mismatch');
    }
    if ($kind === 'ok' && preg_match(NOTICE_CPAY_REF, $cpayRef) !== 1) {
        return $refused('cpay_ok_without_ref');
    }
    try {
        $after = apply_notice_result($dir, $record['ref'], $kind, $via, $cpayRef, date('c'));
    } catch (Throwable $e) {
        return $refused('cpay_record_write');
    }
    return $after === null ? $refused('cpay_unknown_payment') : ['code' => ''] + $after;
}

/** One answer: the status, what to show (neutral | unconfirmed | ok | result) and, for a result, the record. */
function notice_answer(int $status, string $show, ?array $record = null): array
{
    return ['status' => $status, 'show' => $show, 'record' => $record];
}

/** The answer to a notice that marks nothing: 200 either way, a short OK for cPay's own push, the unconfirmed page for everyone else. */
function notice_unconfirmed(string $via): array
{
    return notice_answer(200, $via === 'push' ? 'ok' : 'unconfirmed');
}

/**
 * Steps 4 to 12 of the handler, for a request with fields and a config that loaded: checks, marks the record,
 * keeps the raw copy (after marking, so the copy never decides the answer) and says what to answer.
 * The copy of a verified notice leads with what was verified (notice_copy_fields()) and skips the limits only when
 * the notice joined its record, or when its record could not be written (the copy is then its only trace); any other
 * copy keeps the limits, so one captured notice sent in a loop cannot fill the disk (design 4.7).
 * @param array<string, mixed> $server $_SERVER: the sender's address, the size, the browser's headers
 * @param int $bodyBytes the length of the body the fields were parsed from, when the handler read it (notice_input())
 * @return array{status: int, show: string, record: array|null} see notice_answer()
 */
function notice_outcome(string $kind, array $fields, #[\SensitiveParameter] array $config, array $server, int $bodyBytes = 0): array
{
    $private = $config['private_dir'];
    $via = notice_channel(from_cpay($config, $server), $server);
    if (notice_bytes($server, $fields, $bodyBytes) > CPAY_MAX_BYTES) {
        log_error($private, 'cpay_too_large');
        return notice_answer(200, 'neutral');
    }
    if (!notice_shaped($fields) || cpay_problems($config) !== []) {
        save_cpay_notice($config, $kind, $fields, $via, false);
        return notice_answer(200, 'neutral');
    }
    $checked = checked_notice($fields, cpay_settings($config));
    if ($checked['code'] !== '') {
        log_error($private, $checked['code']);
        save_cpay_notice($config, $kind, $fields, $via, false);
        return notice_unconfirmed($via);
    }
    $applied = applied_notice(payments_dir($private), $kind, $via, $checked['signed']);
    if ($applied['code'] !== '') {
        log_error($private, $applied['code']);
    }
    $exempt = $applied['added'] || $applied['code'] === 'cpay_record_write';
    save_cpay_notice($config, $kind, notice_copy_fields($fields, $checked['signed']), $via, true, $exempt);
    if ($applied['code'] === 'cpay_record_write') {
        return notice_answer(500, 'unconfirmed'); /* cPay tries again after 5 minutes and after 1 hour */
    }
    if ($applied['code'] !== '') {
        return notice_unconfirmed($via);
    }
    if ($via === 'unlisted' && $applied['added']) { /* cPay's sender has probably changed: cpay.push_ips needs this address */
        log_error($private, 'cpay_push_from_unlisted ' . visitor_address((string) ($server['REMOTE_ADDR'] ?? '')));
    }
    return $via === 'push' ? notice_answer(200, 'ok') : notice_answer(200, 'result', $applied['record']);
}

/**
 * The answer to a body that was read and holds no field (notice_input()), for a config that loaded. Past the size
 * limit it is logged as cpay_too_large and kept nowhere. Otherwise it is logged as cpay_unparsed_body (the code,
 * nothing of the body) and kept as a raw copy of its first CPAY_MAX_VALUE characters, unverified and under the limits
 * of everything unverified (design 4.7). Answered with the neutral page, as a request without fields is.
 * @return array{status: int, show: string, record: array|null} see notice_answer()
 */
function notice_unparsed_outcome(string $kind, string $body, #[\SensitiveParameter] array $config, array $server): array
{
    $private = $config['private_dir'];
    if (notice_bytes($server, [], strlen($body)) > CPAY_MAX_BYTES) {
        log_error($private, 'cpay_too_large');
        return notice_answer(200, 'neutral');
    }
    log_error($private, 'cpay_unparsed_body');
    save_cpay_body($config, $kind, $body, notice_channel(from_cpay($config, $server), $server));
    return notice_answer(200, 'neutral');
}

/** Sends an answer of notice_answer(): the text OK, or one of the pages. Does not exit. */
function send_notice_answer(array $answer): void
{
    if ($answer['show'] === 'ok') {
        send_text($answer['status'], 'OK');
        return;
    }
    send_page($answer['status'], match ($answer['show']) {
        'neutral' => neutral_page(),
        'result' => result_page($answer['record'] ?? []),
        default => unconfirmed_page(),
    });
}
