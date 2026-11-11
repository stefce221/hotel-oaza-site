<?php
/* Hotel Oaza — what one charge sends to cPay: the checks on the typed amount and reservation number, and the ten
   fields of the request form (design 3.1). Pure: no config file, no request, no files. PHP 8.0+, default extensions only. */
declare(strict_types=1);

require_once __DIR__ . '/checksum.php';

const CPAY_CURRENCY = 'MKD';
/* Whole denars as typed: 1 to 7 digits, no leading zero. Nothing is trimmed, cast or rounded before this check. */
const CHARGE_AMOUNT = '/\A[1-9][0-9]{0,6}\z/';
/* Typed as asked (digits only, no leading zero) but with a digit too many: too large, not mistyped. */
const CHARGE_AMOUNT_TOO_LONG = '/\A[1-9][0-9]{7,}\z/';
const CHARGE_MAX_DENARS = 9999999;
const CHARGE_RESERVATION = '/\A[0-9]{6,20}\z/';
/* Details2 carries the payment's reference: 10 characters of 0-9 and A-Z. */
const CHARGE_REFERENCE = '/\A[0-9A-Z]{10}\z/';
const CHARGE_DETAILS1_PREFIX = 'booking ';
/* The settings a request needs (from cpay_settings()); each must be text and not empty. */
const CHARGE_SETTINGS = ['merchant_id', 'merchant_name', 'key', 'payment_url', 'ok_url', 'fail_url'];

/**
 * '' | 'amount_format' | 'amount_limit'. Digits only, 1 to 7 of them, no leading zero, at most $max.
 * 'amount_limit' for more than $max and for digits with a digit too many (staff typed it the right way, it is
 * only too large); 'amount_format' for everything else, also for 0. Never trimmed or cast before the check.
 */
function amount_problem(string $typed, int $max): string
{
    if (preg_match(CHARGE_AMOUNT, $typed) === 1) {
        return (int) $typed > $max ? 'amount_limit' : '';
    }
    return preg_match(CHARGE_AMOUNT_TOO_LONG, $typed) === 1 ? 'amount_limit' : 'amount_format';
}

/** 6 to 20 digits and nothing else. */
function valid_reservation(string $typed): bool
{
    return preg_match(CHARGE_RESERVATION, $typed) === 1;
}

/** Details1 of the request: 'booking <reservation number>'. */
function details1_for(string $reservation): string
{
    return CHARGE_DETAILS1_PREFIX . $reservation;
}

/** AmountToPay of the request: whole denars followed by 00 (21802 -> '2180200'). */
function amount_to_pay(int $denars): string
{
    return $denars . '00';
}

/** Denars as staff read them: 21802 -> '21.802'. */
function format_denars(int $denars): string
{
    return number_format($denars, 0, ',', '.');
}

/** True when every setting a request needs is text and not empty. */
function charge_settings_complete(#[\SensitiveParameter] array $settings): bool
{
    foreach (CHARGE_SETTINGS as $name) {
        if (!is_string($settings[$name] ?? null) || $settings[$name] === '') {
            return false;
        }
    }
    return true;
}

/**
 * The form that starts one payment at cPay.
 * @param array<string, mixed> $settings merchant_id, merchant_name, key, payment_url, ok_url, fail_url (cpay_settings())
 * @return array{action: string, fields: array<string, string>} action is the payment address; fields are the eight
 *         parameters in CPAY_REQUEST_ORDER, then CheckSumHeader and CheckSum: exactly what the form posts
 * @throws InvalidArgumentException 'charge_input' for an amount outside 1 to 9999999 denars, a reservation number
 *         that is not valid_reservation() or a reference that is not 10 characters of 0-9 and A-Z;
 *         'charge_settings' for a setting that is missing, empty or not text; 'checksum_input' for a setting
 *         that cannot be signed
 */
function charge_request(#[\SensitiveParameter] array $settings, int $denars, string $reservation, string $ref): array
{
    $inRange = $denars >= 1 && $denars <= CHARGE_MAX_DENARS;
    if (!$inRange || !valid_reservation($reservation) || preg_match(CHARGE_REFERENCE, $ref) !== 1) {
        throw new InvalidArgumentException('charge_input');
    }
    if (!charge_settings_complete($settings)) {
        throw new InvalidArgumentException('charge_settings');
    }
    $values = [
        'AmountToPay' => amount_to_pay($denars),
        'PayToMerchant' => $settings['merchant_id'],
        'MerchantName' => $settings['merchant_name'],
        'AmountCurrency' => CPAY_CURRENCY,
        'Details1' => details1_for($reservation),
        'Details2' => $ref,
        'PaymentOKURL' => $settings['ok_url'],
        'PaymentFailURL' => $settings['fail_url'],
    ];
    $pairs = [];
    foreach (CPAY_REQUEST_ORDER as $name) {
        $pairs[$name] = $values[$name];
    }
    $signed = cpay_checksum($pairs, $settings['key']);
    return [
        'action' => $settings['payment_url'],
        'fields' => $pairs + ['CheckSumHeader' => $signed['header'], 'CheckSum' => $signed['checksum']],
    ];
}
