<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/cpay/lib/settings.php';

const SETTINGS_TEST_NAMES = [
    'cpay.merchant_id', 'cpay.merchant_name', 'cpay.key', 'cpay.payment_url', 'cpay.hash',
    'cpay.ok_url', 'cpay.fail_url', 'cpay.max_amount', 'cpay.retention_days',
];
const SETTINGS_TEST_REQUIRED = ['cpay.merchant_id', 'cpay.merchant_name', 'cpay.key', 'cpay.ok_url', 'cpay.fail_url', 'cpay.max_amount'];

/** A config with the specification's sample merchant and the test key (TEST_CPAY in test/helpers/php.mjs); $override replaces settings. */
function settings_test_config(array $override = []): array
{
    return ['cpay' => array_replace([
        'merchant_id' => '1000000003',
        'merchant_name' => 'Bookstore',
        'key' => 'TEST_PASS',
        'ok_url' => 'https://www.oazahotel.test/cpay/ok.php',
        'fail_url' => 'https://www.oazahotel.test/cpay/fail.php',
        'max_amount' => 300000,
    ], $override)];
}

/** Each case is [setting, value]: with only that setting changed, cpay_problems() must name exactly that setting. */
function settings_test_only(array $cases): void
{
    foreach ($cases as $label => [$name, $value]) {
        same(["cpay.$name"], cpay_problems(settings_test_config([$name => $value])), $label);
    }
}

/** Each case is [setting, value]: with only that setting changed, cpay_problems() must stay empty. */
function settings_test_accepted(array $cases): void
{
    foreach ($cases as $label => [$name, $value]) {
        same([], cpay_problems(settings_test_config([$name => $value])), $label);
    }
}

test('the cPay constants are the old address, both cPay hosts and 18 months', function (): void {
    same(['www.cpay.com.mk', 'vpos.cpay.com.mk'], CPAY_HOSTS);
    same('https://www.cpay.com.mk/client/Page/default.aspx?xml_id=/mk-MK/.loginToPay/.simple/', CPAY_DEFAULT_URL);
    same(548, CPAY_DEFAULT_RETENTION_DAYS);
});

test('cpay_problems names every missing setting for an empty section and for no section', function (): void {
    same(SETTINGS_TEST_REQUIRED, cpay_problems([]), 'no section');
    same(SETTINGS_TEST_REQUIRED, cpay_problems(['cpay' => []]), 'an empty section');
    same(SETTINGS_TEST_REQUIRED, cpay_problems(['cpay' => 'x']), 'a section that is not a list');
    same(SETTINGS_TEST_REQUIRED, cpay_problems(['cpay' => ['push_ips' => ['80.77.147.43']]]), 'a section with the push addresses only');
    $empty = array_fill_keys(['merchant_id', 'merchant_name', 'key', 'ok_url', 'fail_url', 'max_amount'], '');
    same(SETTINGS_TEST_REQUIRED, cpay_problems(['cpay' => $empty]), 'every value empty');
    $nulls = array_fill_keys(['merchant_id', 'merchant_name', 'key', 'ok_url', 'fail_url', 'max_amount'], null);
    same(SETTINGS_TEST_REQUIRED, cpay_problems(['cpay' => $nulls]), 'every value null');
});

test('cpay_problems accepts the test settings', function (): void {
    same([], cpay_problems(settings_test_config()));
    same([], cpay_problems(settings_test_config(['push_ips' => ['127.0.0.1']])), 'beside the push addresses');
    same([], cpay_problems(['private_dir' => '/x', 'mail' => [], 'rate' => []] + settings_test_config()), 'beside the other sections');
});

test('cpay_problems accepts the settings that may be left out, given or empty', function (): void {
    settings_test_accepted([
        'the default address' => ['payment_url', CPAY_DEFAULT_URL],
        'the new cPay host' => ['payment_url', 'https://vpos.cpay.com.mk/client/Page/default.aspx?xml_id=/mk-MK/.loginToPay/.simple/'],
        'a cPay host without a path' => ['payment_url', 'https://www.cpay.com.mk'],
        'an address of 500 bytes' => ['payment_url', 'https://www.cpay.com.mk/' . str_repeat('a', 476)],
        'payment_url empty' => ['payment_url', ''],
        'payment_url null' => ['payment_url', null],
        'md5' => ['hash', 'md5'],
        'hash empty' => ['hash', ''],
        'hash null' => ['hash', null],
        '30 days' => ['retention_days', 30],
        '3650 days' => ['retention_days', 3650],
        'retention empty' => ['retention_days', ''],
        'retention null' => ['retention_days', null],
    ]);
});

