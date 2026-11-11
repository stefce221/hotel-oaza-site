<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/cpay/lib/checksum.php';

/* The worked examples of cPay's specification (v3.3) and its public test key. No real merchant value belongs here. */
const CHECKSUM_TEST_KEY = 'TEST_PASS';
const CHECKSUM_SAMPLE_ONE_HEADER = '08PaymentOKURL,PaymentFailURL,AmountToPay,AmountCurrency,PayToMerchant,Details1,Details2,MerchantName,'
    . '025027005003010017011009';
const CHECKSUM_SAMPLE_TWO_NAMES = 'PaymentOKURL,PaymentFailURL,AmountToPay,AmountCurrency,PayToMerchant,Details1,Details2,MerchantName,'
    . 'FirstName,LastName,Telephone,Email,Zip,Address,City,Country,OriginalAmount,OriginalCurrency,';
const CHECKSUM_SAMPLE_TWO_LENGTHS = '016018003003010008003014005009011016004007006003002003';
const CHECKSUM_RETURN_HEADER = '19PaymentFailURL,PaymentOKURL,AmountToPay,AmountCurrency,PayToMerchant,Details1,Details2,MerchantName,'
    . 'FirstName,LastName,Telephone,Email,Zip,Address,City,Country,OriginalAmount,OriginalCurrency,cPayPaymentRef,'
    . '018016003003010008003014005009011016004007006003002003006';
const CHECKSUM_RETURN_SUM = '97F4E18E88A48D4BAA1742164A3AFD8B';

/** Sample 1: a request with 8 parameters. */
function checksum_sample_one(): array
{
    return [
        'PaymentOKURL' => 'https://bookstore/ok.html',
        'PaymentFailURL' => 'https://bookstore/fail.html',
        'AmountToPay' => '12300',
        'AmountCurrency' => 'MKD',
        'PayToMerchant' => '1000000003',
        'Details1' => 'purchase of books',
        'Details2' => 'Order 25467',
        'MerchantName' => 'Bookstore',
    ];
}

/** Sample 2: a request with 18 parameters. */
function checksum_sample_two(): array
{
    return [
        'PaymentOKURL' => 'www.OKUrl.com.mk',
        'PaymentFailURL' => 'www.FailUrl.com.mk',
        'AmountToPay' => '100',
        'AmountCurrency' => 'MKD',
        'PayToMerchant' => '1234567890',
        'Details1' => 'Detali 1',
        'Details2' => '123',
        'MerchantName' => 'ImeNaTrgovecot',
        'FirstName' => 'Petar',
        'LastName' => 'Petrevski',
        'Telephone' => '38977777777',
        'Email' => 'petarp@gmail.com',
        'Zip' => '1000',
        'Address' => 'KJP 1/2',
        'City' => 'Skopje',
        'Country' => '807',
        'OriginalAmount' => '10',
        'OriginalCurrency' => 'EUR',
    ];
}

/** Sample 3 as the values cPay signs: sample 2 with the first two swapped, then cPayPaymentRef. */
function checksum_sample_signed(): array
{
    $request = checksum_sample_two();
    $swapped = ['PaymentFailURL' => $request['PaymentFailURL'], 'PaymentOKURL' => $request['PaymentOKURL']];
    return $swapped + array_slice($request, 2, null, true) + ['cPayPaymentRef' => '123456'];
}

/** Sample 3 as the fields cPay posts: the request's fields in the request's order, then what cPay adds. */
function checksum_sample_return(array $change = []): array
{
    $posted = checksum_sample_two() + [
        'cPayPaymentRef' => '123456',
        'ReturnCheckSumHeader' => CHECKSUM_RETURN_HEADER,
        'ReturnCheckSum' => CHECKSUM_RETURN_SUM,
    ];
    return array_merge($posted, $change);
}

/** The message of the InvalidArgumentException that $body throws; '' when it throws none. */
function checksum_refusal(callable $body): string
{
    try {
        $body();
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }
    return '';
}

/** $count pairs named P1, P2, … with the value x. */
function checksum_many_pairs(int $count): array
{
    return array_fill_keys(array_map(static fn (int $i): string => "P$i", range(1, $count)), 'x');
}

