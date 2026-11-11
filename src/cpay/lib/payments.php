<?php
/* Hotel Oaza — the record store for cPay charges: one JSON file per payment in <private>/cpay-payments, named
   <reference>-<reservation>.json. Every write happens under one lock file (.lock), goes to a temporary file in
   the same folder and is renamed over the record, with umask 0077. These files never hold card data.
   Deleting what is past its retention (prune_old) lives in prune.php; the two files require each other on purpose. */
declare(strict_types=1);

require_once __DIR__ . '/prune.php';

const CPAY_NO_ANSWER_AFTER = 2700; /* 45 minutes */
const CPAY_MAX_NOTICES = 20;
const PAYMENT_STATUSES = ['waiting', 'paid', 'failed'];
const PAYMENT_NOTICE_KINDS = ['ok', 'fail'];
const PAYMENT_NOTICE_CHANNELS = ['push', 'browser', 'unlisted'];
const PAYMENT_FILE_PATTERN = '/\A[0-9A-Z]{10}-[0-9]{6,20}\.json\z/';

function payments_dir(string $privateDir): string
{
    return $privateDir . '/cpay-payments';
}

/** $number in base 36, upper case, padded with zeros to $width characters. */
function payment_base36(int $number, int $width): string
{
    return str_pad(strtoupper(base_convert((string) $number, 10, 36)), $width, '0', STR_PAD_LEFT);
}

/** Six base-36 characters of $now, then four random ones, upper case; sorts by time.
    Throws InvalidArgumentException for a time that six characters cannot hold (before 1970, after December 2038). */
function new_reference(int $now): string
{
    if ($now < 0 || $now >= 36 ** 6) {
        throw new InvalidArgumentException('payment_time');
    }
    return payment_base36($now, 6) . payment_base36(random_int(0, 36 ** 4 - 1), 4);
}

function valid_reference(string $ref): bool
{
    return preg_match('/\A[0-9A-Z]{10}\z/', $ref) === 1;
}

/** The time in the first six characters. Throws InvalidArgumentException for something that is not a reference. */
function reference_time(string $ref): int
{
    if (!valid_reference($ref)) {
        throw new InvalidArgumentException('payment_reference');
    }
    return intval(substr($ref, 0, 6), 36);
}

/** 6 to 20 digits, the rule of charge.php's valid_reservation(): checked here too because the number becomes part of a file name. */
function payment_reservation_ok(string $reservation): bool
{
    return preg_match('/\A[0-9]{6,20}\z/', $reservation) === 1;
}

/** <reference>-<reservation>.json. Throws InvalidArgumentException instead of building a name out of anything else. */
function payment_file_name(string $ref, string $reservation): string
{
    if (!valid_reference($ref) || !payment_reservation_ok($reservation)) {
        throw new InvalidArgumentException('payment_record');
    }
    return $ref . '-' . $reservation . '.json';
}

/** The complete record of a payment about to be sent: status waiting, no cPay reference, no notices. */
function new_payment(string $ref, string $user, int $amount, string $reservation, string $details1, string $paymentUrl, string $hash, int $now): array
{
    $created = date('c', $now);
    return [
        'ref' => $ref,
        'created' => $created,
        'updated' => $created,
        'user' => $user,
        'amount' => $amount,
        'reservation' => $reservation,
        'details1' => $details1,
        'payment_url' => $paymentUrl,
        'hash' => $hash,
        'status' => 'waiting',
        'cpay_ref' => '',
        'extra_refs' => [],
        'notices' => [],
    ];
}

/** The record, with the parts a file written by hand may leave out filled in; null for anything that is not a payment record. */
function usable_payment(mixed $data): ?array
{
    if (!is_array($data)) {
        return null;
    }
    $optional = ['user' => '', 'details1' => '', 'payment_url' => '', 'hash' => '', 'cpay_ref' => '', 'extra_refs' => [], 'notices' => []];
    $record = $data + ['updated' => $data['created'] ?? null] + $optional;
    foreach (['ref', 'created', 'updated', 'user', 'reservation', 'details1', 'payment_url', 'hash', 'status', 'cpay_ref'] as $key) {
        if (!is_string($record[$key] ?? null)) {
            return null;
        }
    }
    $usable = valid_reference($record['ref'])
        && payment_reservation_ok($record['reservation'])
        && is_int($record['amount'] ?? null) && $record['amount'] >= 1
        && in_array($record['status'], PAYMENT_STATUSES, true)
        && is_array($record['extra_refs']) && is_array($record['notices']);
    return $usable ? $record : null;
}

