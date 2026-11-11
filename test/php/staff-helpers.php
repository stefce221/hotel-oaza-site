<?php
/* Hotel Oaza — what the tests of the staff page (test/php/staff*.test.php) share: the clock, the config, the request,
   the forms and records they build, and small readers of pages and folders. The runner loads only *.test.php
   files, so this one is loaded by the test files themselves. */
declare(strict_types=1);

require_once __DIR__ . '/../../src/cpay/lib/staff.php';
require_once __DIR__ . '/../../src/cpay/lib/staff-pages.php';
require_once __DIR__ . '/../../src/cpay/lib/staff-app.php';

/* 2026-11-11 10:00 UTC, 11:00 in Skopje: the first second of an hour. */
const STAFF_TEST_NOW = 1794391200;
/* 2026-11-11 00:00 in Skopje. */
const STAFF_TEST_DAY = 1794351600;
const STAFF_TEST_HOST = 'www.oazahotel.test';
const STAFF_TEST_SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
const STAFF_TEST_CPAY_NAMES = ['AmountToPay', 'PayToMerchant', 'MerchantName', 'AmountCurrency', 'Details1', 'Details2', 'PaymentOKURL', 'PaymentFailURL', 'CheckSumHeader', 'CheckSum'];

function staff_test_remove(string $path): void
{
    if (is_dir($path)) {
        foreach (array_diff(scandir($path), ['.', '..']) as $name) {
            staff_test_remove($path . '/' . $name);
        }
        rmdir($path);
        return;
    }
    if (is_file($path)) {
        unlink($path);
    }
}

/** Runs $body with a throw-away private folder and removes the folder afterwards, whatever happened. */
function staff_test_private(callable $body): void
{
    $private = str_replace('\\', '/', sys_get_temp_dir()) . '/oaza-staff-' . bin2hex(random_bytes(6));
    mkdir($private, 0700);
    try {
        $body($private);
    } finally {
        staff_test_remove($private);
    }
}

/** A complete config for that private folder: the specification's sample merchant and its public test key; $cpay replaces settings. */
function staff_test_config(string $private, array $cpay = []): array
{
    return [
        'private_dir' => $private,
        'rate' => ['limit' => 5, 'window' => 3600, 'salt' => 'test-salt'],
        'cpay' => array_replace([
            'merchant_id' => '1000000003',
            'merchant_name' => 'Bookstore',
            'key' => 'TEST_PASS',
            'ok_url' => 'https://' . STAFF_TEST_HOST . '/cpay/ok.php',
            'fail_url' => 'https://' . STAFF_TEST_HOST . '/cpay/fail.php',
            'max_amount' => 300000,
        ], $cpay),
    ];
}

/** What the live server gives PHP for a form posted by a signed-in user; $changes replaces entries. */
function staff_test_server(array $changes = []): array
{
    return array_replace([
        'REQUEST_METHOD' => 'POST',
        'REMOTE_USER' => 'recepcija',
        'HTTPS' => 'on',
        'HTTP_HOST' => STAFF_TEST_HOST,
        'HTTP_ORIGIN' => 'https://' . STAFF_TEST_HOST,
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ], $changes);
}

/** The fields of a form that the page issued to $user at $now, filled in; $typed replaces fields. */
function staff_test_fields(array $config, int $now, array $typed = [], string $user = 'recepcija'): array
{
    $ref = new_reference($now);
    $token = staff_token(staff_secret($config['private_dir']), $user, $ref, intdiv($now, 3600));
    return array_replace(['amount' => '21802', 'reservation' => '4567890123', 'ref' => $ref, 'token' => $token], $typed);
}

/** A form the page issued to recepcija at STAFF_TEST_NOW, whose reference ends in $tail, filled in; $typed replaces fields. */
function staff_test_form(string $private, string $tail, array $typed = []): array
{
    $ref = substr(new_reference(STAFF_TEST_NOW), 0, 6) . $tail;
    $token = staff_token(staff_secret($private), 'recepcija', $ref, intdiv(STAFF_TEST_NOW, 3600));
    return array_replace(['amount' => '21802', 'reservation' => '4567890123', 'ref' => $ref, 'token' => $token], $typed);
}

/** True when the page is the question before a second charge and carries nothing for cPay. */
function staff_test_asked(string $html): bool
{
    return str_contains($html, 'Да се наплати уште еднаш?') && array_intersect(STAFF_TEST_CPAY_NAMES, array_keys(staff_test_hidden($html))) === [];
}

/** True when the page is the form that goes on to cPay with the ten fields. */
function staff_test_to_cpay(string $html): bool
{
    return array_keys(staff_test_hidden($html)) === STAFF_TEST_CPAY_NAMES;
}

/** The hidden fields of a page, name => value, in their order. */
function staff_test_hidden(string $html): array
{
    preg_match_all('/<input type="hidden" name="([^"]*)" value="([^"]*)">/', $html, $found, PREG_SET_ORDER);
    $fields = [];
    foreach ($found as $one) {
        $fields[html_entity_decode($one[1], ENT_QUOTES)] = html_entity_decode($one[2], ENT_QUOTES);
    }
    return $fields;
}

/** A record started at $time by the test; $changes replaces parts of it. */
function staff_test_record(int $time, array $changes = [], string $tail = 'AAAA'): array
{
    $ref = substr(new_reference($time), 0, 6) . $tail;
    $url = 'https://www.cpay.com.mk/client/Page/default.aspx?xml_id=/mk-MK/.loginToPay/.simple/';
    return array_replace(new_payment($ref, 'recepcija', 21802, '4567890123', 'booking 4567890123', $url, 'md5', $time), $changes);
}

/** What the form page is given; $changes replaces parts of it. */
function staff_test_view(array $changes = []): array
{
    return array_replace([
        'user' => 'recepcija', 'ready' => true, 'ref' => 'TMZK4A7Q2B', 'token' => str_repeat('a', 64),
        'amount' => '', 'reservation' => '', 'errors' => [], 'max' => 300000,
        'rows' => [], 'today' => ['count' => 0, 'sum' => 0], 'now' => STAFF_TEST_NOW,
    ], $changes);
}

/** The table row of the page that holds this text (the end of a reference and of its cell); '' when there is none. */
function staff_test_row(string $html, string $text): string
{
    foreach (explode('<tr', $html) as $part) {
        if (str_contains($part, $text)) {
            return $part;
        }
    }
    return '';
}

function staff_test_records(string $private): array
{
    return payment_file_names(payments_dir($private));
}

function staff_test_log(string $private): string
{
    return is_file($private . '/logs/errors.log') ? (string) file_get_contents($private . '/logs/errors.log') : '';
}