test('checksum: the request order is the one of the merchant\'s redirect template', function (): void {
    same(
        ['AmountToPay', 'PayToMerchant', 'MerchantName', 'AmountCurrency', 'Details1', 'Details2', 'PaymentOKURL', 'PaymentFailURL'],
        CPAY_REQUEST_ORDER,
    );
});

test('checksum: sample 1 of the specification (8 parameters)', function (): void {
    same(CHECKSUM_SAMPLE_ONE_HEADER, checksum_header(checksum_sample_one()));
    same(
        ['header' => CHECKSUM_SAMPLE_ONE_HEADER, 'checksum' => '34F2872495067872C7D11C4D0F6A3DE2'],
        cpay_checksum(checksum_sample_one(), CHECKSUM_TEST_KEY),
    );
});

test('checksum: sample 2 of the specification (18 parameters)', function (): void {
    $header = '18' . CHECKSUM_SAMPLE_TWO_NAMES . CHECKSUM_SAMPLE_TWO_LENGTHS;
    same($header, checksum_header(checksum_sample_two()));
    same(
        ['header' => $header, 'checksum' => '1AEB4E68DCF02D51C54A269EC26D94DB'],
        cpay_checksum(checksum_sample_two(), CHECKSUM_TEST_KEY),
    );
});

test('checksum: sample 3 of the specification (the return checksum)', function (): void {
    $signed = cpay_checksum(checksum_sample_signed(), CHECKSUM_TEST_KEY);
    truthy(str_starts_with($signed['header'], '19PaymentFailURL,PaymentOKURL,'), 'the first two names are swapped');
    truthy(
        str_ends_with($signed['header'], 'OriginalCurrency,cPayPaymentRef,018016003003010008003014005009011016004007006003002003006'),
        'cPayPaymentRef is appended, then the lengths',
    );
    same(['header' => CHECKSUM_RETURN_HEADER, 'checksum' => CHECKSUM_RETURN_SUM], $signed);
});

test('checksum: lengths count characters, not bytes', function (): void {
    same('01Details1,011', checksum_header(['Details1' => 'Тёст Strîñğ']));
    same('01Details1,011', checksum_header(['Details1' => "\u{0422}\u{0451}\u{0441}\u{0442} Str\u{00EE}\u{00F1}\u{011F}"]));
    same('01Details1,001', checksum_header(['Details1' => "\u{1F600}"]), 'one character outside the basic plane');
    $signed = cpay_checksum(['Details1' => 'Тёст'], CHECKSUM_TEST_KEY);
    same(strtoupper(md5('01Details1,004' . 'Тёст' . CHECKSUM_TEST_KEY)), $signed['checksum'], 'the bytes hashed are UTF-8');
});

test('checksum: the header for the eight request parameters in the request order', function (): void {
    $sample = checksum_sample_one();
    $pairs = [];
    foreach (CPAY_REQUEST_ORDER as $name) {
        $pairs[$name] = $sample[$name];
    }
    same(
        '08AmountToPay,PayToMerchant,MerchantName,AmountCurrency,Details1,Details2,PaymentOKURL,PaymentFailURL,005010009003017011025027',
        checksum_header($pairs),
    );
});

test('checksum: a header counts at most 99 parameters and 999 characters, and a name may be all digits', function (): void {
    truthy(str_starts_with(checksum_header(checksum_many_pairs(99)), '99P1,P2,'), '99 pairs');
    same('01Details1,999', checksum_header(['Details1' => str_repeat('ж', 999)]));
    same('01123,001', checksum_header(['123' => 'x']), 'PHP turns the name 123 into a number; the header still names it');
    same('02Under_score,a1,001001', checksum_header(['Under_score' => 'x', 'a1' => 'y']));
});

test('checksum: checksum_header refuses what cannot be signed', function (): void {
    $bad = [
        'no pairs' => [],
        '100 pairs' => checksum_many_pairs(100),
        'an empty name' => ['' => 'x'],
        'a name with a comma' => ['A,B' => 'x'],
        'a name with a space' => ['Details 1' => 'x'],
        'a name with a hyphen' => ['Details-1' => 'x'],
        'a Cyrillic name' => ['Име' => 'x'],
        'a name that ends with a line break' => ["Details1\n" => 'x'],
        'a number as value' => ['AmountToPay' => 12300],
        'null as value' => ['Details1' => null],
        'a list as value' => ['Details1' => ['x']],
        'an empty value' => ['Details1' => ''],
        'an empty value among good ones' => ['AmountToPay' => '100', 'Details1' => '', 'Details2' => 'x'],
        'a value that is not UTF-8' => ['Details1' => "caf\xE9"],
        '1000 characters' => ['Details1' => str_repeat('ж', 1000)],
    ];
    foreach ($bad as $label => $pairs) {
        same('checksum_input', checksum_refusal(static fn () => checksum_header($pairs)), $label);
        same('checksum_input', checksum_refusal(static fn () => cpay_checksum($pairs, CHECKSUM_TEST_KEY)), "cpay_checksum, $label");
    }
});

