<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/api/lib/bootstrap.php';
require_once __DIR__ . '/../../src/cpay/lib/settings.php';

test('config_problems names every missing setting', function (): void {
    same(['private_dir', 'rate.salt', 'mail.transport', 'mail.from', 'mail.from_name', 'mail.to'], config_problems([]));
});

test('config_problems rejects a private_dir or salt that is not a string, by name only', function (): void {
    $base = smtp_config();
    same(['private_dir'], config_problems(array_merge($base, ['private_dir' => 42])), 'private_dir as a number');
    same(['private_dir'], config_problems(array_merge($base, ['private_dir' => ['x']])), 'private_dir as a list');
    same(['rate.salt'], config_problems(array_merge($base, ['rate' => ['limit' => 5, 'window' => 3600, 'salt' => 12345]])), 'salt as a number');
    truthy(!str_contains(implode(',', config_problems(array_merge($base, ['rate' => ['salt' => 98765]]))), '98765'), 'no values');
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

test("the example config lists cPay's push address", function (): void {
    $example = require __DIR__ . '/../../deploy/private/config.example.php';
    same(['80.77.147.43'], $example['cpay']['push_ips'] ?? null);
});

test('config_problems accepts cPay push addresses and rejects anything but a list of IPv4 addresses, by name only', function (): void {
    same([], config_problems(smtp_config(['cpay' => ['push_ips' => ['80.77.147.43']]])), 'one address');
    same([], config_problems(smtp_config(['cpay' => ['push_ips' => []]])), 'an empty list');
    same([], config_problems(smtp_config(['cpay' => []])), 'a section without the list');
    same(['cpay.push_ips'], config_problems(smtp_config(['cpay' => ['push_ips' => '80.77.147.43']])), 'text, not a list');
    same(['cpay.push_ips'], config_problems(smtp_config(['cpay' => ['push_ips' => ['80.77.147.43 ']]])), 'a trailing space');
    same(['cpay.push_ips'], config_problems(smtp_config(['cpay' => ['push_ips' => ['cpay.com.mk']]])), 'a host name');
    same(['cpay.push_ips'], config_problems(smtp_config(['cpay' => ['push_ips' => ['2001:db8::1']]])), 'IPv6');
    same(['cpay.push_ips'], config_problems(smtp_config(['cpay' => ['push_ips' => [80]]])), 'a number');
    same(['cpay.push_ips'], config_problems(smtp_config(['cpay' => 'x'])), 'a section that is not a list');
    truthy(!str_contains(implode(',', config_problems(smtp_config(['cpay' => ['push_ips' => ['not-an-ip']]]))), 'not-an-ip'), 'no values');
});

test('the example config leaves exactly the three cPay values to fill in on the server', function (): void {
    $example = require __DIR__ . '/../../deploy/private/config.example.php';
    same(['cpay.merchant_id', 'cpay.merchant_name', 'cpay.key'], cpay_problems($example));
    same(['', '', ''], [$example['cpay']['merchant_id'] ?? null, $example['cpay']['merchant_name'] ?? null, $example['cpay']['key'] ?? null], 'empty in the repository');
});

test('the example config names the live return addresses and the largest charge', function (): void {
    $example = require __DIR__ . '/../../deploy/private/config.example.php';
    same('https://www.oazahotel.com.mk/cpay/ok.php', $example['cpay']['ok_url'] ?? null);
    same('https://www.oazahotel.com.mk/cpay/fail.php', $example['cpay']['fail_url'] ?? null);
    same(300000, $example['cpay']['max_amount'] ?? null);
    $filled = array_replace($example['cpay'], ['merchant_id' => '1000000003', 'merchant_name' => 'Bookstore', 'key' => 'TEST_PASS']);
    same([], cpay_problems(['cpay' => $filled]), 'complete once the three values are typed in');
    same('www.oazahotel.com.mk', cpay_settings(['cpay' => $filled])['host']);
});

test('config_problems is unchanged by any cPay payment setting, missing or wrong', function (): void {
    $wrong = [
        'merchant_id' => 'not-digits', 'merchant_name' => "it's", 'key' => '', 'payment_url' => 'http://pay.example.com/',
        'hash' => 'sha256', 'ok_url' => 'x', 'fail_url' => 'y', 'max_amount' => '300000', 'retention_days' => 1,
    ];
    same([], config_problems(smtp_config(['cpay' => $wrong])), 'every payment setting wrong');
    same([], config_problems(smtp_config(['cpay' => ['push_ips' => ['80.77.147.43']] + $wrong])), 'beside the push addresses');
    same([], config_problems(smtp_config(['cpay' => ['key' => ['a list']]])), 'a key that is not text');
    same(['cpay.push_ips'], config_problems(smtp_config(['cpay' => ['push_ips' => 'x'] + $wrong])), 'only the push addresses are its business');
    $complete = [
        'merchant_id' => '1000000003', 'merchant_name' => 'Bookstore', 'key' => 'TEST_PASS', 'max_amount' => 300000,
        'ok_url' => 'https://www.oazahotel.test/cpay/ok.php', 'fail_url' => 'https://www.oazahotel.test/cpay/fail.php',
    ];
    same([], config_problems(smtp_config(['cpay' => $complete])), 'complete payment settings');
    same(['rate.salt'], config_problems(array_replace(smtp_config(['cpay' => $complete]), ['rate' => []])), 'the other sections are still checked');
});

test('load_config accepts a config whose cPay payment settings are missing or wrong', function (): void {
    $path = (string) tempnam(sys_get_temp_dir(), 'oaza-config-');
    $config = smtp_config(['cpay' => ['push_ips' => ['80.77.147.43'], 'merchant_id' => 'not-digits', 'hash' => 'sha256']]);
    file_put_contents($path, '<?php return ' . var_export($config, true) . ';');
    try {
        same($config, load_config($path));
        same(
            ['cpay.merchant_id', 'cpay.merchant_name', 'cpay.key', 'cpay.hash', 'cpay.ok_url', 'cpay.fail_url', 'cpay.max_amount'],
            cpay_problems(load_config($path)),
            'cpay_problems is where they are reported',
        );
    } finally {
        unlink($path);
    }
});

test('load_config refuses a missing file', function (): void {
    try {
        load_config(__DIR__ . '/no-such-config.php');
        truthy(false, 'should have thrown');
    } catch (RuntimeException $e) {
        same('config_missing', $e->getMessage());
    }
});

test('load_config refuses a cpay section that is not a list of addresses, without quoting it', function (): void {
    $path = (string) tempnam(sys_get_temp_dir(), 'oaza-config-');
    file_put_contents($path, '<?php return ' . var_export(smtp_config(['cpay' => ['push_ips' => '80.77.147.43']]), true) . ';');
    try {
        load_config($path);
        truthy(false, 'should have thrown');
    } catch (RuntimeException $e) {
        same('config_incomplete: cpay.push_ips', $e->getMessage());
    } finally {
        unlink($path);
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

test('the bootstrap functions that are handed the whole config, cpay.key included, mark it #[\SensitiveParameter]', function (): void {
    foreach (['config_problems' => 'config', 'config_invalid' => 'config', 'push_ips_ok' => 'cpay'] as $function => $parameter) {
        $marked = array_filter(
            (new ReflectionFunction($function))->getParameters(),
            static fn (ReflectionParameter $p): bool => $p->getName() === $parameter && $p->getAttributes(SensitiveParameter::class) !== [],
        );
        truthy($marked !== [], "$function(\$$parameter) is marked #[\\SensitiveParameter]");
    }
});
