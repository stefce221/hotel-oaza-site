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

/** OAZA_CONFIG (tests), else private/config.php three levels above api/lib/: /home/oazahote/private on MKhost. */
function config_path(): string
{
    $override = getenv('OAZA_CONFIG');
    return is_string($override) && $override !== '' ? $override : dirname(__DIR__, 3) . '/private/config.php';
}

/**
 * The settings that are missing or empty, as dotted names.
 * @return string[]
 */
function config_problems(array $config): array
{
    $mail = is_array($config['mail'] ?? null) ? $config['mail'] : [];
    $byTransport = ['smtp' => ['host', 'port', 'secure', 'username', 'password'], 'file' => ['file']];
    $transport = $mail['transport'] ?? '';
    $mailKeys = array_merge(['from', 'from_name', 'to'], $byTransport[$transport] ?? []);
    $empty = static fn (mixed $value): bool => $value === null || $value === '';
    return array_merge(
        $empty($config['private_dir'] ?? null) ? ['private_dir'] : [],
        $empty($config['rate']['salt'] ?? null) ? ['rate.salt'] : [],
        isset($byTransport[$transport]) ? [] : ['mail.transport'],
        array_map(
            static fn (string $key): string => "mail.$key",
            array_values(array_filter($mailKeys, static fn (string $key): bool => $empty($mail[$key] ?? null))),
        ),
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