/** Runs one file operation without PHP's warning, whatever error handler is installed; the caller checks the result. */
function payments_quietly(callable $operation): mixed
{
    set_error_handler(static fn (): bool => true);
    try {
        return $operation();
    } finally {
        restore_error_handler();
    }
}

/** The names of the record files, newest first: the reference leads the name and sorts by time. */
function payment_file_names(string $dir): array
{
    $found = is_dir($dir) ? payments_quietly(static fn () => scandir($dir)) : false;
    $names = array_values(array_filter(
        is_array($found) ? $found : [],
        static fn (string $name): bool => preg_match(PAYMENT_FILE_PATTERN, $name) === 1,
    ));
    rsort($names, SORT_STRING);
    return $names;
}

/** The record in the file of that name; null when it cannot be read, does not parse or is not the record its name says. */
function read_payment(string $dir, string $name): ?array
{
    $path = $dir . '/' . $name;
    $json = is_file($path) ? payments_quietly(static fn () => file_get_contents($path)) : false;
    $record = is_string($json) ? usable_payment(json_decode($json, true)) : null;
    return $record !== null && payment_file_name($record['ref'], $record['reservation']) === $name ? $record : null;
}

/** The readable records whose reference and reservation (read off the file name) $wanted accepts, newest first, at most $limit. */
function payments_where(string $dir, callable $wanted, int $limit = PHP_INT_MAX): array
{
    $records = [];
    foreach (payment_file_names($dir) as $name) {
        if (count($records) >= $limit) {
            break;
        }
        $record = $wanted(substr($name, 0, 10), substr($name, 11, -5)) ? read_payment($dir, $name) : null;
        if ($record !== null) {
            $records[] = $record;
        }
    }
    return $records;
}

/** The record with that reference; null when there is none, when its file does not parse, and for a bad reference. */
function find_payment(string $dir, string $ref): ?array
{
    if (!valid_reference($ref)) {
        return null;
    }
    return payments_where($dir, static fn (string $found): bool => $found === $ref, 1)[0] ?? null;
}

/** The records of one reservation number, newest first; [] for a number of the wrong shape. */
function payments_for_reservation(string $dir, string $reservation): array
{
    if (!payment_reservation_ok($reservation)) {
        return [];
    }
    return payments_where($dir, static fn (string $ref, string $found): bool => $found === $reservation);
}

/** The newest records, newest first. */
function latest_payments(string $dir, int $limit = 50): array
{
    return payments_where($dir, static fn (): bool => true, max(0, $limit));
}

/** The records whose reference was issued at $since or later, newest first. */
function payments_since(string $dir, int $since): array
{
    return payments_where($dir, static fn (string $ref): bool => reference_time($ref) >= $since);
}

/** How many of the records are paid and what they add up to, in denars: ['count' => int, 'sum' => int]. */
function paid_total(array $records): array
{
    $amounts = array_map(
        static fn (array $record): int => is_int($record['amount'] ?? null) ? $record['amount'] : 0,
        array_filter($records, static fn (array $record): bool => ($record['status'] ?? '') === 'paid'),
    );
    return ['count' => count($amounts), 'sum' => (int) array_sum($amounts)];
}

/** Runs $work holding the folder's lock, with umask 0077, and returns what it returns. Makes the folder (0700) when it
    is missing, but never its parent. Throws RuntimeException('payments_write') when the folder or the lock cannot be had.
    The lock waits without a time limit and cannot be taken twice: never call create_payment(), apply_notice() or
    prune_old() from inside $work. */
