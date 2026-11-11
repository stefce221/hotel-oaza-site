<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/cpay/lib/charge.php';

/** What cpay_settings() returns, with the specification's sample merchant and its public test key. */
function charge_test_settings(array $change = []): array
{
    return array_merge([
        'merchant_id' => '1000000003',
        'merchant_name' => 'Bookstore',
        'key' => 'TEST_PASS',
        'payment_url' => 'https://www.cpay.com.mk/client/Page/default.aspx?xml_id=/mk-MK/.loginToPay/.simple/',
        'ok_url' => 'https://www.oazahotel.test/cpay/ok.php',
        'fail_url' => 'https://www.oazahotel.test/cpay/fail.php',
    ], $change);
}

/** The message of the InvalidArgumentException that $body throws; '' when it throws none. */
function charge_refusal(callable $body): string
{
    try {
        $body();
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }
    return '';
}

test('charge: amount_problem accepts whole denars typed as digits', function (): void {
    same('', amount_problem('21802', 300000));
    same('', amount_problem('1', 300000));
    same('', amount_problem('300000', 300000), 'the limit itself');
    same('', amount_problem('9999999', 9999999), 'seven digits');
});

test('charge: amount_problem refuses anything that is not 1 to 7 digits without a leading zero', function (): void {
    $bad = [
        'zero' => '0',
        'a leading zero' => '021802',
        'only zeros' => '000',
        'a dot' => '21.802',
        'a comma' => '21802,00',
        'a space before' => ' 21802',
        'a space after' => '21802 ',
        'a line break after' => "21802\n",
        'a NUL after' => "21802\0",
        'empty' => '',
        'a minus' => '-21802',
        'a plus' => '+21802',
        'an exponent' => '2e4',
        'hexadecimal' => '0x55',
        'a letter' => '21802a',
        'full-width digits' => "\u{FF12}\u{FF11}",
        'Arabic-Indic digits' => "\u{0662}\u{0661}",
    ];
    foreach ($bad as $label => $typed) {
        same('amount_format', amount_problem($typed, 300000), $label);
        same('amount_format', amount_problem($typed, 9999999), "$label, with the highest limit");
    }
});

test('charge: amount_problem refuses an amount above the limit', function (): void {
    same('amount_limit', amount_problem('300001', 300000));
    same('amount_limit', amount_problem('9999999', 300000));
    same('amount_limit', amount_problem('2', 1));
    same('amount_limit', amount_problem('1', 0), 'a limit of nothing lets nothing through');
});

test('charge: amount_problem calls digits with a digit too many too large, not mistyped', function (): void {
    /* Staff typed digits only, as asked: the message must say "too large, check for an extra digit". */
    same('amount_limit', amount_problem('12345678', 300000), 'eight digits');
    same('amount_limit', amount_problem('12345678', 9999999), 'eight digits, with the highest limit');
    same('amount_limit', amount_problem('99999999999999999999999', 300000), 'more digits than a number can hold');
    same('amount_format', amount_problem('012345678', 300000), 'a leading zero is still a typing mistake');
    same('amount_format', amount_problem("12345678\n", 300000), 'a line break after is still refused as mistyped');
});

test('charge: valid_reservation takes 6 to 20 digits and nothing else', function (): void {
    foreach (['123456', '4567890123', '012345', str_repeat('9', 20)] as $good) {
        truthy(valid_reservation($good), $good);
    }
    $bad = [
        'empty' => '',
        'five digits' => '12345',
        '21 digits' => str_repeat('9', 21),
        'a letter' => '45678901a3',
        'a space before' => ' 4567890123',
        'a space inside' => '4567 890123',
        'a line break after' => "4567890123\n",
        'a dot' => '4567.890123',
        'a minus' => '-4567890123',
        'markup' => '"><script>',
        'full-width digits' => str_repeat("\u{FF11}", 6),
    ];
    foreach ($bad as $label => $typed) {
        truthy(!valid_reservation($typed), $label);
    }
});

test('charge: Details1 names the reservation', function (): void {
    same('booking 4567890123', details1_for('4567890123'));
});

test('charge: AmountToPay is the whole denars followed by 00', function (): void {
    same('2180200', amount_to_pay(21802));
    same('100', amount_to_pay(1));
    same('999999900', amount_to_pay(9999999));
});

test('charge: format_denars groups thousands with a dot', function (): void {
    same('5', format_denars(5));
    same('1.000', format_denars(1000));
    same('21.802', format_denars(21802));
    same('1.234.567', format_denars(1234567));
    same('999', format_denars(999));
    same('0', format_denars(0));
});

test('charge: charge_request gives the payment address and exactly ten fields in order', function (): void {
    $request = charge_request(charge_test_settings(), 21802, '4567890123', 'TMZK4A7Q2B');
    same(['action', 'fields'], array_keys($request));
    same('https://www.cpay.com.mk/client/Page/default.aspx?xml_id=/mk-MK/.loginToPay/.simple/', $request['action']);
    same(
        [
            'AmountToPay', 'PayToMerchant', 'MerchantName', 'AmountCurrency', 'Details1', 'Details2', 'PaymentOKURL', 'PaymentFailURL',
            'CheckSumHeader', 'CheckSum',
        ],
        array_keys($request['fields']),
    );
    same(array_merge(CPAY_REQUEST_ORDER, ['CheckSumHeader', 'CheckSum']), array_keys($request['fields']));
    same(
        [
            'AmountToPay' => '2180200',
            'PayToMerchant' => '1000000003',
            'MerchantName' => 'Bookstore',
            'AmountCurrency' => 'MKD',
            'Details1' => 'booking 4567890123',
            'Details2' => 'TMZK4A7Q2B',
            'PaymentOKURL' => 'https://www.oazahotel.test/cpay/ok.php',
            'PaymentFailURL' => 'https://www.oazahotel.test/cpay/fail.php',
        ],
        array_slice($request['fields'], 0, 8, true),
    );
    same(
        '08AmountToPay,PayToMerchant,MerchantName,AmountCurrency,Details1,Details2,PaymentOKURL,PaymentFailURL,007010009003018010038040',
        $request['fields']['CheckSumHeader'],
    );
});

