<?php
/* Hotel Oaza — what /naplata/index.php and /naplata/start.php do (design sections 2, 4.1 to 4.3 and 5). A request
   takes the time once and passes it down. staff_index_answer() and staff_start_answer() decide the whole answer
   without sending it, so they can be tested without a web server; every answer then goes out through send_page().
   Nothing typed by staff is ever logged: log lines carry codes and setting names only. */
declare(strict_types=1);

require_once __DIR__ . '/entry.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/charge.php';
require_once __DIR__ . '/payments.php';
require_once __DIR__ . '/pages.php';
require_once __DIR__ . '/staff.php';
require_once __DIR__ . '/staff-pages.php';

const STAFF_WHO = 'staff page';
/* Staff charge about two dozen cards in half an hour; 80 starts an hour leaves room and still stops a runaway. */
const STAFF_MAX_PER_HOUR = 80;
const STAFF_RATE_FILE = 'cpay-staff-ratelimit.json';
const STAFF_LIST_LIMIT = 50;
/* Raw copies of cPay's notices are kept for 90 days (design 4.8). */
const STAFF_INBOX_DAYS = 90;
/* What was typed is shown again; more than the form's own fields can hold is cut. */
const STAFF_TYPED_BYTES = 64;
const STAFF_REFERENCE_TRIES = 20;

