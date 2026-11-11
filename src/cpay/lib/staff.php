<?php
/* Hotel Oaza — who is using the staff page and whether the request may go on (design sections 4.2 and 5): the
   signed-in user, the checks on the request, the page's own secret, the token that ties one form to one user,
   one reference and one hour, and the lock that lets one payment be started at a time. Apache asks for the
   password (src/naplata/.htaccess); this file only reads who was let in, and refuses when nobody was.
   PHP 8.0+, default extensions only. */
declare(strict_types=1);

require_once __DIR__ . '/payments.php';
require_once dirname(__DIR__, 2) . '/api/lib/request.php';

/* PHP's built-in server: the tests and a developer's own PC. Only there PHP_AUTH_USER counts, and plain HTTP on any host. */
const STAFF_BUILT_IN_SERVER = 'cli-server';
/* A user name as cPanel's Directory Privacy makes one, and nothing that could break a log line or the token's text. */
const STAFF_USER_PATTERN = '/\A[A-Za-z0-9._@-]{1,64}\z/';
const STAFF_SECRET_FILE = 'cpay-staff-secret';
/* 32 random bytes as hexadecimal; a token is an HMAC-SHA256 as hexadecimal, so it has the same shape. */
const STAFF_SECRET_PATTERN = '/\A[0-9a-f]{64}\z/';
const STAFF_TOKEN_PATTERN = '/\A[0-9a-f]{64}\z/';
const STAFF_TOKEN_SECONDS = 3600;
const STAFF_LOCK_FILE = 'cpay-staff-start.lock';
/* A start holds the lock for a few file reads and one write. Another start asks for it this often, this far apart
   (500 times 20 milliseconds: about ten seconds), and then gives up. */
const STAFF_LOCK_TRIES = 500;
const STAFF_LOCK_PAUSE_MICROSECONDS = 20000;

/**
 * The user Apache let in: REMOTE_USER, else REDIRECT_REMOTE_USER; PHP_AUTH_USER only when $sapi is 'cli-server'
 * (anywhere else that header is whatever the visitor sent). '' when there is none, and when the first name found
 * is not 1 to 64 of A-Z a-z 0-9 . _ @ - (a name of another shape is not replaced by the next one).
 * @param array<string, mixed> $server $_SERVER
 */
function staff_user(array $server, string $sapi): string
{
    $names = $sapi === STAFF_BUILT_IN_SERVER
        ? ['REMOTE_USER', 'REDIRECT_REMOTE_USER', 'PHP_AUTH_USER']
        : ['REMOTE_USER', 'REDIRECT_REMOTE_USER'];
    foreach ($names as $name) {
        $user = $server[$name] ?? '';
        if ($user !== '') {
            return is_string($user) && preg_match(STAFF_USER_PATTERN, $user) === 1 ? $user : '';
        }
    }
    return '';
}

/** True when the server says the request came over HTTPS. A forwarded header never counts: the visitor can send one. */
function staff_is_https(array $server): bool
{
    $https = $server['HTTPS'] ?? '';
    return is_string($https) && $https !== '' && strtolower($https) !== 'off';
}

/** True when the form came from this site's own page: same_origin() (host and port), and Origin (or, without it,
    Referer) has the request's own scheme. An http page is not the same origin as the https one (design 5). */
function staff_same_origin(array $server): bool
{
    $source = (string) ($server['HTTP_ORIGIN'] ?? $server['HTTP_REFERER'] ?? '');
    $scheme = strtolower((string) parse_url($source, PHP_URL_SCHEME));
    return same_origin($server) && $scheme === (staff_is_https($server) ? 'https' : 'http');
}

/** The host the request was sent to, in lower case, without the port of HTTPS. */
function staff_request_host(array $server): string
{
    $host = $server['HTTP_HOST'] ?? '';
    return is_string($host) ? (string) preg_replace('/:443\z/', '', strtolower($host)) : '';
}

/**
 * Why this request may not use the staff page: '' | 'staff_no_user' | 'staff_not_https' | 'staff_wrong_host'.
 * The HTTPS and host checks are skipped under 'cli-server'; the host check also when $host is '' (the config
 * names no usable address of the site yet).
 * @param array<string, mixed> $server $_SERVER
 * @param string $host the site's own host: the one of cpay.ok_url
 */
function staff_problem(array $server, string $sapi, string $host): string
{
    if (staff_user($server, $sapi) === '') {
        return 'staff_no_user';
    }
    if ($sapi === STAFF_BUILT_IN_SERVER) {
        return '';
    }
    if (!staff_is_https($server)) {
        return 'staff_not_https';
    }
    return $host === '' || staff_request_host($server) === strtolower($host) ? '' : 'staff_wrong_host';
}

