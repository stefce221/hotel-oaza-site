<?php
/* The raw copies of what reaches the cPay addresses (src/cpay/lib/inbox.php): what happens when the hourly counter
   cannot be used. The limits themselves are tested in notice.test.php. */
declare(strict_types=1);

require_once __DIR__ . '/../../src/cpay/lib/inbox.php';

/** Removes a file, or a folder with everything in it. */
function inbox_test_remove(string $path): void
{
    if (is_dir($path)) {
        foreach (array_diff(scandir($path), ['.', '..']) as $name) {
            inbox_test_remove($path . '/' . $name);
        }
        rmdir($path);
        return;
    }
    if (is_file($path)) {
        unlink($path);
    }
}

test('inbox: a rate counter that cannot be used keeps the copy and logs cpay_rate_unavailable, never a value', function (): void {
    $private = str_replace('\\', '/', sys_get_temp_dir()) . '/oaza-inbox-' . bin2hex(random_bytes(6));
    mkdir($private . '/cpay-ratelimit.json', 0700, true); /* a folder where the counter's file should be */
    try {
        $config = ['private_dir' => $private, 'rate' => ['limit' => 5, 'window' => 3600, 'salt' => 'test-salt'], 'cpay' => []];
        save_cpay_notice($config, 'ok', ['Details2' => 'TMZK4A7Q2B', 'note' => 'a value'], 'browser', false);
        same(1, count(array_diff(scandir($private . '/cpay-inbox'), ['.', '..'])), 'the copy is kept: a broken counter must not drop a payment notice');
        $log = (string) file_get_contents($private . '/logs/errors.log');
        truthy(str_contains($log, ' cpay_rate_unavailable'), 'and the broken counter is logged by its code: ' . $log);
        truthy(!str_contains($log, 'TMZK4A7Q2B') && !str_contains($log, 'a value'), 'never what was sent');
    } finally {
        inbox_test_remove($private);
    }
});