function payments_locked(string $dir, callable $work): mixed
{
    $mask = umask(0077);
    try {
        $ready = is_dir($dir) || payments_quietly(static fn (): bool => mkdir($dir, 0700)) || is_dir($dir);
        $lock = $ready ? payments_quietly(static fn () => fopen($dir . '/.lock', 'c')) : false;
        if (!is_resource($lock)) {
            throw new RuntimeException('payments_write');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('payments_write');
            }
            return $work();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    } finally {
        umask($mask);
    }
}

/** Puts the record in its file: the new content goes to a temporary file in the same folder, which is then renamed
    over the record. Call it holding the lock. Throws RuntimeException('payments_write'). */
function write_payment(string $dir, array $record): void
{
    $path = $dir . '/' . payment_file_name($record['ref'], $record['reservation']);
    $temporary = $path . '.tmp';
    $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    $written = is_string($json) && payments_quietly(static fn () => file_put_contents($temporary, $json)) === strlen($json);
    if ($written && payments_quietly(static fn (): bool => chmod($temporary, 0600) && rename($temporary, $path))) {
        return;
    }
    payments_quietly(static fn (): bool => is_file($temporary) && unlink($temporary));
    throw new RuntimeException('payments_write');
}

/** Writes the record unless one with that reference exists (checked under the lock). False when it exists.
    Throws InvalidArgumentException for a reference or reservation of the wrong shape (or a record that is otherwise
    not one), before any file name is built; RuntimeException('payments_write') when it cannot write. */
function create_payment(string $dir, array $record): bool
{
    $complete = usable_payment($record);
    if ($complete === null) {
        throw new InvalidArgumentException('payment_record');
    }
    return payments_locked($dir, static function () use ($dir, $complete): bool {
        $taken = array_filter(
            payment_file_names($dir),
            static fn (string $name): bool => str_starts_with($name, $complete['ref'] . '-'),
        );
        if ($taken !== []) {
            return false;
        }
        write_payment($dir, $complete);
        return true;
    });
}

/** What an OK notice changes: with a cPay reference, waiting or failed becomes paid; for a paid record another
    reference is kept once as a second charge, and the same one changes nothing. Without a reference, nothing. */
function payment_ok_changes(array $record, string $cpayRef): array
{
    if ($cpayRef === '') {
        return [];
    }
    if ($record['status'] !== 'paid' || $record['cpay_ref'] === '') {
        return ['status' => 'paid', 'cpay_ref' => $cpayRef];
    }
    $known = $cpayRef === $record['cpay_ref'] || in_array($cpayRef, $record['extra_refs'], true);
    return $known ? [] : ['extra_refs' => [...array_values($record['extra_refs']), $cpayRef]];
}

/** What a fail notice changes: waiting becomes failed (paid stays paid), and its cPay reference is kept when the record has none. */
function payment_fail_changes(array $record, string $cpayRef): array
{
    return ($record['status'] === 'waiting' ? ['status' => 'failed'] : [])
        + ($record['cpay_ref'] === '' && $cpayRef !== '' ? ['cpay_ref' => $cpayRef] : []);
}

/** The record after one notice (design section 4.4). The notice joins the list while the list has room; the status
    rules apply either way. A cPay reference that is not 1 to 20 digits counts as none (design section 4.5, point 6). */
function payment_after_notice(array $record, string $kind, string $via, string $cpayRef, string $at): array
{
    $digits = preg_match('/\A[0-9]{1,20}\z/', $cpayRef) === 1 ? $cpayRef : '';
    $changes = $kind === 'ok' ? payment_ok_changes($record, $digits) : payment_fail_changes($record, $digits);
    $notices = array_values($record['notices']);
    $room = count($notices) < CPAY_MAX_NOTICES;
    return array_merge($record, $changes, ['notices' => $room ? [...$notices, ['at' => $at, 'kind' => $kind, 'via' => $via]] : $notices]);
}