test('charge: the checksum of charge_request is confirmed by computing it again', function (): void {
    $fields = charge_request(charge_test_settings(), 21802, '4567890123', 'TMZK4A7Q2B')['fields'];
    $values = '2180200' . '1000000003' . 'Bookstore' . 'MKD' . 'booking 4567890123' . 'TMZK4A7Q2B'
        . 'https://www.oazahotel.test/cpay/ok.php' . 'https://www.oazahotel.test/cpay/fail.php';
    same(strtoupper(md5($fields['CheckSumHeader'] . $values . 'TEST_PASS')), $fields['CheckSum']);
    truthy(preg_match('/\A[0-9A-F]{32}\z/', $fields['CheckSum']) === 1, '32 hex characters, upper case');

    /* The way cPay's answer is checked: the same header and checksum verify the same eight values. */
    $posted = array_slice($fields, 0, 8, true) + ['ReturnCheckSumHeader' => $fields['CheckSumHeader'], 'ReturnCheckSum' => $fields['CheckSum']];
    same(array_slice($fields, 0, 8, true), verified_return($posted, 'TEST_PASS'));
    same(null, verified_return(array_merge($posted, ['AmountToPay' => '2180300']), 'TEST_PASS'), 'another amount does not verify');

    $other = charge_request(charge_test_settings(['key' => 'another key']), 21802, '4567890123', 'TMZK4A7Q2B')['fields'];
    same($fields['CheckSumHeader'], $other['CheckSumHeader']);
    truthy($other['CheckSum'] !== $fields['CheckSum'], 'the key decides the checksum');
});

test('charge: charge_request sends every value as text and never the key', function (): void {
    $request = charge_request(charge_test_settings(), 5, '123456', '0000000000');
    foreach ($request['fields'] as $name => $value) {
        truthy(is_string($value) && $value !== '', "$name is text");
    }
    same('500', $request['fields']['AmountToPay']);
    same('booking 123456', $request['fields']['Details1']);
    truthy(!str_contains(json_encode($request, JSON_THROW_ON_ERROR), 'TEST_PASS'), 'the key is in no field');
});

test('charge: charge_request does not change the settings it is given', function (): void {
    $settings = charge_test_settings(['host' => 'www.oazahotel.test', 'max_amount' => 300000, 'hash' => 'md5']);
    $before = $settings;
    $request = charge_request($settings, 21802, '4567890123', 'TMZK4A7Q2B');
    same($before, $settings);
    same(10, count($request['fields']), 'further settings add no field');
});

test('charge: charge_request takes the lowest and the highest amount', function (): void {
    $amountToPay = static fn (int $denars): string
        => charge_request(charge_test_settings(), $denars, '123456', 'ABCDEFGHIJ')['fields']['AmountToPay'];
    same('100', $amountToPay(1), '1 denar');
    same('999999900', $amountToPay(9999999), '9999999 denars');
});

test('charge: charge_request refuses an amount, reservation or reference of the wrong shape', function (): void {
    $settings = charge_test_settings();
    $bad = [
        'no denars' => [0, '4567890123', 'TMZK4A7Q2B'],
        'a negative amount' => [-21802, '4567890123', 'TMZK4A7Q2B'],
        'eight digits' => [10000000, '4567890123', 'TMZK4A7Q2B'],
        'a reservation with a letter' => [21802, '45678901a3', 'TMZK4A7Q2B'],
        'a reservation with a quote' => [21802, "4567890123'", 'TMZK4A7Q2B'],
        'no reservation' => [21802, '', 'TMZK4A7Q2B'],
        'a short reference' => [21802, '4567890123', 'TMZK4A7Q2'],
        'a long reference' => [21802, '4567890123', 'TMZK4A7Q2BX'],
        'a lower-case reference' => [21802, '4567890123', 'tmzk4a7q2b'],
        'a reference with a line break' => [21802, '4567890123', "TMZK4A7Q2B\n"],
        'a reference with @@' => [21802, '4567890123', 'TMZK@@7Q2B'],
        'no reference' => [21802, '4567890123', ''],
    ];
    foreach ($bad as $label => [$denars, $reservation, $ref]) {
        same('charge_input', charge_refusal(static fn () => charge_request($settings, $denars, $reservation, $ref)), $label);
    }
});

test('charge: charge_request refuses settings that are not complete, by code only', function (): void {
    $request = static fn (array $settings): string => charge_refusal(
        static fn () => charge_request($settings, 21802, '4567890123', 'TMZK4A7Q2B'),
    );
    foreach (['merchant_id', 'merchant_name', 'key', 'payment_url', 'ok_url', 'fail_url'] as $name) {
        same('charge_settings', $request(array_diff_key(charge_test_settings(), [$name => 0])), "no $name");
        same('charge_settings', $request(charge_test_settings([$name => ''])), "an empty $name");
        same('charge_settings', $request(charge_test_settings([$name => ['x']])), "a $name that is not text");
    }
    same('charge_settings', $request(charge_test_settings(['merchant_id' => 1000000003])), 'a merchant number that is a number');
    same('charge_settings', $request([]));
    same('checksum_input', $request(charge_test_settings(['merchant_name' => "Book\xFFstore"])), 'a name that is not UTF-8 cannot be signed');
});