test('cpay_problems accepts merchant numbers, names and amounts at the edges of their rules', function (): void {
    settings_test_accepted([
        'one digit' => ['merchant_id', '7'],
        'a leading zero' => ['merchant_id', '0000000003'],
        "the specification's second sample" => ['merchant_name', 'ImeNaTrgovecot'],
        'every allowed kind of character' => ['merchant_name', 'Hotel 5 @ a-b/c?d=e&f'],
        '200 characters' => ['merchant_name', str_repeat('a', 200)],
        'a key with any characters' => ['key', "p'a\"s@@s <word>"],
        'one denar' => ['max_amount', 1],
        'seven digits' => ['max_amount', 9999999],
    ]);
});

test('cpay_problems: a merchant number is a text of digits and nothing else', function (): void {
    settings_test_only([
        'a letter' => ['merchant_id', '10000A0003'],
        'a number without quotes' => ['merchant_id', 1000000003],
        'a space' => ['merchant_id', '1000000003 '],
        'a line break at the end' => ['merchant_id', "1000000003\n"],
        'a sign' => ['merchant_id', '-1000000003'],
        'longer than any merchant number' => ['merchant_id', str_repeat('1', 21)],
    ]);
});

test('cpay_problems: a merchant name has 1 to 200 of the characters cPay accepts and no @@', function (): void {
    settings_test_only([
        'a single quote' => ['merchant_name', "Book'store"],
        'a double quote' => ['merchant_name', 'Book"store'],
        '@@' => ['merchant_name', 'Book@@store'],
        '201 characters' => ['merchant_name', str_repeat('a', 201)],
        'a Cyrillic letter' => ['merchant_name', 'Хотел'],
        'a dot' => ['merchant_name', 'Book.store'],
        'a line break at the end' => ['merchant_name', "Bookstore\n"],
        'a space at the start' => ['merchant_name', ' Bookstore'],
        'a space at the end' => ['merchant_name', 'Bookstore '],
        'not text' => ['merchant_name', ['Bookstore']],
    ]);
});

test('cpay_problems: the key is a text that is not empty', function (): void {
    settings_test_only([
        'a number' => ['key', 12345],
        'a list' => ['key', ['TEST_PASS']],
        'true' => ['key', true],
    ]);
});

test('cpay_problems: a key typed with a stray space, tab or line break around or inside it is reported', function (): void {
    /* The owner types the key by hand. A stray space would make every checksum wrong, and cPay would only
       show its error page: better that the staff page says "not set up" at once. */
    settings_test_only([
        'a space before' => ['key', ' TEST_PASS'],
        'a space after' => ['key', 'TEST_PASS '],
        'a line break after' => ['key', "TEST_PASS\n"],
        'a carriage return after' => ['key', "TEST_PASS\r"],
        'a tab inside' => ['key', "TEST\tPASS"],
        'a NUL inside' => ['key', "TEST\0PASS"],
        'only spaces' => ['key', '   '],
    ]);
    same([], cpay_problems(settings_test_config(['key' => 'TEST PASS'])), 'a space inside a key is allowed');
});

test('cpay_problems: payment_url is an https address on one of the two cPay hosts', function (): void {
    $path = '/client/Page/default.aspx?xml_id=/mk-MK/.loginToPay/.simple/';
    settings_test_only([
        'http' => ['payment_url', 'http://www.cpay.com.mk' . $path],
        'another host' => ['payment_url', 'https://pay.example.com' . $path],
        'a host that only starts like cPay' => ['payment_url', 'https://www.cpay.com.mk.example.com' . $path],
        'another cPay host name' => ['payment_url', 'https://cpay.com.mk' . $path],
        'a port' => ['payment_url', 'https://www.cpay.com.mk:8443' . $path],
        'user info' => ['payment_url', 'https://user@www.cpay.com.mk' . $path],
        'cPay as the user of another host' => ['payment_url', 'https://www.cpay.com.mk@pay.example.com' . $path],
        'a double quote' => ['payment_url', 'https://www.cpay.com.mk/client/"x'],
        'a single quote' => ['payment_url', "https://www.cpay.com.mk/client/'x"],
        'a space' => ['payment_url', 'https://www.cpay.com.mk/client/ x'],
        'a tag' => ['payment_url', 'https://www.cpay.com.mk/client/<x>'],
        'a fragment' => ['payment_url', 'https://www.cpay.com.mk/client/#x'],
        'a backslash' => ['payment_url', 'https://www.cpay.com.mk\\@pay.example.com/'],
        'a line break at the end' => ['payment_url', 'https://www.cpay.com.mk' . $path . "\n"],
        'capital letters in the host' => ['payment_url', 'https://WWW.CPAY.COM.MK' . $path],
        'no scheme' => ['payment_url', 'www.cpay.com.mk' . $path],
        'an address of 501 bytes' => ['payment_url', 'https://www.cpay.com.mk/' . str_repeat('a', 477)],
        'not text' => ['payment_url', 1],
    ]);
});

