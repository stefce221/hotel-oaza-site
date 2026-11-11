<?php
/* Hotel Oaza — the cPay payment settings in private/config.php (section 'cpay'): which are missing or wrong, and
   their values with the defaults filled in. The rules are section 6 of the design
   (docs/superpowers/specs/2026-11-11-cpay-vcc-charging-design.md). Pure: no files, no globals, nothing logged.
   config_problems() and load_config() never report these settings: a missing key must not stop the enquiry form
   or the keeping of notices. The staff page and the notice handler ask cpay_problems() instead. */
declare(strict_types=1);

const CPAY_HOSTS = ['www.cpay.com.mk', 'vpos.cpay.com.mk'];
const CPAY_DEFAULT_URL = 'https://www.cpay.com.mk/client/Page/default.aspx?xml_id=/mk-MK/.loginToPay/.simple/';
const CPAY_DEFAULT_RETENTION_DAYS = 548;
/* The only checksum this site can make and check; SHA-256 needs cPay's worked example in the tests first (design 3.4). */
const CPAY_DEFAULT_HASH = 'md5';
/* The amount field takes at most seven digits (design 4.1). */
const CPAY_LARGEST_MAX_AMOUNT = 9999999;
const CPAY_RETENTION_DAYS_MIN = 30;
const CPAY_RETENTION_DAYS_MAX = 3650;
/* One host name in lower case: labels of letters, digits and hyphens with dots between them. No port, no user. */
const CPAY_HOST_PATTERN = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*';

