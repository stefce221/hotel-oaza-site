<?php
/* Helpers of the tests of the cPay notice handler (notice.test.php, notice-body.test.php). */
declare(strict_types=1);

require_once __DIR__ . '/../../src/cpay/lib/notice.php';

/* The merchant of the specification's first sample and its public test key. */
const NOTICE_TEST_CPAY = [
    'merchant_id' => '1000000003',
    'merchant_name' => 'Bookstore',
    'key' => 'TEST_PASS',
    'ok_url' => 'https://www.oazahotel.test/cpay/ok.php',
    'fail_url' => 'https://www.oazahotel.test/cpay/fail.php',
    'max_amount' => 300000,
];
/* Addresses reserved for documentation: the first stands for cPay's own sender, the second for anyone else. */
const NOTICE_TEST_CPAY_ADDRESS = '203.0.113.9';
const NOTICE_TEST_PUSH = ['REMOTE_ADDR' => NOTICE_TEST_CPAY_ADDRESS, 'CONTENT_LENGTH' => '700'];
const NOTICE_TEST_BROWSER = ['REMOTE_ADDR' => '198.51.100.7', 'CONTENT_LENGTH' => '700', 'HTTP_SEC_FETCH_SITE' => 'cross-site'];
const NOTICE_TEST_UNLISTED = ['REMOTE_ADDR' => '198.51.100.7', 'CONTENT_LENGTH' => '700'];
/* The page these addresses showed before they could verify anything: its text and markup must stay. */
const NOTICE_TEST_NEUTRAL = <<<'HTML'
<!doctype html>
<html lang="mk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Хотел Оаза — статус на плаќањето</title>
<style>body{font:1rem/1.6 system-ui,sans-serif;max-width:36rem;margin:4rem auto;padding:0 1rem;color:#1d2a30}a{color:#0a6f99}hr{margin:2rem 0}</style>
</head>
<body>
<h1>Го примивме статусот на плаќањето.</h1>
<p>Ви благодариме. За прашања околу плаќањето, контактирајте го хотелот: <a href="tel:+38970310300">+389 70 310 300</a>, <a href="mailto:info@oazahotel.com.mk">info@oazahotel.com.mk</a>.</p>
<p><a href="/">Хотел Оаза — почетна</a></p>
<hr>
<div lang="en">
<h2>Payment status received.</h2>
<p>Thank you. For any question about your payment, contact the hotel: <a href="tel:+38970310300">+389 70 310 300</a>, <a href="mailto:info@oazahotel.com.mk">info@oazahotel.com.mk</a>.</p>
<p><a href="/en/">Hotel Oaza — home</a></p>
</div>
</body>
</html>
HTML;

/** Removes a file, or a folder with everything in it. */
function notice_test_remove(string $path): void
{
    if (is_dir($path)) {
        foreach (array_diff(scandir($path), ['.', '..']) as $name) {
            notice_test_remove($path . '/' . $name);
        }
        rmdir($path);
        return;
    }
    if (is_file($path)) {
        unlink($path);
    }
}

/** Runs $body with a throw-away private folder and removes the folder afterwards, whatever happened. */
function notice_test_private(callable $body): void
{
    $private = str_replace('\\', '/', sys_get_temp_dir()) . '/oaza-notice-' . bin2hex(random_bytes(6));
    mkdir($private, 0700);
    try {
        $body($private);
    } finally {
        notice_test_remove($private);
    }
}

/** A complete config for that private folder: cPay's own address listed, the test merchant, and $cpay on top. */
function notice_test_config(string $private, array $cpay = []): array
{
    return [
        'private_dir' => $private,
        'mail' => ['transport' => 'file', 'file' => $private . '/sent.jsonl', 'from' => 'web@example.mk', 'from_name' => 'Web', 'to' => 'info@example.mk'],
        'rate' => ['limit' => 5, 'window' => 3600, 'salt' => 'test-salt'],
        'cpay' => array_merge(['push_ips' => [NOTICE_TEST_CPAY_ADDRESS]], NOTICE_TEST_CPAY, $cpay),
    ];
}

/** A waiting record of 21.802 denars in that private folder, with $change on top. */
function notice_test_record(string $private, array $change = []): array
{
    $record = array_merge(
        new_payment('TMZK4A7Q2B', 'recepcija', 21802, '4567890123', 'booking 4567890123', CPAY_DEFAULT_URL, 'md5', time() - 60),
        $change,
    );
    truthy(create_payment(payments_dir($private), $record), 'the record is written');
    return $record;
}

/** The eight parameters this site sends for a record, in the order it signs them, with $change on top. */
function notice_test_pairs(array $record, array $change = []): array
{
    $request = charge_request(cpay_settings(notice_test_config('')), $record['amount'], $record['reservation'], $record['ref']);
    return array_merge(array_slice($request['fields'], 0, 8, true), $change);
}

/** What cPay sends back for those parameters: they, cPayPaymentRef when given, a few empty fields it does not sign,
    the return header (first two names swapped, cPayPaymentRef last) and the return checksum. */
function notice_test_return(array $pairs, ?string $cpayRef = '123456', string $key = 'TEST_PASS'): array
{
    $names = array_keys($pairs);
    $signed = [];
    foreach (array_merge([$names[1], $names[0]], array_slice($names, 2)) as $name) {
        $signed[$name] = $pairs[$name];
    }
    $added = $cpayRef === null ? [] : ['cPayPaymentRef' => $cpayRef];
    $sum = cpay_checksum($signed + $added, $key);
    return $pairs + $added + ['ServiceID' => '', 'Details3' => '', 'FirstName' => '']
        + ['ReturnCheckSumHeader' => $sum['header'], 'ReturnCheckSum' => $sum['checksum']];
}

/** $count fields that are no part of a notice: pad0 => 'x', pad1 => 'x', … */
function notice_test_padding(int $count): array
{
    $padding = [];
    for ($i = 0; $i < $count; $i++) {
        $padding['pad' . $i] = 'x';
    }
    return $padding;
}

/** The specification's return sample (19 parameters, cPayPaymentRef 123456), with $change on top. */
function notice_test_sample(array $change = []): array
{
    return array_merge([
        'PaymentOKURL' => 'www.OKUrl.com.mk', 'PaymentFailURL' => 'www.FailUrl.com.mk', 'AmountToPay' => '100',
        'AmountCurrency' => 'MKD', 'PayToMerchant' => '1234567890', 'Details1' => 'Detali 1', 'Details2' => '123',
        'MerchantName' => 'ImeNaTrgovecot', 'FirstName' => 'Petar', 'LastName' => 'Petrevski', 'Telephone' => '38977777777',
        'Email' => 'petarp@gmail.com', 'Zip' => '1000', 'Address' => 'KJP 1/2', 'City' => 'Skopje', 'Country' => '807',
        'OriginalAmount' => '10', 'OriginalCurrency' => 'EUR', 'cPayPaymentRef' => '123456',
        'ReturnCheckSumHeader' => '19PaymentFailURL,PaymentOKURL,AmountToPay,AmountCurrency,PayToMerchant,Details1,Details2,MerchantName,'
            . 'FirstName,LastName,Telephone,Email,Zip,Address,City,Country,OriginalAmount,OriginalCurrency,cPayPaymentRef,'
            . '018016003003010008003014005009011016004007006003002003006',
        'ReturnCheckSum' => '97F4E18E88A48D4BAA1742164A3AFD8B',
    ], $change);
}

/** The settings of the merchant in the specification's second sample, with the test key. */
function notice_test_sample_settings(array $change = []): array
{
    return cpay_settings(notice_test_config('', array_merge(['merchant_id' => '1234567890', 'merchant_name' => 'ImeNaTrgovecot'], $change)));
}

/** The codes in the private folder's error log, oldest first. */
function notice_test_codes(string $private): array
{
    $log = $private . '/logs/errors.log';
    $lines = is_file($log) ? file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    return array_map(static fn (string $line): string => substr($line, (int) strpos($line, ' ') + 1), $lines);
}

/** The raw copies in the private folder's inbox (files named as a copy is), each with its file name. */
function notice_test_copies(string $private): array
{
    $inbox = $private . '/cpay-inbox';
    $names = is_dir($inbox) ? preg_grep(PAYMENT_INBOX_PATTERN, scandir($inbox)) : [];
    return array_map(
        static fn (string $name): array => ['file' => $name] + json_decode((string) file_get_contents($inbox . '/' . $name), true),
        array_values($names),
    );
}

/** Puts empty files into the inbox until it holds $total. */
function notice_test_fill_inbox(string $private, int $total): void
{
    $inbox = $private . '/cpay-inbox';
    is_dir($inbox) || mkdir($inbox, 0700);
    for ($i = count(scandir($inbox)) - 2; $i < $total; $i++) {
        file_put_contents(sprintf('%s/seed-%05d.json', $inbox, $i), '{}');
    }
    same($total, count(scandir($inbox)) - 2, 'the inbox is filled');
}

/** The record as it stands in its file. */
function notice_test_stored(string $private, array $record): ?array
{
    return find_payment(payments_dir($private), $record['ref']);
}