test('checksum: cpay_checksum needs a key, and the key decides the checksum', function (): void {
    same('checksum_key', checksum_refusal(static fn () => cpay_checksum(checksum_sample_one(), '')));
    $other = cpay_checksum(checksum_sample_one(), 'test_pass');
    same(CHECKSUM_SAMPLE_ONE_HEADER, $other['header']);
    truthy(preg_match('/\A[0-9A-F]{32}\z/', $other['checksum']) === 1, '32 hex characters, upper case');
    truthy($other['checksum'] !== '34F2872495067872C7D11C4D0F6A3DE2', 'another key gives another checksum');
});

test('checksum: parse_checksum_header reads count, names and lengths', function (): void {
    same(
        [
            'names' => ['PaymentOKURL', 'PaymentFailURL', 'AmountToPay', 'AmountCurrency', 'PayToMerchant', 'Details1', 'Details2', 'MerchantName'],
            'lengths' => [25, 27, 5, 3, 10, 17, 11, 9],
        ],
        parse_checksum_header(CHECKSUM_SAMPLE_ONE_HEADER),
    );
    $return = parse_checksum_header(CHECKSUM_RETURN_HEADER);
    same(19, count($return['names']));
    same(['PaymentFailURL', 'PaymentOKURL'], array_slice($return['names'], 0, 2));
    same('cPayPaymentRef', $return['names'][18]);
    same(6, $return['lengths'][18]);
    same(['names' => ['A'], 'lengths' => [0]], parse_checksum_header('01A,000'));
    same(['names' => ['12', 'a_1'], 'lengths' => [999, 1]], parse_checksum_header('0212,a_1,999001'), 'a name of digits');
    same(checksum_header(checksum_many_pairs(99)), checksum_header(array_combine(
        parse_checksum_header(checksum_header(checksum_many_pairs(99)))['names'],
        array_fill(0, 99, 'x'),
    )), 'what checksum_header writes, parse_checksum_header reads');
});

test('checksum: parse_checksum_header refuses a header of another shape', function (): void {
    $bad = [
        'empty' => '',
        'a count only' => '02',
        'a count of zero' => '00',
        'no count' => 'A,B,001002',
        'a count of one digit' => '2A,B,001002',
        'a count that is not ASCII digits' => "\u{0660}\u{0662}A,B,001002",
        'a count above the names' => '03A,B,001002',
        'a count below the names' => '01A,B,001002',
        'a count above the names, lengths to match' => '03A,B,001002003',
        'a missing trailing comma' => '02A,B001002',
        'no comma at all' => '01A001',
        'a name twice' => '02A,A,001002',
        'a name twice among others' => '03A,B,A,001002003',
        'a count below the names, hidden by a repeated name' => '01A,A,001',
        'an empty name' => '02A,,001002',
        'a name with a space' => '02A, B,001002',
        'a name with a hyphen' => '02A,B-C,001002',
        'a letter among the lengths' => '02A,B,00100x',
        'a letter at the start of the lengths' => '02A,B,x01002',
        'a sign among the lengths' => '02A,B,001+02',
        'a space among the lengths' => '02A,B,001 02',
        'too few lengths' => '02A,B,001',
        'too many lengths' => '02A,B,001002003',
        'lengths that are not threes' => '02A,B,00100',
        'no lengths' => '02A,B,',
        'a line break at the end' => "02A,B,001002\n",
        'a line break at the start' => "\n02A,B,001002",
    ];
    foreach ($bad as $label => $header) {
        same(null, parse_checksum_header($header), $label);
    }
});

