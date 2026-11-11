<?php
/* What both staff entry points do around their answer (staff_run()): a failure of the site says that nothing was
   started, whether it has one of this site's codes or reaches the handler for uncaught errors. Each case runs in a
   PHP process of its own, since only there does an uncaught error reach the handler. */
declare(strict_types=1);

require_once __DIR__ . '/staff-helpers.php';

const STAFF_RUN_TEST_FAILED = 'Наплатата не е започната. Пробајте повторно за една минута. Ако се повтори, јавете му се на сопственикот.';
const STAFF_RUN_TEST_NOT_SET_UP = 'Наплатата не е поставена до крај. Јавете му се на сопственикот.';

/**
 * Runs staff_run(['GET'], <$answer>) for a GET in a PHP process of its own, with a config that loads (for $private).
 * $answer is the PHP code of the function that decides the answer. Returns what was printed, the status the
 * process ended with and what PHP logged.
 * @return array{page: string, status: string, php_log: string}
 */
function staff_run_test(string $private, string $answer): array
{
    $mail = ['transport' => 'file', 'file' => $private . '/sent.jsonl', 'from' => 'web@example.mk', 'from_name' => 'Web', 'to' => 'info@example.mk'];
    file_put_contents($private . '/config.php', '<?php return ' . var_export(staff_test_config($private) + ['mail' => $mail], true) . ';');
    $log = $private . '/php.log';
    $script = $private . '/run.php';
    file_put_contents($script, implode("\n", [
        '<?php',
        'declare(strict_types=1);',
        'ini_set(\'error_log\', ' . var_export($log, true) . ');',
        'putenv(' . var_export('OAZA_CONFIG=' . $private . '/config.php', true) . ');',
        '$_SERVER[\'REQUEST_METHOD\'] = \'GET\';',
        'register_shutdown_function(static function (): void { fwrite(STDERR, (string) http_response_code()); });',
        'require ' . var_export(dirname(__DIR__, 2) . '/src/cpay/lib/staff-app.php', true) . ';',
        'staff_run([\'GET\'], ' . $answer . ');',
    ]));
    $process = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    truthy(is_resource($process), 'PHP started');
    $page = (string) stream_get_contents($pipes[1]);
    $status = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    return ['page' => $page, 'status' => $status, 'php_log' => is_file($log) ? (string) file_get_contents($log) : ''];
}

test('staff: an uncaught error answers 500, says that nothing was started and logs its class only', function (): void {
    if (!function_exists('proc_open')) {
        skip('no second process can be started here');
    }
    staff_test_private(static function (string $private): void {
        $run = staff_run_test($private, 'static function (array $config, int $now): array { throw new TypeError(\'a value: 4567890123\'); }');
        same('500', $run['status']);
        truthy(str_contains($run['page'], STAFF_RUN_TEST_FAILED) && !str_contains($run['page'], STAFF_RUN_TEST_NOT_SET_UP), 'the site failed; the setup is not to blame');
        truthy(!str_contains($run['page'], '<form'), 'nothing to send');
        truthy(str_contains($run['php_log'], 'Hotel Oaza staff page: uncaught TypeError'), $run['php_log']);
        truthy(!str_contains($run['php_log'], '4567890123'), 'never the message');
    });
});

test('staff: a failure with one of the site\'s codes answers 500, says that nothing was started and logs the code', function (): void {
    if (!function_exists('proc_open')) {
        skip('no second process can be started here');
    }
    foreach (['staff_lock', 'payments_write', 'staff_secret', 'staff_reference'] as $code) {
        staff_test_private(static function (string $private) use ($code): void {
            $run = staff_run_test($private, 'static function (array $config, int $now): array { throw new RuntimeException(' . var_export($code, true) . '); }');
            same('500', $run['status'], $code);
            truthy(str_contains($run['page'], STAFF_RUN_TEST_FAILED) && !str_contains($run['page'], STAFF_RUN_TEST_NOT_SET_UP), $code . ': nothing was started');
            truthy(str_contains(staff_test_log($private), ' staff_failed ' . $code), $code . ': logged by its code');
            same('', $run['php_log'], $code . ': nothing reached the handler for uncaught errors');
        });
    }
});