test('cpay_problems: hash is md5 or left out', function (): void {
    settings_test_only([
        'sha256' => ['hash', 'sha256'],
        'capital letters' => ['hash', 'MD5'],
        'not text' => ['hash', 5],
    ]);
});

test('cpay_problems: ok_url is https://<host>/cpay/ok.php', function (): void {
    settings_test_only([
        'http' => ['ok_url', 'http://www.oazahotel.test/cpay/ok.php'],
        'another path' => ['ok_url', 'https://www.oazahotel.test/pay/ok.php'],
        'the fail address' => ['ok_url', 'https://www.oazahotel.test/cpay/fail.php'],
        'a query' => ['ok_url', 'https://www.oazahotel.test/cpay/ok.php?x=1'],
        'a port' => ['ok_url', 'https://www.oazahotel.test:8443/cpay/ok.php'],
        'user info' => ['ok_url', 'https://user@www.oazahotel.test/cpay/ok.php'],
        'a quote' => ['ok_url', "https://www.oazahotel.test'/cpay/ok.php"],
        'capital letters in the host' => ['ok_url', 'https://www.OazaHotel.test/cpay/ok.php'],
        'a dot after the host' => ['ok_url', 'https://www.oazahotel.test./cpay/ok.php'],
        'a line break at the end' => ['ok_url', "https://www.oazahotel.test/cpay/ok.php\n"],
        'no host' => ['ok_url', 'https:///cpay/ok.php'],
        'not text' => ['ok_url', ['https://www.oazahotel.test/cpay/ok.php']],
    ]);
});

test('cpay_problems: fail_url is https://<the same host>/cpay/fail.php', function (): void {
    settings_test_only([
        'another host' => ['fail_url', 'https://www.example.test/cpay/fail.php'],
        'the host without www' => ['fail_url', 'https://oazahotel.test/cpay/fail.php'],
        'http' => ['fail_url', 'http://www.oazahotel.test/cpay/fail.php'],
        'another path' => ['fail_url', 'https://www.oazahotel.test/cpay/failed.php'],
        'the ok address' => ['fail_url', 'https://www.oazahotel.test/cpay/ok.php'],
        'a line break at the end' => ['fail_url', "https://www.oazahotel.test/cpay/fail.php\n"],
        'not text' => ['fail_url', 0],
    ]);
});

test('cpay_problems judges fail_url by its own shape when ok_url is wrong, and names both when both are', function (): void {
    same(
        ['cpay.ok_url'],
        cpay_problems(settings_test_config(['ok_url' => 'http://www.oazahotel.test/cpay/ok.php', 'fail_url' => 'https://www.example.test/cpay/fail.php'])),
        'a wrong ok_url gives no host to compare with',
    );
    same(
        ['cpay.ok_url', 'cpay.fail_url'],
        cpay_problems(settings_test_config(['ok_url' => 'https://www.oazahotel.test/cpay/fail.php', 'fail_url' => 'https://www.oazahotel.test/cpay/ok.php'])),
        'the two addresses swapped',
    );
    same(
        [],
        cpay_problems(settings_test_config(['ok_url' => 'https://www.oazahotel.com.mk/cpay/ok.php', 'fail_url' => 'https://www.oazahotel.com.mk/cpay/fail.php'])),
        'both on the live host',
    );
});

test('cpay_problems: the host of the return addresses has at most 253 characters', function (): void {
    $label = str_repeat('a', 63);
    $longest = "$label.$label.$label." . str_repeat('b', 61);
    $on = static fn (string $host): array => ['ok_url' => "https://$host/cpay/ok.php", 'fail_url' => "https://$host/cpay/fail.php"];
    same(253, strlen($longest));
    same([], cpay_problems(settings_test_config($on($longest))), '253 characters');
    same($longest, cpay_settings(settings_test_config($on($longest)))['host']);
    same(['cpay.ok_url', 'cpay.fail_url'], cpay_problems(settings_test_config($on($longest . 'b'))), '254 characters');
});

