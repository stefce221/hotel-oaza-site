<?php
/* Hotel Oaza — cPay's checksum (Merchant integration specification v3.3, MD5): the header that names and
   measures the signed values, the checksum over header, values and the merchant's key, and the check of what
   cPay sends back. Pure: no config, no request, no files. PHP 8.0+, default extensions only. */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/api/lib/validate.php';

/* The order of the merchant's redirect template: the eight request parameters are signed and posted in it. */
const CPAY_REQUEST_ORDER = ['AmountToPay', 'PayToMerchant', 'MerchantName', 'AmountCurrency', 'Details1', 'Details2', 'PaymentOKURL', 'PaymentFailURL'];

/* The header counts its parameters in two digits and each value's characters in three. */
const CHECKSUM_MAX_PAIRS = 99;
const CHECKSUM_MAX_CHARS = 999;
const CHECKSUM_NAME = '/\A[A-Za-z0-9_]+\z/';
/* Count, then names each followed by a comma, then digits. The possessive ++ keeps a long hostile header cheap. */
const CHECKSUM_HEADER = '/\A([0-9]{2})((?:[A-Za-z0-9_]++,)++)([0-9]+)\z/';
const CHECKSUM_SUM = '/\A[0-9A-Fa-f]{32}\z/';

/** The character count of a value that can be signed (text, not empty, valid UTF-8, at most 999 characters); else null. */
function signable_length(mixed $value): ?int
{
    if (!is_string($value) || $value === '') {
        return null;
    }
    $length = char_count($value);
    return $length > CHECKSUM_MAX_CHARS ? null : $length;
}

/**
 * NN + "name," for each + one 3-digit character count per value.
 * @param array<int|string, mixed> $pairs ordered map name => value
 * @throws InvalidArgumentException 'checksum_input' for no pairs, more than 99, a name outside [A-Za-z0-9_]+,
 *         a value that is not a string, is empty, is not valid UTF-8 or has over 999 characters
 */
function checksum_header(array $pairs): string
{
    $count = count($pairs);
    if ($count < 1 || $count > CHECKSUM_MAX_PAIRS) {
        throw new InvalidArgumentException('checksum_input');
    }
    $names = '';
    $lengths = '';
    foreach ($pairs as $name => $value) {
        $length = signable_length($value);
        if ($length === null || preg_match(CHECKSUM_NAME, (string) $name) !== 1) {
            throw new InvalidArgumentException('checksum_input');
        }
        $names .= $name . ',';
        $lengths .= sprintf('%03d', $length);
    }
    return sprintf('%02d', $count) . $names . $lengths;
}

/**
 * Upper-case MD5 of the header, the values in header order and the key, as UTF-8 bytes: what cPay computes.
 * @param string[] $values
 */
function checksum_md5(string $header, array $values, #[\SensitiveParameter] string $key): string
{
    return strtoupper(md5($header . implode('', $values) . $key));
}

/**
 * @param array<int|string, mixed> $pairs ordered map name => value
 * @return array{header: string, checksum: string} the checksum is the upper-case MD5 of header . values . key
 * @throws InvalidArgumentException 'checksum_key' for an empty key, 'checksum_input' as checksum_header() does
 */
function cpay_checksum(array $pairs, #[\SensitiveParameter] string $key): array
{
    if ($key === '') {
        throw new InvalidArgumentException('checksum_key');
    }
    $header = checksum_header($pairs);
    return ['header' => $header, 'checksum' => checksum_md5($header, array_values($pairs), $key)];
}

/**
 * @return array{names: string[], lengths: int[]}|null null when the header is not NN, NN names each followed by a
 *         comma, NN*3 digits, or names a parameter twice. A count of 00 is refused too: nothing would be signed.
 */
function parse_checksum_header(string $header): ?array
{
    if (preg_match(CHECKSUM_HEADER, $header, $match) !== 1) {
        return null;
    }
    $count = (int) $match[1];
    $names = explode(',', substr($match[2], 0, -1));
    if (count($names) !== $count || strlen($match[3]) !== $count * 3 || count(array_unique($names)) !== $count) {
        return null;
    }
    return ['names' => $names, 'lengths' => array_map('intval', str_split($match[3], 3))];
}

/**
 * The signed name => value map, in header order, when ReturnCheckSum matches ReturnCheckSumHeader, its named
 * values and the key; else null. Only what the header names is returned: nothing else that was posted is signed.
 * Every named field must have been posted as text of exactly the length the header gives it (design 4.5): a named
 * field that is absent is refused, also with length 000. Compared with hash_equals, in either letter case.
 * An empty key verifies nothing.
 * @param array<int|string, mixed> $fields what was posted (or the query string)
 * @return array<int|string, string>|null
 */
function verified_return(array $fields, #[\SensitiveParameter] string $key): ?array
{
    $header = $fields['ReturnCheckSumHeader'] ?? null;
    $sum = $fields['ReturnCheckSum'] ?? null;
    if ($key === '' || !is_string($header) || !is_string($sum) || preg_match(CHECKSUM_SUM, $sum) !== 1) {
        return null;
    }
    $parsed = parse_checksum_header($header);
    if ($parsed === null) {
        return null;
    }
    $signed = [];
    foreach ($parsed['names'] as $index => $name) {
        $value = $fields[$name] ?? null;
        if (!is_string($value) || char_count($value) !== $parsed['lengths'][$index]) {
            return null;
        }
        $signed[$name] = $value;
    }
    return hash_equals(checksum_md5($header, array_values($signed), $key), strtoupper($sum)) ? $signed : null;
}
