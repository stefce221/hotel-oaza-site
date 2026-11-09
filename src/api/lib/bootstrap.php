<?php
/* Hotel Oaza — shared setup for api/enquiry.php and the cPay placeholders: PHP settings, libraries, config. */
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

/**
 * Values that are present but wrong, as dotted names. Empty values are reported as missing instead.
 * @return string[]
 */
function config_invalid(array $config, array $mail): array
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
    return $invalid;
}

/**
 * The settings that are missing, empty or wrong, as dotted names (never values).
 * @return string[]
 */
function config_problems(array $config): array
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

/** The private config; throws RuntimeException naming what is wrong (never a value). */
function load_config(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('config_missing');
    }
    $config = require $path;
    $problems = is_array($config) ? config_problems($config) : ['config.php must return an array'];
    if ($problems !== []) {
        throw new RuntimeException('config_incomplete: ' . implode(', ', $problems));
    }
    return $config;
}
