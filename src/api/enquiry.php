<?php
/* Hotel Oaza — POST /api/enquiry.php: checks an enquiry and mails it to reception.
   Answers {"success": bool, "data": null, "error": null | {"code": "invalid"|"rate_limited"|"send_failed"|"bad_request", "fields"?: [...]}} */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

/* Anything unexpected ends as a plain send_failed: only the class name is logged, as a message could quote the config. */
set_exception_handler(static function (Throwable $e): void {
    error_log('Hotel Oaza enquiry: uncaught ' . get_class($e));
    if (!headers_sent()) {
        respond_error(500, 'send_failed');
    }
});

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond_error(405, 'bad_request');
}
if (!same_origin($_SERVER)) {
    respond_error(403, 'bad_request');
}
if (!is_form_post($_SERVER)) {
    respond_error(400, 'bad_request');
}

try {
    $config = load_config(config_path());
} catch (Throwable $e) {
    /* Only a RuntimeException's message is safe to log; a ParseError in config.php quotes the code around the error. */
    error_log('Hotel Oaza enquiry: ' . ($e instanceof RuntimeException ? $e->getMessage() : get_class($e) . ' on line ' . $e->getLine()));
    respond_error(500, 'send_failed');
}

/* The spam trap: people never fill the hidden "website" field. Answer as if sent.
   The hit is counted in the log (timestamp and code only), so nothing about the sender is kept. */
$trap = $_POST['website'] ?? '';
if (!is_string($trap) || trim($trap) !== '') {
    log_error($config['private_dir'], 'spam_trap');
    respond(200);
}

$result = validate_enquiry($_POST, today_in_skopje());
if ($result['errors'] !== []) {
    respond_error(422, 'invalid', array_keys($result['errors']));
}

$visitor = visitor_key($config['rate']['salt'], $_SERVER);
try {
    $limited = rate_limited(
        $config['private_dir'] . '/ratelimit.json',
        $visitor,
        (int) ($config['rate']['limit'] ?? 5),
        (int) ($config['rate']['window'] ?? 3600),
        time(),
    );
} catch (Throwable $e) {
    log_error($config['private_dir'], 'rate_unavailable');
    $limited = false; /* a broken counter must not turn guests away */
}
if ($limited) {
    respond_error(429, 'rate_limited');
}

try {
    send_enquiry($config['mail'], build_message($result['data']));
} catch (Throwable $e) {
    log_error($config['private_dir'], 'send_failed');
    respond_error(500, 'send_failed');
}

respond(200);
