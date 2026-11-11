<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/cpay/lib/entry.php';

const ENTRY_TEST_SECRET = 'SECRETFRAGMENT';

/** A throw-away private folder, with or without its logs folder. */
function entry_test_private_dir(bool $withLogs): string
{
    $dir = str_replace('\\', '/', sys_get_temp_dir()) . '/oaza-entry-' . bin2hex(random_bytes(6));
    mkdir($withLogs ? $dir . '/logs' : $dir, 0700, true);
    return $dir;
}

function entry_test_remove_dir(string $dir): void
{
    foreach ([$dir . '/logs/php-errors.log', $dir . '/config.php'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    foreach ([$dir . '/logs', $dir] as $folder) {
        if (is_dir($folder)) {
            rmdir($folder);
        }
    }
}

/** A complete config (file transport) for a private folder, as PHP code. */
function entry_test_config_code(string $dir, string $salt = 'test-salt'): string
{
    return '<?php return ' . var_export([
        'private_dir' => $dir,
        'mail' => ['transport' => 'file', 'file' => $dir . '/sent.jsonl', 'from' => 'web@example.mk', 'from_name' => 'Web', 'to' => 'info@example.mk'],
        'rate' => ['limit' => 5, 'window' => 3600, 'salt' => $salt],
    ], true) . ';';
}

/** Runs $body with OAZA_CONFIG naming $path and PHP's own log going to a file; returns [what $body returned, what was logged]. */
function entry_test_with_config(string $path, callable $body): array
{
    $before = getenv('OAZA_CONFIG');
    $log = (string) tempnam(sys_get_temp_dir(), 'oaza-entry-log-');
    putenv('OAZA_CONFIG=' . $path);
    ini_set('error_log', $log);
    try {
        return [$body(), (string) file_get_contents($log)];
    } finally {
        ini_restore('error_log');
        putenv($before === false ? 'OAZA_CONFIG' : 'OAZA_CONFIG=' . $before);
        unlink($log);
    }
}

/** Runs PHP code as a script of its own (an uncaught error only reaches the handler there) and returns what it printed and logged. */
function entry_test_script(string $body): array
{
    $script = (string) tempnam(sys_get_temp_dir(), 'oaza-entry-');
    $log = $script . '.log';
    $library = var_export(dirname(__DIR__, 2) . '/src/cpay/lib/entry.php', true);
    $toLog = var_export($log, true);
    file_put_contents($script, "<?php\ndeclare(strict_types=1);\nini_set('error_log', $toLog);\nrequire $library;\n$body");
    try {
        $process = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        truthy(is_resource($process), 'PHP started');
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        return ['out' => $out, 'err' => $err, 'log' => is_file($log) ? (string) file_get_contents($log) : ''];
    } finally {
        unlink($script);
        if (is_file($log)) {
            unlink($log);
        }
    }
}

/**
 * Runs try_load_config() as a script of its own (only there is it seen what really gets printed), with a complete
 * config after $damage as the config file. Returns what the script printed and logged; on STDERR it says whether
 * it got a config and whether output had started, which is when a status and headers can no longer be sent.
 */
function entry_test_damaged_config(callable $damage): array
{
    $dir = entry_test_private_dir(false);
    $path = $dir . '/config.php';
    file_put_contents($path, $damage(entry_test_config_code($dir, ENTRY_TEST_SECRET)));
    $setPath = 'putenv(' . var_export('OAZA_CONFIG=' . $path, true) . ");\n";
    try {
        return entry_test_script($setPath . <<<'PHP'
$config = try_load_config('test page');
fwrite(STDERR, ($config === null ? 'no config' : 'a config') . (headers_sent() ? ', output has started' : ', nothing sent'));
PHP);
    } finally {
        entry_test_remove_dir($dir);
    }
}

/** True when the log holds exactly these lines (each after PHP's own time stamp) and nothing else. */
function entry_test_logged(array $lines, string $log): bool
{
    $pattern = implode('', array_map(static fn (string $line): string => '\[[^\]]+\] ' . preg_quote($line, '/') . '\R', $lines));
    return preg_match('/\A' . $pattern . '\z/', $log) === 1;
}

test('guard_uncaught logs the class of an uncaught error and nothing else, then answers', function (): void {
    $run = entry_test_script(<<<'PHP'
guard_uncaught('test page', static function (): void {
    echo 'ANSWERED';
});
throw new LogicException('SECRETFRAGMENT');
PHP);
    same('ANSWERED', $run['out']);
    same('', $run['err']);
    truthy(entry_test_logged(['Hotel Oaza test page: uncaught LogicException'], $run['log']), $run['log']);
    truthy(!str_contains(implode('', $run), ENTRY_TEST_SECRET), 'nothing of the message');
});

test('guard_uncaught also catches errors that are not exceptions', function (): void {
    $run = entry_test_script(<<<'PHP'
guard_uncaught('test page', static function (): void {
    echo 'ANSWERED';
});
strlen(['SECRETFRAGMENT']);
PHP);
    same('ANSWERED', $run['out']);
    truthy(entry_test_logged(['Hotel Oaza test page: uncaught TypeError'], $run['log']), $run['log']);
});

test('guard_uncaught does not answer once output has started', function (): void {
    $run = entry_test_script(<<<'PHP'
guard_uncaught('test page', static function (): void {
    echo 'ANSWERED';
});
echo 'half a page';
flush();
throw new RuntimeException('SECRETFRAGMENT');
PHP);
    same('half a page', $run['out']);
    truthy(entry_test_logged(['Hotel Oaza test page: uncaught RuntimeException'], $run['log']), $run['log']);
});

test('guard_uncaught logs only the class when the answer itself fails', function (): void {
    $run = entry_test_script(<<<'PHP'
guard_uncaught('test page', static function (): void {
    throw new DomainException('SECRETFRAGMENT in the answer');
});
throw new LogicException('SECRETFRAGMENT');
PHP);
    same('', $run['out']);
    same('', $run['err']);
    truthy(entry_test_logged([
        'Hotel Oaza test page: uncaught LogicException',
        'Hotel Oaza test page: uncaught DomainException while answering',
    ], $run['log']), $run['log']);
    truthy(!str_contains(implode('', $run), ENTRY_TEST_SECRET), 'nothing of either message');
});

test('try_load_config returns null for a missing file and logs why', function (): void {
    [$config, $log] = entry_test_with_config(__DIR__ . '/no-such-config.php', static fn (): ?array => try_load_config('test page'));
    same(null, $config);
    truthy(entry_test_logged(['Hotel Oaza test page: config_missing'], $log), $log);
});

test('try_load_config returns null for an incomplete config and logs the names, never a value', function (): void {
    $dir = entry_test_private_dir(false);
    $path = $dir . '/config.php';
    file_put_contents($path, '<?php return ' . var_export(['private_dir' => $dir, 'rate' => ['salt' => ENTRY_TEST_SECRET]], true) . ';');
    try {
        [$config, $log] = entry_test_with_config($path, static fn (): ?array => try_load_config('test page'));
        same(null, $config);
        truthy(entry_test_logged(['Hotel Oaza test page: config_incomplete: mail.transport, mail.from, mail.from_name, mail.to'], $log), $log);
    } finally {
        entry_test_remove_dir($dir);
    }
});

test('try_load_config logs a config that does not parse by class and line, without a fragment of it', function (): void {
    $dir = entry_test_private_dir(false);
    $path = $dir . '/config.php';
    file_put_contents($path, "<?php\nreturn ['rate' => ['salt' => 'x' '" . ENTRY_TEST_SECRET . "']];\n");
    try {
        [$config, $log] = entry_test_with_config($path, static fn (): ?array => try_load_config('test page'));
        same(null, $config);
        truthy(entry_test_logged(['Hotel Oaza test page: ParseError on line 2'], $log), $log);
        truthy(!str_contains($log, ENTRY_TEST_SECRET), 'no fragment');
    } finally {
        entry_test_remove_dir($dir);
    }
});

test('try_load_config returns a complete config, logs nothing and leaves PHP\'s log alone on the command line', function (): void {
    $dir = entry_test_private_dir(true);
    $path = $dir . '/config.php';
    file_put_contents($path, entry_test_config_code($dir));
    try {
        [$result, $log] = entry_test_with_config($path, static function (): array {
            $before = ini_get('error_log');
            return [try_load_config('test page'), $before === ini_get('error_log')];
        });
        same($dir, $result[0]['private_dir'] ?? null);
        same('test-salt', $result[0]['rate']['salt'] ?? null);
        same(true, $result[1], 'error_log is unchanged');
        same('', $log);
        truthy(!is_file($dir . '/logs/php-errors.log'), 'no log file was made');
    } finally {
        entry_test_remove_dir($dir);
    }
});

test('try_load_config prints nothing of a config whose opening tag is damaged', function (): void {
    $run = entry_test_damaged_config(static fn (string $code): string => substr($code, 1));
    same('', $run['out'], 'nothing of the file is printed');
    same('no config, nothing sent', $run['err']);
    truthy(entry_test_logged(['Hotel Oaza test page: config_incomplete: config.php must return an array'], $run['log']), $run['log']);
    truthy(!str_contains(implode('', $run), ENTRY_TEST_SECRET), 'no fragment of the file');
});

test('try_load_config discards what a config prints above its opening tag, so a status and headers can still be sent', function (): void {
    $above = ['a byte-order mark' => "\xEF\xBB\xBF", 'a blank line' => "\n", 'a space' => ' '];
    foreach ($above as $label => $bytes) {
        $run = entry_test_damaged_config(static fn (string $code): string => $bytes . $code);
        same('', $run['out'], $label);
        same('a config, nothing sent', $run['err'], $label);
        same('', $run['log'], $label);
    }
});

test('try_load_config prints nothing of a config that ends the request itself', function (): void {
    $run = entry_test_damaged_config(static fn (string $code): string => ENTRY_TEST_SECRET . '<?php exit;');
    same(['out' => '', 'err' => '', 'log' => ''], $run);
});

test('try_load_config leaves output that was already being collected as it was', function (): void {
    $dir = entry_test_private_dir(false);
    $path = $dir . '/config.php';
    file_put_contents($path, ENTRY_TEST_SECRET . entry_test_config_code($dir));
    $level = ob_get_level();
    ob_start();
    try {
        echo 'the page so far';
        [$config] = entry_test_with_config($path, static fn (): ?array => try_load_config('test page'));
        same($level + 1, ob_get_level(), 'the buffer of the caller is still open');
        same('the page so far', ob_get_contents());
        same($dir, $config['private_dir'] ?? null);
    } finally {
        while (ob_get_level() > $level) {
            ob_end_clean();
        }
        entry_test_remove_dir($dir);
    }
});

test('try_load_config points PHP\'s own log at the private folder after a successful load under a web server', function (): void {
    $dir = entry_test_private_dir(true);
    $path = $dir . '/config.php';
    file_put_contents($path, entry_test_config_code($dir));
    try {
        [$result, $log] = entry_test_with_config($path, static fn (): array => [try_load_config('test page', 'litespeed'), ini_get('error_log')]);
        same($dir, $result[0]['private_dir'] ?? null);
        same($dir . '/logs/php-errors.log', $result[1], 'error_log after the load');
        same('', $log, 'nothing was logged');
    } finally {
        entry_test_remove_dir($dir);
    }
});

test('try_load_config leaves PHP\'s own log alone under a web server when the config cannot be loaded', function (): void {
    $dir = entry_test_private_dir(true);
    $path = $dir . '/config.php';
    file_put_contents($path, '<?php return ' . var_export(['private_dir' => $dir], true) . ';');
    try {
        [$result, $log] = entry_test_with_config($path, static function (): array {
            $before = ini_get('error_log');
            return [try_load_config('test page', 'litespeed'), $before === ini_get('error_log')];
        });
        same([null, true], $result, 'no config, and error_log is unchanged');
        truthy(str_contains($log, 'Hotel Oaza test page: config_incomplete: rate.salt, mail.transport'), $log);
        truthy(!is_file($dir . '/logs/php-errors.log'), 'no log file was made');
    } finally {
        entry_test_remove_dir($dir);
    }
});

test('private_php_log does nothing on the command line and under the test server', function (): void {
    $dir = entry_test_private_dir(true);
    $before = ini_get('error_log');
    try {
        same(false, private_php_log($dir, 'cli'));
        same(false, private_php_log($dir, 'cli-server'));
        same(false, private_php_log($dir), 'without a name it is the running PHP, here the command line');
        same($before, ini_get('error_log'), 'error_log is unchanged');
    } finally {
        ini_restore('error_log');
        entry_test_remove_dir($dir);
    }
});

test('private_php_log points PHP\'s own log at <private>/logs/php-errors.log under a web server', function (): void {
    $dir = entry_test_private_dir(true);
    try {
        same(true, private_php_log($dir, 'litespeed'));
        same($dir . '/logs/php-errors.log', ini_get('error_log'));
        error_log('Hotel Oaza test: a line');
        truthy(entry_test_logged(['Hotel Oaza test: a line'], (string) file_get_contents($dir . '/logs/php-errors.log')), 'the line is in the private log');
    } finally {
        ini_restore('error_log');
        entry_test_remove_dir($dir);
    }
});

test('private_php_log makes <private>/logs when it is missing, and leaves the log alone without a private folder', function (): void {
    $dir = entry_test_private_dir(false);
    $before = ini_get('error_log');
    try {
        same(false, private_php_log('', 'litespeed'), 'no private folder at all');
        same(false, private_php_log($dir . '/no-such-folder', 'litespeed'), 'a private folder that does not exist is not made');
        same($before, ini_get('error_log'), 'error_log is unchanged');
        truthy(!is_dir($dir . '/no-such-folder'), 'nothing was made for the missing private folder');
        same(true, private_php_log($dir, 'litespeed'), 'the logs folder is made on first use');
        truthy(is_dir($dir . '/logs'), 'the folder exists now');
        same($dir . '/logs/php-errors.log', ini_get('error_log'));
    } finally {
        ini_restore('error_log');
        entry_test_remove_dir($dir);
    }
});