test('checksum: verified_return accepts sample 3 in either letter case and returns the signed values in header order', function (): void {
    $signed = checksum_sample_signed();
    same($signed, verified_return(checksum_sample_return(), CHECKSUM_TEST_KEY));
    same(array_keys($signed), array_keys(verified_return(checksum_sample_return(), CHECKSUM_TEST_KEY)), 'header order');
    same($signed, verified_return(checksum_sample_return(['ReturnCheckSum' => strtolower(CHECKSUM_RETURN_SUM)]), CHECKSUM_TEST_KEY), 'lower case');
    same($signed, verified_return(checksum_sample_return(['ReturnCheckSum' => '97f4e18e88a48d4bAA1742164A3AFD8B']), CHECKSUM_TEST_KEY), 'mixed case');
});

test('checksum: verified_return returns only the fields the header names', function (): void {
    $posted = checksum_sample_return([
        'ServiceID' => '',
        'Details3' => 'not signed',
        'CheckSum' => '1AEB4E68DCF02D51C54A269EC26D94DB',
        'CheckSumHeader' => '18' . CHECKSUM_SAMPLE_TWO_NAMES . CHECKSUM_SAMPLE_TWO_LENGTHS,
        'iseCollect' => ['a list'],
    ]);
    $result = verified_return($posted, CHECKSUM_TEST_KEY);
    same(checksum_sample_signed(), $result);
    foreach (['ServiceID', 'Details3', 'CheckSum', 'CheckSumHeader', 'iseCollect', 'ReturnCheckSum', 'ReturnCheckSumHeader'] as $name) {
        truthy(!array_key_exists($name, $result), "$name is not in the result");
    }
});

test('checksum: verified_return refuses a changed value', function (): void {
    $changes = [
        'another amount of the same length' => ['AmountToPay' => '900'],
        'another reference' => ['Details2' => '124'],
        'another payment reference' => ['cPayPaymentRef' => '123457'],
        'a character moved from one value to the next' => ['Details1' => 'Detali ', 'Details2' => '1123'],
        'two values of one length exchanged' => ['Country' => 'EUR', 'OriginalCurrency' => '807'],
        'a value in another letter case' => ['City' => 'skopje'],
        'a value that is not UTF-8' => ['City' => "Skopj\xE9"],
    ];
    foreach ($changes as $label => $change) {
        same(null, verified_return(checksum_sample_return($change), CHECKSUM_TEST_KEY), $label);
    }
});

test('checksum: verified_return refuses a changed length', function (): void {
    $longer = substr(CHECKSUM_RETURN_HEADER, 0, -3) . '007';
    $shorter = substr(CHECKSUM_RETURN_HEADER, 0, -3) . '005';
    same(null, verified_return(checksum_sample_return(['ReturnCheckSumHeader' => $longer]), CHECKSUM_TEST_KEY), 'a length above the value');
    same(null, verified_return(checksum_sample_return(['ReturnCheckSumHeader' => $shorter]), CHECKSUM_TEST_KEY), 'a length below the value');
    same(
        null,
        verified_return(checksum_sample_return(['ReturnCheckSumHeader' => $longer, 'cPayPaymentRef' => '1234567']), CHECKSUM_TEST_KEY),
        'a length and its value changed together',
    );
});

test('checksum: verified_return takes a value only at its length in characters, also when the checksum fits', function (): void {
    $values = ['Details1' => 'Тёст Strîñğ', 'Details2' => 'ABC'];
    $signed = cpay_checksum($values, CHECKSUM_TEST_KEY);
    same('02Details1,Details2,011003', $signed['header']);
    $posted = $values + ['ReturnCheckSumHeader' => $signed['header'], 'ReturnCheckSum' => $signed['checksum']];
    same($values, verified_return($posted, CHECKSUM_TEST_KEY), '11 characters in 18 bytes');

    /* A header that gives Details1 another length, with the checksum computed over that header: only the length is wrong. */
    $withLength = static function (string $length) use ($values): ?array {
        $header = '02Details1,Details2,' . $length . '003';
        $sum = strtoupper(md5($header . implode('', $values) . CHECKSUM_TEST_KEY));
        return verified_return($values + ['ReturnCheckSumHeader' => $header, 'ReturnCheckSum' => $sum], CHECKSUM_TEST_KEY);
    };
    same($values, $withLength('011'), 'the characters');
    same(18, strlen($values['Details1']));
    same(null, $withLength('018'), 'the bytes');
    same(null, $withLength('010'), 'one character less');
});