/** GET /naplata/: the form and the list of charges. */
function staff_index(): void
{
    staff_run(['GET', 'HEAD'], static fn (#[\SensitiveParameter] array $config, int $now): array
        => staff_index_answer($config, $_SERVER, PHP_SAPI, $now));
}

/** POST /naplata/start.php: checks the form, writes a waiting record and answers with the page that posts itself to cPay. */
function staff_start(): void
{
    staff_run(['POST'], static fn (#[\SensitiveParameter] array $config, int $now): array
        => staff_start_answer($config, $_SERVER, $_POST, PHP_SAPI, $now));
}

/**
 * What both entry points do around their answer: catch what nobody caught (500), refuse another method (405 with
 * Allow), load the config (500 and "not set up" when it cannot be loaded), and send what $answer decides.
 * A failure with a code of this site (the record or the secret cannot be written, the page's lock is busy) is logged
 * by that code; anything else reaches the handler for uncaught errors, which logs its class only. Both answer 500
 * with the page that says nothing was started: the setup is not to blame.
 * @param string[] $methods the request methods this address takes
 * @param callable(array, int): array $answer the config and the time of the request => staff_answer()
 */
function staff_run(array $methods, callable $answer): void
{
    $now = time();
    guard_uncaught(STAFF_WHO, static function (): void {
        staff_send(staff_answer(500, staff_message_page('site_failed')));
    });
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', $methods, true)) {
        staff_send(staff_answer(405, staff_refused_page('staff_method'), ['Allow: ' . implode(', ', $methods)]));
        return;
    }
    $config = try_load_config(STAFF_WHO);
    if ($config === null) {
        staff_send(staff_answer(500, staff_message_page('not_set_up')));
        return;
    }
    try {
        $decided = $answer($config, $now);
    } catch (RuntimeException $e) {
        log_error($config['private_dir'], staff_failure_code($e));
        $decided = staff_answer(500, staff_message_page('site_failed'));
    }
    staff_send($decided);
}

/** 'staff_failed <code>' for an exception whose message is one of this site's codes, else 'staff_failed <class>': a message could quote a value. */
function staff_failure_code(Throwable $e): string
{
    return 'staff_failed ' . (preg_match('/\A[a-z_]{1,40}\z/', $e->getMessage()) === 1 ? $e->getMessage() : get_class($e));
}

/**
 * One answer, not yet sent.
 * @param string[] $headers header lines beyond those every page carries (Location, Allow)
 * @return array{status: int, headers: string[], html: string}
 */
function staff_answer(int $status, string $html, array $headers = []): array
{
    return ['status' => $status, 'headers' => $headers, 'html' => $html];
}

/** Sends an answer: its own header lines, then status, the headers of every page (no-store, noindex, no framing) and the page. */
function staff_send(array $answer): void
{
    foreach ($answer['headers'] as $line) {
        header($line);
    }
    send_page($answer['status'], $answer['html']);
}

/** The site's own host: the one of cpay.ok_url; '' while that setting is missing or wrong. */
function staff_host(#[\SensitiveParameter] array $config): string
{
    return cpay_return_host(cpay_section($config)['ok_url'] ?? null, 'ok.php');
}

/** A refusal: its code goes to the log and onto the page. */
function staff_refused(#[\SensitiveParameter] array $config, int $status, string $code): array
{
    log_error($config['private_dir'], $code);
    return staff_answer($status, staff_refused_page($code));
}

/** The start of the day $now falls on in Skopje, as a unix time. */
function staff_day_start(int $now): int
{
    return (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone(STAFF_ZONE))->setTime(0, 0)->getTimestamp();
}

/** The records started (payment_started(), the time the list shows) on the day $now falls on in Skopje. A form is
    good for two token hours, so only references issued from two hours before midnight on are read. */
function staff_started_today(string $dir, int $now): array
{
    $dayStart = staff_day_start($now);
    return array_values(array_filter(
        payments_since($dir, $dayStart - 2 * STAFF_TOKEN_SECONDS),
        static fn (array $record): bool => payment_started($record) >= $dayStart,
    ));
}

/** True when a record file carries that reference, also one that cannot be read. */
function staff_reference_taken(string $dir, string $ref): bool
{
    foreach (payment_file_names($dir) as $name) {
        if (str_starts_with($name, $ref . '-')) {
            return true;
        }
    }
    return false;
}

/** A reference of this moment that no record uses yet. Throws RuntimeException('staff_reference') when none is found. */
function staff_fresh_reference(string $dir, int $now): string
{
    for ($try = 0; $try < STAFF_REFERENCE_TRIES; $try++) {
        $ref = new_reference($now);
        if (!staff_reference_taken($dir, $ref)) {
            return $ref;
        }
    }
    throw new RuntimeException('staff_reference');
}

/**
 * Deletes what is past its retention, at most once a day (design 4.8). A failure is logged by code and otherwise
 * ignored: the page must open. A number of days that the settings check refuses deletes nothing.
 */
function staff_prune(#[\SensitiveParameter] array $config, int $now): void
{
    if (in_array('cpay.retention_days', cpay_problems($config), true)) {
        return;
    }
    $days = cpay_section($config)['retention_days'] ?? null;
    try {
        prune_old($config['private_dir'], cpay_setting_blank($days) ? CPAY_DEFAULT_RETENTION_DAYS : $days, STAFF_INBOX_DAYS, $now);
    } catch (Throwable $e) {
        log_error($config['private_dir'], 'staff_prune_failed');
    }
}

/**
 * The staff page with a form that carries a fresh reference and its token, or, while the cPay settings are
 * incomplete, the "not set up" message (their names go to the log, never a value); the list either way.
 * @param array<string, string> $typed amount and reservation as typed, shown again
 * @param string[] $errors keys of STAFF_MESSAGES
 */
function staff_form_answer(int $status, #[\SensitiveParameter] array $config, string $user, array $typed, array $errors, int $now): array
{
    $private = $config['private_dir'];
    $dir = payments_dir($private);
    $problems = cpay_problems($config);
    $ready = $problems === [];
    if (!$ready) {
        log_error($private, 'cpay_incomplete: ' . implode(', ', $problems));
    }
    $ref = $ready ? staff_fresh_reference($dir, $now) : '';
    return staff_answer($status, staff_form_page([
        'user' => $user,
        'ready' => $ready,
        'ref' => $ref,
        'token' => $ready ? staff_token(staff_secret($private), $user, $ref, intdiv($now, STAFF_TOKEN_SECONDS)) : '',
        'amount' => substr($typed['amount'] ?? '', 0, STAFF_TYPED_BYTES),
        'reservation' => substr($typed['reservation'] ?? '', 0, STAFF_TYPED_BYTES),
        'errors' => $errors,
        'max' => $ready ? cpay_settings($config)['max_amount'] : 0,
        'rows' => latest_payments($dir, STAFF_LIST_LIMIT),
        'today' => paid_total(staff_started_today($dir, $now)),
        'now' => $now,
    ]));
}

/**
 * The answer of GET /naplata/ once the config is loaded. Another host: 302 to the site's own; no user or plain
 * HTTP: 403 with the code. Then the pruning of the day and the page.
 * @param array<string, mixed> $server $_SERVER
 */
function staff_index_answer(#[\SensitiveParameter] array $config, array $server, string $sapi, int $now): array
{
    $host = staff_host($config);
    $problem = staff_problem($server, $sapi, $host);
    if ($problem === 'staff_wrong_host') {
        $address = 'https://' . $host . STAFF_PATH;
        return staff_answer(302, staff_moved_page($address), ['Location: ' . $address]);
    }
    if ($problem !== '') {
        return staff_refused($config, 403, $problem);
    }
    staff_prune($config, $now);
    return staff_form_answer(200, $config, staff_user($server, $sapi), [], [], $now);
}

/** The posted fields the page uses, each as text ('' for one that is missing or is not text). Nothing is trimmed. */
function staff_typed(array $post): array
{
    $text = static fn (string $name): string => is_string($post[$name] ?? null) ? $post[$name] : '';
    return [
        'amount' => $text('amount'),
        'reservation' => $text('reservation'),
        'ref' => $text('ref'),
        'token' => $text('token'),
        'seen' => $text('seen'),
        'confirm' => $text('confirm'),
    ];
}

/**
 * True when staff must be asked (again) before this charge (design 4.3): a record stands in the way and the form does
 * not confirm it. A confirmation counts only for the records its own page showed (their codes in "seen", see
 * staff_seen()): a charge that another form started since is shown and asked about first.
 * @param array[] $earlier the records of the reservation that stand in the way
 * @param array{confirm: string, seen: string} $typed
 */
function staff_must_ask(array $earlier, array $typed): bool
{
    if ($earlier === []) {
        return false;
    }
    if ($typed['confirm'] !== 'yes') {
        return true;
    }
    $seen = explode(',', $typed['seen']);
    return array_filter($earlier, static fn (array $record): bool => !in_array($record['ref'], $seen, true)) !== [];
}

/** What is wrong with the typed amount and reservation number, as keys of STAFF_MESSAGES; [] when both are good. */
function staff_input_errors(array $typed, int $max): array
{
    return array_values(array_filter(
        [amount_problem($typed['amount'], $max), valid_reservation($typed['reservation']) ? '' : 'reservation'],
        static fn (string $code): bool => $code !== '',
    ));
}

/** The salted hash that stands for one user in the rate-limit file; the name itself is never stored. */
function staff_rate_key(#[\SensitiveParameter] array $config, string $user): string
{
    return hash('sha256', $config['rate']['salt'] . '|staff|' . $user);
}

/** True when this user has started 80 payments within the hour; otherwise counts this one. A counter that cannot be
    read or written is logged by code and stops nobody: it guards the disk, not the cards. */
function staff_rate_limited(#[\SensitiveParameter] array $config, string $user, int $now): bool
{
    $mask = umask(0077);
    try {
        return rate_limited($config['private_dir'] . '/' . STAFF_RATE_FILE, staff_rate_key($config, $user), STAFF_MAX_PER_HOUR, 3600, $now);
    } catch (Throwable $e) {
        log_error($config['private_dir'], 'staff_rate_unavailable');
        return false;
    } finally {
        umask($mask);
    }
}

/**
 * Starts the payment of a form whose token, amount and reservation number are good, holding the page's own lock
 * (staff_locked()) from the look at the reservation's earlier charges to the writing of the record. Without it two
 * forms for one reservation, sent at the same moment, would both find nothing in the way and both go on to cPay
 * (design 4.3); with it the second one finds the first one's record and is asked.
 * @param array{amount: string, reservation: string, ref: string, token: string, seen: string, confirm: string} $typed
 * @param int $lockTries how often the lock is asked for (the tests give up at once)
 * @throws RuntimeException 'staff_lock' when the lock cannot be had, 'payments_write' when the record cannot be written
 */
function staff_charge_answer(#[\SensitiveParameter] array $config, string $user, array $typed, int $now, int $lockTries = STAFF_LOCK_TRIES): array
{
    return staff_locked(
        $config['private_dir'],
        static fn (): array => staff_charge_decided($config, $user, $typed, $now),
        $lockTries,
    );
}

/**
 * What staff_charge_answer() decides once it holds the lock: refuses a reference that was used (409), asks before
 * a reservation is charged again, also when a confirmed form had not seen every charge that stands in the way now
 * (200, the confirm page, staff_must_ask()), keeps to the hourly cap (429), then writes the waiting record and
 * answers with the page that posts itself to cPay. The request is signed before the record is written, so a
 * setting that cannot be signed leaves no record behind. Never call it without the lock.
 * @param array{amount: string, reservation: string, ref: string, token: string, seen: string, confirm: string} $typed
 * @throws RuntimeException 'payments_write' when the record cannot be written
 */
function staff_charge_decided(#[\SensitiveParameter] array $config, string $user, array $typed, int $now): array
{
    $settings = cpay_settings($config);
    $dir = payments_dir($config['private_dir']);
    $amount = (int) $typed['amount'];
    if (staff_reference_taken($dir, $typed['ref'])) {
        return staff_answer(409, staff_message_page('already_started'));
    }
    $earlier = array_values(array_filter(
        payments_for_reservation($dir, $typed['reservation']),
        static fn (array $record): bool => blocks_repeat($record, $now),
    ));
    if (staff_must_ask($earlier, $typed)) {
        return staff_answer(200, staff_confirm_page($user, $typed, $earlier, $now));
    }
    if (staff_rate_limited($config, $user, $now)) {
        log_error($config['private_dir'], 'staff_rate_limited');
        return staff_answer(429, staff_message_page('too_many'));
    }
    $request = charge_request($settings, $amount, $typed['reservation'], $typed['ref']);
    $record = new_payment($typed['ref'], $user, $amount, $typed['reservation'], details1_for($typed['reservation']), $settings['payment_url'], $settings['hash'], $now);
    if (!create_payment($dir, $record)) {
        return staff_answer(409, staff_message_page('already_started'));
    }
    return staff_answer(200, staff_redirect_page($request, $amount));
}

/**
 * The answer of POST /naplata/start.php once the config is loaded, in this order: no user, plain HTTP or another
 * host (403); not from this site's own page (403); not a form (400); settings incomplete (503); a reference or
 * token that is not this user's of this hour or the one before (409, the form again with what was typed); a bad
 * amount or reservation number (422, the form again); then staff_charge_answer().
 * @param array<string, mixed> $server $_SERVER
 * @param array<string, mixed> $post $_POST
 */
function staff_start_answer(#[\SensitiveParameter] array $config, array $server, array $post, string $sapi, int $now): array
{
    $problem = staff_problem($server, $sapi, staff_host($config));
    if ($problem !== '') {
        return staff_refused($config, 403, $problem);
    }
    if (!staff_same_origin($server)) {
        return staff_refused($config, 403, 'staff_other_origin');
    }
    if (!is_form_post($server)) {
        return staff_refused($config, 400, 'staff_not_a_form');
    }
    $problems = cpay_problems($config);
    if ($problems !== []) {
        log_error($config['private_dir'], 'cpay_incomplete: ' . implode(', ', $problems));
        return staff_answer(503, staff_message_page('not_set_up'));
    }
    $user = staff_user($server, $sapi);
    $typed = staff_typed($post);
    $tokenGood = valid_reference($typed['ref'])
        && staff_token_ok(staff_secret($config['private_dir']), $user, $typed['ref'], $typed['token'], $now);
    if (!$tokenGood) {
        return staff_form_answer(409, $config, $user, $typed, ['stale'], $now);
    }
    $errors = staff_input_errors($typed, cpay_settings($config)['max_amount']);
    if ($errors !== []) {
        return staff_form_answer(422, $config, $user, $typed, $errors, $now);
    }
    return staff_charge_answer($config, $user, $typed, $now);
}
