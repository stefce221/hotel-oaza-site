<?php
/* Hotel Oaza — shared setup for api/enquiry.php, the cPay addresses and the staff page: PHP settings, libraries, config. */
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Europe/Skopje');

require_once __DIR__ . '/validate.php';
require_once __DIR__ . '/message.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/ratelimit.php';
require_once __DIR__ . '/request.php';
require_once __DIR__ . '/response.php';

/* The mark load_config() puts on a config that raised a warning while it loaded; also the log code and the name
   cpay_problems() (cpay/lib/settings.php) reports it by. */
const CONFIG_WARNING = 'config_warning';

/** OAZA_CONFIG (tests, command line and dev server only), else private/config.php three levels above api/lib/: /home/oazahote/private on MKhost. */
function config_path(): string
{
    $override = in_array(PHP_SAPI, ['cli', 'cli-server'], true) ? getenv('OAZA_CONFIG') : false;
    return is_string($override) && $override !== '' ? $override : dirname(__DIR__, 3) . '/private/config.php';
}

/** True for an int within the bounds (a number written as text does not count). */
function int_between(mixed $value, int $min, int $max): bool
{
    return is_int($value) && $value >= $min && $value <= $max;
}

/** True for a 'cpay' section whose push_ips (it may be left out) is a list of IPv4 addresses and nothing else. */
function push_ips_ok(#[\SensitiveParameter] mixed $cpay): bool
{
    $ips = is_array($cpay) ? ($cpay['push_ips'] ?? []) : null;
    if (!is_array($ips)) {
        return false;
    }
    foreach ($ips as $ip) {
        if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
    }
    return true;
}

/**
 * Values that are present but wrong, as dotted names. Empty values are reported as missing instead.
 * @return string[]
 */
function config_invalid(#[\SensitiveParameter] array $config, array $mail): array
{
    $rate = is_array($config['rate'] ?? null) ? $config['rate'] : [];
    $invalid = [];
    if (array_key_exists('limit', $rate) && !int_between($rate['limit'], 1, PHP_INT_MAX)) {
        $invalid[] = 'rate.limit';
    }
    if (array_key_exists('window', $rate) && !int_between($rate['window'], 60, PHP_INT_MAX)) {
        $invalid[] = 'rate.window';
    }
    foreach (['from', 'to'] as $key) {
        $value = $mail[$key] ?? '';
        if ($value !== '' && $value !== null && (!is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false)) {
            $invalid[] = "mail.$key";
        }
    }
    if (($mail['transport'] ?? '') === 'smtp') {
        if (($mail['port'] ?? null) !== null && $mail['port'] !== '' && !int_between($mail['port'], 1, 65535)) {
            $invalid[] = 'mail.port';
        }
        if (($mail['secure'] ?? null) !== null && $mail['secure'] !== '' && !in_array($mail['secure'], ['ssl', 'tls'], true)) {
            $invalid[] = 'mail.secure';
        }
    }
    if (array_key_exists('cpay', $config) && !push_ips_ok($config['cpay'])) {
        $invalid[] = 'cpay.push_ips';
    }
    return $invalid;
}

/**
 * The settings that are missing, empty or wrong, as dotted names (never values).
 * @return string[]
 */
function config_problems(#[\SensitiveParameter] array $config): array
{
    $mail = is_array($config['mail'] ?? null) ? $config['mail'] : [];
    $byTransport = ['smtp' => ['host', 'port', 'secure', 'username', 'password'], 'file' => ['file']];
    $transport = $mail['transport'] ?? '';
    $mailKeys = array_merge(['from', 'from_name', 'to'], $byTransport[$transport] ?? []);
    $empty = static fn (mixed $value): bool => $value === null || $value === '';
    $unusable = static fn (mixed $value): bool => $empty($value) || !is_string($value);
    return array_merge(
        $unusable($config['private_dir'] ?? null) ? ['private_dir'] : [],
        $unusable(is_array($config['rate'] ?? null) ? ($config['rate']['salt'] ?? null) : null) ? ['rate.salt'] : [],
        isset($byTransport[$transport]) ? [] : ['mail.transport'],
        array_map(
            static fn (string $key): string => "mail.$key",
            array_values(array_filter($mailKeys, static fn (string $key): bool => $empty($mail[$key] ?? null))),
        ),
        config_invalid($config, $mail),
    );
}

/**
 * The private config; throws RuntimeException naming what is wrong (never a value).
 * A config that raised a warning, notice or deprecation while it was compiled or ran is still returned, with
 * CONFIG_WARNING set: the enquiry form goes on, and cpay_problems() reports it, so cPay counts as not set up (design 6).
 */
function load_config(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('config_missing');
    }
    [$config, $warnings] = required_config($path);
    $problems = is_array($config) ? config_problems($config) : ['config.php must return an array'];
    if ($problems !== []) {
        throw new RuntimeException('config_incomplete: ' . implode(', ', $problems));
    }
    return $warnings > 0 ? [CONFIG_WARNING => true] + $config : $config;
}

/**
 * What config.php returns, and how many warnings, notices and deprecations PHP raised while it ran. None of them
 * reaches PHP's own log, which is still the server's at that moment (private_php_log() moves it after the load): the
 * likeliest, "Undefined variable", quotes what follows a '$' in a value between double quotes, a piece of the key.
 * Their messages are never kept. The file runs in a scope without variables, so every such '$' raises one.
 * A warning PHP raises while it compiles the file (an octal escape past \377 between double quotes, which it quotes)
 * reaches no error handler: log_errors is off while the file loads, and such a warning counts as one when PHP has it
 * as its last error afterwards. Under opcache it may be raised only on the request that compiles the file; a key
 * mangled that way then fails at cPay (checksum refused), it never charges a wrong amount.
 * log_errors and the caller's error handler are back afterwards, also when the file throws. A fatal error while the
 * file loads (not a ParseError, which is thrown) ends the request with nothing logged: a price paid so that no
 * message about config.php, which may quote the key, reaches the server's log.
 * @return array{0: mixed, 1: int}
 */
function required_config(string $path): array
{
    $warnings = 0;
    $logErrors = ini_set('log_errors', '0');
    error_clear_last();
    set_error_handler(static function () use (&$warnings): bool {
        $warnings++;
        return true; /* handled: PHP logs nothing */
    });
    try {
        $config = (static function () {
            return require func_get_arg(0);
        })($path);
        $compiled = error_get_last() !== null ? 1 : 0;
    } finally {
        error_clear_last(); /* the message is not kept */
        restore_error_handler();
        if ($logErrors !== false) {
            ini_set('log_errors', $logErrors);
        }
    }
    return [$config, $warnings + $compiled];
}

/** True for a config that raised a warning while it loaded (see load_config()). */
function config_warned(#[\SensitiveParameter] array $config): bool
{
    return ($config[CONFIG_WARNING] ?? false) === true;
}
