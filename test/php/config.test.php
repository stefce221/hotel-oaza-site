<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/api/lib/bootstrap.php';

test('config_problems names every missing setting', function (): void {
    same(['private_dir', 'rate.salt', 'mail.transport', 'mail.from', 'mail.from_name', 'mail.to'], config_problems([]));
});

test('config_problems accepts a complete SMTP config', function (): void {
    same([], config_problems([
        'private_dir' => '/home/oazahote/private',
        'rate' => ['salt' => 'x'],
        'mail' => [
            'transport' => 'smtp', 'host' => 'mail.example.mk', 'port' => 465, 'secure' => 'ssl',
            'username' => 'u', 'password' => 'p', 'from' => 'web@example.mk', 'from_name' => 'Web', 'to' => 'info@example.mk',
        ],
    ]));
});

test('the example config leaves exactly the secrets and the mailbox to fill in', function (): void {
    $example = require __DIR__ . '/../../deploy/private/config.example.php';
    same(['rate.salt', 'mail.from', 'mail.username', 'mail.password'], config_problems($example));
});

test('load_config refuses a missing file', function (): void {
    try {
        load_config(__DIR__ . '/no-such-config.php');
        truthy(false, 'should have thrown');
    } catch (RuntimeException $e) {
        same('config_missing', $e->getMessage());
    }
});

test('config_path uses OAZA_CONFIG, else private/config.php outside the web folder', function (): void {
    putenv('OAZA_CONFIG=/tmp/oaza-test-config.php');
    same('/tmp/oaza-test-config.php', config_path());
    putenv('OAZA_CONFIG');
    truthy(str_ends_with(config_path(), '/private/config.php'), config_path());
});

/** A complete SMTP config with one top-level section replaced. */
function smtp_config(array $override = []): array
{
    $mail = [
        'transport' => 'smtp', 'host' => 'mail.example.mk', 'port' => 465, 'secure' => 'ssl',
        'username' => 'u', 'password' => 'p', 'from' => 'web@example.mk', 'from_name' => 'Web', 'to' => 'info@example.mk',
    ];
    return array_replace([
        'private_dir' => '/home/oazahote/private',
        'rate' => ['limit' => 5, 'window' => 3600, 'salt' => 'x'],
        'mail' => array_replace($mail, $override['mail'] ?? []),
    ], array_diff_key($override, ['mail' => 1]));
}

test('config_problems rejects a rate limit or window that makes no sense', function (): void {
    same([], config_problems(smtp_config()), 'baseline');
    same(['rate.limit'], config_problems(smtp_config(['rate' => ['limit' => 0, 'window' => 3600, 'salt' => 'x']])), 'limit 0');
    same(['rate.limit'], config_problems(smtp_config(['rate' => ['limit' => '5', 'window' => 3600, 'salt' => 'x']])), 'limit as text');
    same(['rate.window'], config_problems(smtp_config(['rate' => ['limit' => 5, 'window' => 59, 'salt' => 'x']])), 'window under a minute');
});

test('config_problems rejects a mail address that is not one, by name only', function (): void {
    same(['mail.from'], config_problems(smtp_config(['mail' => ['from' => 'not-an-address']])));
    same(['mail.to'], config_problems(smtp_config(['mail' => ['to' => 'info@']])));
    truthy(!str_contains(implode(',', config_problems(smtp_config(['mail' => ['from' => 'secret-value']]))), 'secret-value'), 'no values');
});

test('config_problems checks the SMTP port and security setting', function (): void {
    same(['mail.port'], config_problems(smtp_config(['mail' => ['port' => 0]])), 'port 0');
    same(['mail.port'], config_problems(smtp_config(['mail' => ['port' => 70000]])), 'port too big');
    same(['mail.port'], config_problems(smtp_config(['mail' => ['port' => 'smtp']])), 'port as a word');
    same(['mail.secure'], config_problems(smtp_config(['mail' => ['secure' => 'none']])), 'secure');
    same([], config_problems(smtp_config(['mail' => ['port' => 587, 'secure' => 'tls']])), 'STARTTLS on 587');
});

test('config_path ignores OAZA_CONFIG outside the command line and the dev server', function (): void {
    same(true, in_array(PHP_SAPI, ['cli', 'cli-server'], true), 'these tests run on the CLI');
    putenv('OAZA_CONFIG=/tmp/oaza-test-config.php');
    same('/tmp/oaza-test-config.php', config_path(), 'honoured on the CLI');
    putenv('OAZA_CONFIG');
});