test('cpay_problems: max_amount is a whole number from 1 to 9999999', function (): void {
    settings_test_only([
        'text' => ['max_amount', '300000'],
        'zero' => ['max_amount', 0],
        'eight digits' => ['max_amount', 10000000],
        'negative' => ['max_amount', -1],
        'a number with a decimal point' => ['max_amount', 300000.0],
        'true' => ['max_amount', true],
    ]);
});

test('cpay_problems: retention_days is a whole number from 30 to 3650 or left out', function (): void {
    settings_test_only([
        '29 days' => ['retention_days', 29],
        '3651 days' => ['retention_days', 3651],
        'text' => ['retention_days', '548'],
        'zero' => ['retention_days', 0],
        'a number with a decimal point' => ['retention_days', 548.0],
    ]);
});

test('cpay_problems lists the names in one fixed order and never a value', function (): void {
    $problems = cpay_problems(settings_test_config([
        'merchant_id' => 'MARKER-ID', 'merchant_name' => "MARKER'NAME", 'key' => ['MARKER-KEY'],
        'payment_url' => 'http://MARKER-URL', 'hash' => 'MARKER-HASH', 'ok_url' => 'MARKER-OK', 'fail_url' => 'MARKER-FAIL',
        'max_amount' => 'MARKER-MAX', 'retention_days' => 'MARKER-DAYS',
    ]));
    same(SETTINGS_TEST_NAMES, $problems);
    truthy(!str_contains(implode(',', $problems), 'MARKER'), 'no values');
    $withKey = cpay_problems(settings_test_config(['merchant_id' => 'x']));
    truthy(!str_contains(implode(',', $withKey), 'TEST_PASS'), 'the key is never among the problems');
});

test('cpay_problems does not change the config it is given', function (): void {
    $config = settings_test_config(['hash' => '', 'retention_days' => null]);
    $copy = $config;
    cpay_problems($config);
    cpay_settings($config);
    same($copy, $config);
});

test('cpay_settings fills the defaults and the host', function (): void {
    same([
        'merchant_id' => '1000000003',
        'merchant_name' => 'Bookstore',
        'key' => 'TEST_PASS',
        'payment_url' => CPAY_DEFAULT_URL,
        'hash' => 'md5',
        'ok_url' => 'https://www.oazahotel.test/cpay/ok.php',
        'fail_url' => 'https://www.oazahotel.test/cpay/fail.php',
        'host' => 'www.oazahotel.test',
        'max_amount' => 300000,
        'retention_days' => 548,
    ], cpay_settings(settings_test_config()));
    $blank = cpay_settings(settings_test_config(['payment_url' => '', 'hash' => null, 'retention_days' => '']));
    same([CPAY_DEFAULT_URL, 'md5', 548], [$blank['payment_url'], $blank['hash'], $blank['retention_days']], 'empty counts as left out');
});

test('cpay_settings keeps what the config gives and leaves the other settings of the section out', function (): void {
    $vpos = 'https://vpos.cpay.com.mk/client/Page/default.aspx?xml_id=/mk-MK/.loginToPay/.simple/';
    $settings = cpay_settings(settings_test_config([
        'payment_url' => $vpos, 'hash' => 'md5', 'retention_days' => 90, 'max_amount' => 150000,
        'ok_url' => 'https://www.oazahotel.com.mk/cpay/ok.php', 'fail_url' => 'https://www.oazahotel.com.mk/cpay/fail.php',
        'push_ips' => ['80.77.147.43'], 'something_else' => 'x',
    ]));
    same($vpos, $settings['payment_url']);
    same(90, $settings['retention_days']);
    same(150000, $settings['max_amount']);
    same('www.oazahotel.com.mk', $settings['host']);
    same(
        ['merchant_id', 'merchant_name', 'key', 'payment_url', 'hash', 'ok_url', 'fail_url', 'host', 'max_amount', 'retention_days'],
        array_keys($settings),
    );
});

test('cpay_settings refuses incomplete settings with a code and nothing from the config', function (): void {
    $incomplete = [[], ['cpay' => []], settings_test_config(['key' => '']), settings_test_config(['hash' => 'sha256'])];
    foreach ($incomplete as $index => $config) {
        try {
            cpay_settings($config);
            truthy(false, "case $index should have thrown");
        } catch (RuntimeException $e) {
            same('cpay_incomplete', $e->getMessage(), "case $index");
        }
    }
});
