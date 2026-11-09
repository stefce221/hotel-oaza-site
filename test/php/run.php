<?php
/* Hotel Oaza — a tiny runner for the PHP unit tests: php test/php/run.php
   Loads every test/php/*.test.php; each registers tests with test(). Warnings count as failures. */
declare(strict_types=1);

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$tests = [];

function test(string $name, callable $body): void
{
    $GLOBALS['tests'][$name] = $body;
}

function same(mixed $expected, mixed $actual, string $label = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($label === '' ? '' : "$label: ") . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function truthy(bool $value, string $label): void
{
    if (!$value) {
        throw new RuntimeException("not true: $label");
    }
}

foreach (glob(__DIR__ . '/*.test.php') as $file) {
    require $file;
}

$failed = 0;
foreach ($tests as $name => $body) {
    try {
        $body();
        echo "ok - $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "not ok - $name\n  " . $e->getMessage() . "\n";
    }
}
echo "\n" . count($tests) . " tests, $failed failed\n";
exit($failed === 0 ? 0 : 1);
