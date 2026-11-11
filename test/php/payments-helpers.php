<?php
/* Helpers shared by the tests of the record store (payments.test.php, payments-lock.test.php). */
declare(strict_types=1);

require_once __DIR__ . '/../../src/cpay/lib/payments.php';

/* 2026-11-11 10:00 UTC (11:00 in Skopje): a minute later is the same day in either zone. */
const PAYMENTS_TEST_NOW = 1794391200;
const PAYMENTS_TEST_URL = 'https://www.cpay.com.mk/client/Page/default.aspx?xml_id=/mk-MK/.loginToPay/.simple/';

/** Removes a file, or a folder with everything in it. */
function payments_test_remove(string $path): void
{
    if (is_dir($path)) {
        foreach (array_diff(scandir($path), ['.', '..']) as $name) {
            payments_test_remove($path . '/' . $name);
        }
        rmdir($path);
        return;
    }
    if (is_file($path)) {
        unlink($path);
    }
}

/** Runs $body with a throw-away private folder and removes the folder afterwards, whatever happened. */
function payments_test_private(callable $body): void
{
    $private = sys_get_temp_dir() . '/oaza-payments-' . bin2hex(random_bytes(6));
    mkdir($private, 0700);
    try {
        $body($private);
    } finally {
        payments_test_remove($private);
    }
}

/** A waiting record whose reference was issued, and which was started, at $time. */
function payments_test_record(int $time, string $reservation = '4567890123', string $tail = 'AAAA', int $amount = 21802): array
{
    $ref = substr(new_reference($time), 0, 6) . $tail;
    return new_payment($ref, 'recepcija', $amount, $reservation, 'booking ' . $reservation, PAYMENTS_TEST_URL, 'md5', $time);
}

/** The names in a folder, sorted. */
function payments_test_names(string $dir): array
{
    $names = array_values(array_diff(scandir($dir), ['.', '..']));
    sort($names, SORT_STRING);
    return $names;
}

/** The message of the $class exception that $body throws; fails when it throws nothing or something else. */
function payments_test_thrown(string $class, callable $body): string
{
    try {
        $body();
    } catch (Throwable $e) {
        truthy($e instanceof $class, "throws $class, not " . get_class($e) . ' (' . $e->getMessage() . ')');
        return $e->getMessage();
    }
    throw new RuntimeException("nothing was thrown, expected $class");
}

/** The references of some records, in their order. */
function payments_test_refs(array $records): array
{
    return array_map(static fn (array $record): string => $record['ref'], $records);
}