/** The 'cpay' section of the config; [] when there is none or it is not a list of settings. */
function cpay_section(#[\SensitiveParameter] array $config): array
{
    return is_array($config['cpay'] ?? null) ? $config['cpay'] : [];
}

/** True for a setting that counts as left out: absent, null or ''. */
function cpay_setting_blank(mixed $value): bool
{
    return $value === null || $value === '';
}

/** True for 1 to 200 of the characters cPay accepts in a merchant name (0-9 A-Z a-z space @ - / ? = &), without "@@"
    and without a space at either end: the name is signed exactly as written, so a stray space breaks every checksum. */
function cpay_merchant_name_ok(mixed $name): bool
{
    return is_string($name)
        && preg_match('~\A[0-9A-Za-z @/?=&-]{1,200}\z~', $name) === 1
        && !str_contains($name, '@@')
        && trim($name) === $name;
}

/** True for a key that is text, not empty, without white space at either end and without a tab, line break or other
    control character anywhere. The owner types it by hand; such a slip would otherwise show only as cPay's error page. */
function cpay_key_ok(#[\SensitiveParameter] mixed $key): bool
{
    return is_string($key)
        && $key !== ''
        && trim($key) === $key
        && preg_match('/[\x00-\x1F\x7F]/', $key) !== 1;
}

/** True for an https address on one of cPay's hosts: no user, no port, and after the host only plain address characters. */
function cpay_payment_url_ok(mixed $url): bool
{
    if (!is_string($url) || strlen($url) > 500) {
        return false;
    }
    $hosts = implode('|', array_map(static fn (string $host): string => preg_quote($host, '~'), CPAY_HOSTS));
    return preg_match('~\Ahttps://(?:' . $hosts . ')(?:/[A-Za-z0-9/._\~?=&%+-]*)?\z~', $url) === 1;
}

/** The host of an address that is exactly https://<host>/cpay/<file>; '' for anything else. */
function cpay_return_host(mixed $url, string $file): string
{
    $pattern = '~\Ahttps://(' . CPAY_HOST_PATTERN . ')/cpay/' . preg_quote($file, '~') . '\z~';
    if (!is_string($url) || preg_match($pattern, $url, $match) !== 1) {
        return '';
    }
    return strlen($match[1]) <= 253 ? $match[1] : '';
}

/**
 * The cPay settings that are missing or wrong, as dotted names (never values), in this order: cpay.merchant_id,
 * cpay.merchant_name, cpay.key, cpay.payment_url, cpay.hash, cpay.ok_url, cpay.fail_url, cpay.max_amount,
 * cpay.retention_days. payment_url, hash and retention_days may be left out (absent, null or '').
 * fail_url must be on the host of ok_url; when ok_url is wrong itself, fail_url is judged by its own shape.
 * In front of them, config_warning for a config.php that raised a warning while it loaded (the mark of load_config()
 * in api/lib/bootstrap.php): a key typed between double quotes with a '$' in it is cut short there and may still
 * look like a key, so no charge is started and no notice is verified with such a file. Its other settings are used
 * as with incomplete settings: push_ips still names the channel, the host of ok_url still guards the staff page and
 * retention_days still sets the pruning (design 6).
 * @return string[]
 */
function cpay_problems(#[\SensitiveParameter] array $config): array
{
    $warned = ($config['config_warning'] ?? false) === true;
    return array_merge($warned ? ['config_warning'] : [], cpay_setting_problems(cpay_section($config)));
}

/** The settings of a 'cpay' section that are missing or wrong, as cpay_problems() names them. */
function cpay_setting_problems(#[\SensitiveParameter] array $cpay): array
{
    $get = static fn (string $name): mixed => $cpay[$name] ?? null;
    $whole = static fn (mixed $value, int $min, int $max): bool => is_int($value) && $value >= $min && $value <= $max;
    $okHost = cpay_return_host($get('ok_url'), 'ok.php');
    $failHost = cpay_return_host($get('fail_url'), 'fail.php');
    $wrong = [
        'merchant_id' => !is_string($get('merchant_id')) || preg_match('/\A[0-9]{1,20}\z/', $get('merchant_id')) !== 1,
        'merchant_name' => !cpay_merchant_name_ok($get('merchant_name')),
        'key' => !cpay_key_ok($get('key')),
        'payment_url' => !cpay_setting_blank($get('payment_url')) && !cpay_payment_url_ok($get('payment_url')),
        'hash' => !cpay_setting_blank($get('hash')) && $get('hash') !== CPAY_DEFAULT_HASH,
        'ok_url' => $okHost === '',
        'fail_url' => $failHost === '' || ($okHost !== '' && $failHost !== $okHost),
        'max_amount' => !$whole($get('max_amount'), 1, CPAY_LARGEST_MAX_AMOUNT),
        'retention_days' => !cpay_setting_blank($get('retention_days'))
            && !$whole($get('retention_days'), CPAY_RETENTION_DAYS_MIN, CPAY_RETENTION_DAYS_MAX),
    ];
    return array_map(static fn (string $name): string => "cpay.$name", array_keys(array_filter($wrong)));
}

/**
 * The settings a charge and a notice need: merchant_id, merchant_name, key, payment_url, hash, ok_url, fail_url,
 * host (the host of ok_url, which is the site's own), max_amount, retention_days; defaults filled in.
 * Throws RuntimeException('cpay_incomplete') when cpay_problems() is not empty.
 * @return array{merchant_id: string, merchant_name: string, key: string, payment_url: string, hash: string,
 *               ok_url: string, fail_url: string, host: string, max_amount: int, retention_days: int}
 */
function cpay_settings(#[\SensitiveParameter] array $config): array
{
    if (cpay_problems($config) !== []) {
        throw new RuntimeException('cpay_incomplete');
    }
    $cpay = cpay_section($config);
    $or = static fn (string $name, mixed $default): mixed => cpay_setting_blank($cpay[$name] ?? null) ? $default : $cpay[$name];
    return [
        'merchant_id' => $cpay['merchant_id'],
        'merchant_name' => $cpay['merchant_name'],
        'key' => $cpay['key'],
        'payment_url' => $or('payment_url', CPAY_DEFAULT_URL),
        'hash' => $or('hash', CPAY_DEFAULT_HASH),
        'ok_url' => $cpay['ok_url'],
        'fail_url' => $cpay['fail_url'],
        'host' => cpay_return_host($cpay['ok_url'], 'ok.php'),
        'max_amount' => $cpay['max_amount'],
        'retention_days' => $or('retention_days', CPAY_DEFAULT_RETENTION_DAYS),
    ];
}