/** Applies one verified notice under the lock: ['record' => the record as it now stands, 'added' => whether the notice
    joined its list (not once the list holds CPAY_MAX_NOTICES; the status rules apply either way)]; null when there is
    no such record. $kind 'ok' | 'fail'; $via 'push' | 'browser' | 'unlisted'; $cpayRef '' when cPay sent none; $at
    ISO 8601, as date('c') writes it. A notice that changes nothing (the list is full and the status stands) rewrites
    nothing. Throws InvalidArgumentException for another kind or channel or a time of another shape (blocks_repeat()
    counts from it), RuntimeException('payments_write') when it cannot write. */
function apply_notice_result(string $dir, string $ref, string $kind, string $via, string $cpayRef, string $at): ?array
{
    if (!in_array($kind, PAYMENT_NOTICE_KINDS, true) || !in_array($via, PAYMENT_NOTICE_CHANNELS, true) || payment_time($at) === null) {
        throw new InvalidArgumentException('payment_notice');
    }
    if (!valid_reference($ref) || !is_dir($dir)) {
        return null;
    }
    return payments_locked($dir, static function () use ($dir, $ref, $kind, $via, $cpayRef, $at): ?array {
        $record = find_payment($dir, $ref);
        if ($record === null) {
            return null;
        }
        $after = payment_after_notice($record, $kind, $via, $cpayRef, $at);
        if ($after === $record) {
            return ['record' => $record, 'added' => false];
        }
        $updated = array_merge($after, ['updated' => $at]);
        write_payment($dir, $updated);
        return ['record' => $updated, 'added' => count($after['notices']) > count($record['notices'])];
    });
}

/** apply_notice_result() without the word on the notice list: the record as it now stands, or null. */
function apply_notice(string $dir, string $ref, string $kind, string $via, string $cpayRef, string $at): ?array
{
    return apply_notice_result($dir, $ref, $kind, $via, $cpayRef, $at)['record'] ?? null;
}

/** The unix time of a text such as 2026-11-11T10:15:00+01:00 (what date('c') writes); null when it is not one. */
function payment_time(mixed $text): ?int
{
    $shaped = is_string($text) && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})\z/', $text) === 1;
    $time = $shaped ? strtotime($text) : false;
    return $time === false ? null : $time;
}

/** When the payment was started: its "created", or the time in its reference when that does not read as a time. */
function payment_started(array $record): int
{
    return payment_time($record['created'] ?? null)
        ?? reference_time(is_string($record['ref'] ?? null) ? $record['ref'] : '');
}

/** The notices of a record that have the shape of one. */
function payment_notices(array $record): array
{
    return array_values(array_filter(is_array($record['notices'] ?? null) ? $record['notices'] : [], 'is_array'));
}

/** When cPay last reported the payment as failed: the latest time among its fail notices, or its start when no such
    notice was kept. A repeated fail notice can therefore only prolong the confirmation, which is the safe side. */
function payment_failed_at(array $record): int
{
    $times = array_filter(array_map(
        static fn (array $notice): ?int => ($notice['kind'] ?? '') === 'fail' ? payment_time($notice['at'] ?? null) : null,
        payment_notices($record),
    ), 'is_int');
    return $times === [] ? payment_started($record) : max($times);
}

/** paid | failed | waiting | no_answer: waiting is shown as no_answer from 45 minutes after the start. Time never changes what is stored. */
function display_status(array $record, int $now): string
{
    $status = $record['status'] ?? '';
    if ($status === 'paid' || $status === 'failed') {
        return $status;
    }
    return $now - payment_started($record) < CPAY_NO_ANSWER_AFTER ? 'waiting' : 'no_answer';
}

/** True when staff must confirm before charging this reservation again: paid, waiting (any age), or failed with a
    cPay reference less than 45 minutes ago (counted from the latest fail notice). */
function blocks_repeat(array $record, int $now): bool
{
    if (($record['status'] ?? '') !== 'failed') {
        return true;
    }
    return ($record['cpay_ref'] ?? '') !== '' && $now - payment_failed_at($record) < CPAY_NO_ANSWER_AFTER;
}

/** True when a second OK notice carried another cPay reference: the card may have been charged twice. */
function has_two_charges(array $record): bool
{
    return ($record['extra_refs'] ?? []) !== [];
}
