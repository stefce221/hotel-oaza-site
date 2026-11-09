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