/**
 * The secret in an open file, read holding the file's lock; when the file holds anything else (it is new, or was
 * damaged) a new secret is written in its place. The lock makes two first requests agree on one secret.
 * @param resource $file opened for reading and writing
 * @throws RuntimeException 'staff_secret'
 */
function staff_secret_from($file): string
{
    $stored = flock($file, LOCK_EX) ? stream_get_contents($file) : false;
    if (is_string($stored) && preg_match(STAFF_SECRET_PATTERN, $stored) === 1) {
        return $stored;
    }
    $secret = bin2hex(random_bytes(32));
    $written = is_string($stored) && ftruncate($file, 0) && rewind($file)
        && fwrite($file, $secret) === strlen($secret) && fflush($file);
    if (!$written) {
        throw new RuntimeException('staff_secret');
    }
    return $secret;
}

/**
 * The page's own secret: 64 hex characters from <private>/cpay-staff-secret, created (0600) on first use. The
 * private folder itself is never made here.
 * @throws RuntimeException 'staff_secret' when the file can be neither read nor written
 */
function staff_secret(string $privateDir): string
{
    $path = $privateDir . '/' . STAFF_SECRET_FILE;
    $mask = umask(0077);
    try {
        $file = is_dir($privateDir) ? payments_quietly(static fn () => fopen($path, 'c+')) : false;
        if (!is_resource($file)) {
            throw new RuntimeException('staff_secret');
        }
        try {
            $secret = staff_secret_from($file);
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
        payments_quietly(static fn (): bool => chmod($path, 0600));
        return $secret;
    } finally {
        umask($mask);
    }
}

/**
 * True once the lock of an open file is had. It is asked for without waiting, $tries times (at least once) with a
 * short pause between, so a request never waits without end for one that hangs.
 * @param resource $file
 */
function staff_lock_taken($file, int $tries): bool
{
    for ($try = 1; ; $try++) {
        if (flock($file, LOCK_EX | LOCK_NB)) {
            return true;
        }
        if ($try >= $tries) {
            return false;
        }
        usleep(STAFF_LOCK_PAUSE_MICROSECONDS);
    }
}

/**
 * Runs $work while no other request is inside staff_locked(), and returns what it returns. Starting a payment
 * needs it: the look at a reservation's earlier charges and the writing of the new record must be one step, or two
 * forms for one reservation sent at the same moment both pass the look and nobody is asked (design 4.3).
 * The lock is a file of the staff page's own (<private>/cpay-staff-start.lock, 0600), never the store's .lock:
 * create_payment() takes that one itself, and it cannot be taken twice. The private folder is never made here.
 * @param int $tries how often the lock is asked for before giving up
 * @throws RuntimeException 'staff_lock' when the file cannot be opened or the lock does not come free in time
 */
function staff_locked(string $privateDir, callable $work, int $tries = STAFF_LOCK_TRIES): mixed
{
    $path = $privateDir . '/' . STAFF_LOCK_FILE;
    $mask = umask(0077);
    try {
        $file = is_dir($privateDir) ? payments_quietly(static fn () => fopen($path, 'c')) : false;
    } finally {
        umask($mask);
    }
    if (!is_resource($file)) {
        throw new RuntimeException('staff_lock');
    }
    try {
        if (!staff_lock_taken($file, $tries)) {
            throw new RuntimeException('staff_lock');
        }
        return $work();
    } finally {
        flock($file, LOCK_UN);
        fclose($file);
    }
}

/**
 * The token of one form: HMAC-SHA256 of "user|reference|hour" with the page's secret, as 64 hex characters.
 * $hour is the time in seconds divided by 3600. Neither a user name nor a reference can hold a "|".
 * @throws InvalidArgumentException 'staff_secret' for an empty secret
 */
function staff_token(#[\SensitiveParameter] string $secret, string $user, string $ref, int $hour): string
{
    if ($secret === '') {
        throw new InvalidArgumentException('staff_secret');
    }
    return hash_hmac('sha256', $user . '|' . $ref . '|' . $hour, $secret);
}

/** True when $token is the token of this user and reference for the hour of $now or the one before it. An empty secret verifies nothing. */
function staff_token_ok(#[\SensitiveParameter] string $secret, string $user, string $ref, string $token, int $now): bool
{
    if ($secret === '' || preg_match(STAFF_TOKEN_PATTERN, $token) !== 1) {
        return false;
    }
    $hour = intdiv($now, STAFF_TOKEN_SECONDS);
    return hash_equals(staff_token($secret, $user, $ref, $hour), $token)
        || hash_equals(staff_token($secret, $user, $ref, $hour - 1), $token);
}