test('checksum: verified_return refuses a named field that is missing or is not text', function (): void {
    foreach (['cPayPaymentRef', 'Zip', 'PaymentFailURL'] as $name) {
        $posted = checksum_sample_return();
        unset($posted[$name]);
        same(null, verified_return($posted, CHECKSUM_TEST_KEY), "$name is missing");
    }
    same(null, verified_return(checksum_sample_return(['Zip' => ['1000']]), CHECKSUM_TEST_KEY), 'a list instead of text');
    same(null, verified_return(checksum_sample_return(['Zip' => '']), CHECKSUM_TEST_KEY), 'an empty value for a length of 4');
});

test('checksum: verified_return takes an empty value only when it was posted and the header gives it 000', function (): void {
    $header = '02Details3,Details2,000003';
    $sum = strtoupper(md5($header . 'ABC' . CHECKSUM_TEST_KEY));
    $posted = ['Details3' => '', 'Details2' => 'ABC', 'ReturnCheckSumHeader' => $header, 'ReturnCheckSum' => $sum];
    same(['Details3' => '', 'Details2' => 'ABC'], verified_return($posted, CHECKSUM_TEST_KEY));
    $absent = ['Details2' => 'ABC', 'ReturnCheckSumHeader' => $header, 'ReturnCheckSum' => $sum];
    same(null, verified_return($absent, CHECKSUM_TEST_KEY), 'design 4.5: every named field is a posted string');
});

test('checksum: verified_return refuses an empty or another key', function (): void {
    same(null, verified_return(checksum_sample_return(), ''));
    same(null, verified_return(checksum_sample_return(), 'test_pass'));
    same(null, verified_return(checksum_sample_return(), CHECKSUM_TEST_KEY . ' '));
    $keyless = strtoupper(md5(CHECKSUM_RETURN_HEADER . implode('', checksum_sample_signed())));
    same(null, verified_return(checksum_sample_return(['ReturnCheckSum' => $keyless]), ''), 'a checksum made without a key verifies nothing');
});

test('checksum: verified_return refuses a checksum of another shape', function (): void {
    $bad = [
        'one character short' => substr(CHECKSUM_RETURN_SUM, 0, 31),
        'one character long' => CHECKSUM_RETURN_SUM . '0',
        'empty' => '',
        'spaces around it' => ' ' . CHECKSUM_RETURN_SUM . ' ',
        'a line break after it' => CHECKSUM_RETURN_SUM . "\n",
        'one digit changed' => '07F4E18E88A48D4BAA1742164A3AFD8B',
        'not hex' => str_repeat('Z', 32),
        'a list' => [CHECKSUM_RETURN_SUM],
    ];
    foreach ($bad as $label => $sum) {
        same(null, verified_return(checksum_sample_return(['ReturnCheckSum' => $sum]), CHECKSUM_TEST_KEY), $label);
    }
    $posted = checksum_sample_return();
    unset($posted['ReturnCheckSum']);
    same(null, verified_return($posted, CHECKSUM_TEST_KEY), 'no checksum');
});

test('checksum: verified_return refuses a header that is missing or does not parse', function (): void {
    $posted = checksum_sample_return();
    unset($posted['ReturnCheckSumHeader']);
    same(null, verified_return($posted, CHECKSUM_TEST_KEY), 'no header');
    same(null, verified_return(checksum_sample_return(['ReturnCheckSumHeader' => [CHECKSUM_RETURN_HEADER]]), CHECKSUM_TEST_KEY), 'a list');
    same(null, verified_return(checksum_sample_return(['ReturnCheckSumHeader' => '']), CHECKSUM_TEST_KEY), 'empty');
    same(null, verified_return(checksum_sample_return(['ReturnCheckSumHeader' => '20' . substr(CHECKSUM_RETURN_HEADER, 2)]), CHECKSUM_TEST_KEY), 'a wrong count');
    same(null, verified_return([], CHECKSUM_TEST_KEY), 'no fields');

    $twice = '02Details2,Details2,003003';
    $sum = strtoupper(md5($twice . 'ABCABC' . CHECKSUM_TEST_KEY));
    same(
        null,
        verified_return(['Details2' => 'ABC', 'ReturnCheckSumHeader' => $twice, 'ReturnCheckSum' => $sum], CHECKSUM_TEST_KEY),
        'a name twice, even with a checksum that fits',
    );
});
