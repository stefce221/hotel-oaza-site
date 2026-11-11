<?php
/* Hotel Oaza — a tiny runner for the PHP unit tests: php test/php/run.php [part of a file name]
   Loads every test/php/*.test.php (or only those whose name contains the given part); each registers
   tests with test(). Warnings count as failures. A name is registered once: a second test with it stops the run.
   A test that cannot run here calls skip(), which the summary counts. The environment variable OAZA_PHP_TESTS
   names another folder of test files: test/php.test.mjs tests the runner on its fixtures that way. */
declare(strict_types=1);

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$tests = [];

/** Thrown by skip(): the test cannot run here. */
final class TestSkipped extends Exception
{
}

function test(string $name, callable $body): void
{
    if (array_key_exists($name, $GLOBALS['tests'])) {
        throw new LogicException("two tests are named \"$name\": the second would replace the first");
    }
    $GLOBALS['tests'][$name] = $body;
}

/** Ends the test as skipped, with the reason. */
function skip(string $reason): void
{
    throw new TestSkipped($reason);
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

$only = (string) ($argv[1] ?? '');
if (preg_match('/\A[a-z0-9-]*\z/', $only) !== 1) {
    fwrite(STDERR, "usage: php test/php/run.php [part of a test file name: letters, digits, hyphens]\n");
    exit(2);
}
$folder = getenv('OAZA_PHP_TESTS');
$folder = is_string($folder) && $folder !== '' ? rtrim($folder, '/\\') : __DIR__;
$files = array_values(array_filter(
    glob($folder . '/*.test.php') ?: [],
    static fn (string $file): bool => $only === '' || str_contains(basename($file), $only),
));
if ($files === []) {
    fwrite(STDERR, "no test file matches \"$only\"\n");
    exit(2);
}
foreach ($files as $file) {
    require $file;
}

$failed = 0;
$skipped = 0;
foreach ($tests as $name => $body) {
    try {
        $body();
        echo "ok - $name\n";
    } catch (TestSkipped $e) {
        $skipped++;
        echo "ok - $name # skipped: " . $e->getMessage() . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "not ok - $name\n  " . $e->getMessage() . "\n";
    }
}
echo "\n" . count($tests) . " tests, $failed failed" . ($skipped > 0 ? ", $skipped skipped" : '') . "\n";
exit($failed === 0 ? 0 : 1);
