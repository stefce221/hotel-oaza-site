<?php
declare(strict_types=1);

/* A config.php that raises a warning while it loads: the owner typed the key between double quotes and it holds a
   '$'. PHP would write "Undefined variable $<rest of the key>" into its own log, which is still the server's at that
   moment, and cut the key short. load_config() swallows such warnings and marks the config; cPay then counts as not
   set up, and the enquiry form goes on (design 6). */
require_once __DIR__ . '/notice-helpers.php';

/* A complete config for the private folder PRIVATE_DIR, with the cPay key written as KEY (PHP code). */
const CONFIG_WARNING_TEST_CODE = <<<'PHP'
<?php
return [
    'private_dir' => PRIVATE_DIR,
    'mail' => ['transport' => 'file', 'file' => PRIVATE_DIR . '/sent.jsonl', 'from' => 'web@example.mk', 'from_name' => 'Web', 'to' => 'info@example.mk'],
    'rate' => ['limit' => 5, 'window' => 3600, 'salt' => 'test-salt'],
    'cpay' => [
        'push_ips' => ['203.0.113.9'], 'merchant_id' => '1000000003', 'merchant_name' => 'Bookstore', 'key' => KEY,
        'ok_url' => 'https://www.oazahotel.test/cpay/ok.php', 'fail_url' => 'https://www.oazahotel.test/cpay/fail.php', 'max_amount' => 300000,
    ],
];
PHP;
/* The key as the owner might type it: between double quotes with a '$' in it, which PHP reads as a variable. */
const CONFIG_WARNING_TEST_QUOTED = '"TEST_$PASS"';
/* The key between double quotes with an octal escape past \377: PHP warns while it compiles the file
   (E_COMPILE_WARNING, which no error handler sees), quotes the escape and keeps the byte '*'. A nowdoc, so that this
   file does not read the escape itself. */
const CONFIG_WARNING_TEST_OCTAL = <<<'PHP'
"TEST\452PASS"
PHP;

/** Writes <private>/config.php with the key written as $keyCode, and the statements $before ahead of its return, and
    runs $body with the folder and the file's path. */
function config_warning_test_with(string $keyCode, callable $body, string $before = ''): void
{
    notice_test_private(static function (string $private) use ($keyCode, $body, $before): void {
        $path = $private . '/config.php';
        $code = strtr(CONFIG_WARNING_TEST_CODE, ["<?php\n" => "<?php\n" . $before, 'PRIVATE_DIR' => var_export($private, true), 'KEY' => $keyCode]);
        file_put_contents($path, $code);
        $body($private, $path);
    });
}

/** Runs $body while PHP's own log is a file of its own; returns what $body returned and what PHP logged. */
function config_warning_test_logged(callable $body): array
{
    $log = (string) tempnam(sys_get_temp_dir(), 'oaza-warning-log-');
    ini_set('error_log', $log);
    try {
        $result = $body();
        $logged = (string) file_get_contents($log);
    } finally {
        ini_restore('error_log');
        unlink($log);
    }
    return [$result, $logged];
}

/** True when the runner's own error handler is in place again: it turns a warning into an ErrorException. */
function config_warning_test_handler_back(): bool
{
    try {
        trigger_error('a warning after the load', E_USER_WARNING);
    } catch (ErrorException $e) {
        return true;
    }
    return false;
}

test('load_config swallows a warning of config.php, returns the config and marks it: cPay counts as not set up', function (): void {
    config_warning_test_with(CONFIG_WARNING_TEST_QUOTED, static function (string $private, string $path): void {
        $config = load_config($path);
        same('TEST_', $config['cpay']['key'], 'PHP cut the key short at the $');
        same(['config_warning'], cpay_problems($config), 'the cut key looks like a key: only the mark tells');
        same([], config_problems($config), 'the enquiry form does not mind (design 6)');
        truthy(config_warned($config), 'config_warned() sees the mark');
        truthy(config_warning_test_handler_back(), 'the error handler of the caller is back');
    });
});

test('load_config marks a config for any warning, also one that leaves the key whole', function (): void {
    config_warning_test_with('"TEST_PASS$X"', static function (string $private, string $path): void {
        $config = load_config($path);
        same('TEST_PASS', $config['cpay']['key']);
        same(['config_warning'], cpay_problems($config));
    });
});

test('load_config marks a config that PHP warned about while compiling it, logs nothing and keeps no message', function (): void {
    config_warning_test_with(CONFIG_WARNING_TEST_OCTAL, static function (string $private, string $path): void {
        [$config, $logged] = config_warning_test_logged(static fn (): array => load_config($path));
        same('TEST*PASS', $config['cpay']['key'], 'PHP kept one byte for the escape');
        same(['config_warning'], cpay_problems($config), 'no error handler sees this warning: only the mark tells');
        same('', $logged, 'PHP\'s log got nothing, no piece of the key');
        same(null, error_get_last(), 'the message is not kept');
        truthy(config_warning_test_handler_back(), 'the error handler of the caller is back');
    });
});

test('load_config does not mark a clean config for an error that was raised before it', function (): void {
    config_warning_test_with("'TEST_PASS'", static function (string $private, string $path): void {
        set_error_handler(static fn (): bool => false); /* passed on to PHP, which keeps it as the last error */
        try {
            @trigger_error('an earlier notice', E_USER_NOTICE);
        } finally {
            restore_error_handler();
        }
        truthy(error_get_last() !== null, 'an earlier error is on record');
        same(false, config_warned(load_config($path)));
    });
});

test('load_config marks a config that raises a notice or a deprecation, logs neither and puts the caller\'s handler back', function (): void {
    foreach (['E_USER_NOTICE', 'E_USER_DEPRECATED'] as $level) {
        $before = "trigger_error('SECRETFRAGMENT', $level);\ntrigger_error('SECRETFRAGMENT', $level);\n";
        config_warning_test_with("'TEST_PASS'", static function (string $private, string $path) use ($level): void {
            [$config, $logged] = config_warning_test_logged(static fn (): array => load_config($path));
            same(['config_warning'], cpay_problems($config), $level);
            same('', $logged, "$level: PHP's log got nothing");
            same(2, required_config($path)[1], "$level: the error handler of load_config() counted both");
            truthy(config_warning_test_handler_back(), "$level: the error handler of the caller is back");
        }, $before);
    }
});

test('load_config marks a key that names one of its own variables: config.php runs where none is defined', function (): void {
    foreach (['path', 'warnings', 'config', 'logErrors', 'compiled', 'problems'] as $name) {
        config_warning_test_with('"TEST_$' . $name . '"', static function (string $private, string $path) use ($name): void {
            $config = load_config($path);
            same('TEST_', $config['cpay']['key'], "\$$name is not defined there");
            same(['config_warning'], cpay_problems($config), "\$$name");
        });
    }
});

test('load_config leaves log_errors as it found it, also when config.php warns or does not parse', function (): void {
    $before = ini_get('log_errors');
    try {
        foreach (['0', '1'] as $setting) {
            foreach (["'TEST_PASS'", CONFIG_WARNING_TEST_QUOTED, CONFIG_WARNING_TEST_OCTAL, "'TEST_PASS' 'x'"] as $keyCode) {
                ini_set('log_errors', $setting);
                config_warning_test_with($keyCode, static function (string $private, string $path): void {
                    try {
                        config_warning_test_logged(static fn (): array => load_config($path));
                    } catch (ParseError $e) {
                        /* the file that does not parse */
                    }
                });
                same($setting, ini_get('log_errors'), "log_errors $setting, key $keyCode");
            }
        }
    } finally {
        ini_set('log_errors', (string) $before);
    }
});

test('load_config of a config without a warning sets no mark', function (): void {
    config_warning_test_with("'TEST_PASS'", static function (string $private, string $path): void {
        $config = load_config($path);
        same([], cpay_problems($config));
        same(false, config_warned($config));
        same(false, array_key_exists('config_warning', $config), 'the config is as the file returns it');
    });
});

test('load_config puts the error handler back when config.php does not parse', function (): void {
    config_warning_test_with("'TEST_PASS' 'x'", static function (string $private, string $path): void {
        try {
            load_config($path);
            truthy(false, 'should have thrown');
        } catch (ParseError $e) {
            truthy(config_warning_test_handler_back(), 'the error handler of the caller is back');
        }
    });
});

test('a notice with a config that raised a warning is kept unverified and answered as with incomplete settings', function (): void {
    config_warning_test_with('"TEST_PASS$X"', static function (string $private, string $path): void {
        $record = notice_test_record($private);
        $config = load_config($path);
        $fields = notice_test_return(notice_test_pairs($record));
        same(['status' => 200, 'show' => 'neutral', 'record' => null], notice_outcome('ok', $fields, $config, NOTICE_TEST_PUSH));
        same($record, notice_test_stored($private, $record), 'nothing is marked');
        same([false], array_column(notice_test_copies($private), 'verified'));
    });
});

test('try_load_config logs config_warning once into the private log, and nothing into PHP\'s', function (): void {
    config_warning_test_with(CONFIG_WARNING_TEST_QUOTED, static function (string $private, string $path): void {
        $before = getenv('OAZA_CONFIG');
        $log = (string) tempnam(sys_get_temp_dir(), 'oaza-warning-log-');
        putenv('OAZA_CONFIG=' . $path);
        ini_set('error_log', $log);
        try {
            $config = try_load_config('test page');
            $phpLog = (string) file_get_contents($log);
        } finally {
            ini_restore('error_log');
            putenv($before === false ? 'OAZA_CONFIG' : 'OAZA_CONFIG=' . $before);
            unlink($log);
        }
        same($private, $config['private_dir'] ?? null, 'the config is returned');
        same(['config_warning'], notice_test_codes($private));
        same('', $phpLog);
    });
});

/** Runs try_load_config() for $path in a PHP process of its own whose log is $log, as a web server would ('litespeed'). */
function config_warning_test_process(string $path, string $log): string
{
    $script = $path . '.run.php';
    file_put_contents($script, implode("\n", [
        '<?php',
        'declare(strict_types=1);',
        'require ' . var_export(dirname(__DIR__, 2) . '/src/cpay/lib/notice.php', true) . ';',
        'putenv(' . var_export('OAZA_CONFIG=' . $path, true) . ');',
        '$config = try_load_config(\'test page\', \'litespeed\');',
        'echo json_encode($config === null ? null : [cpay_problems($config), config_problems($config)]);',
    ]));
    /* The path in quotes: unquoted, a '~' of a short Windows path is an operator to PHP's ini parser. */
    $process = proc_open([PHP_BINARY, '-d', 'log_errors=1', '-d', 'error_log="' . $log . '"', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    truthy(is_resource($process), 'PHP started');
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    same('', $err, 'nothing on STDERR');
    return $out;
}

test('a warning of config.php reaches neither the server\'s PHP log nor the private one, in a process of its own', function (): void {
    if (!function_exists('proc_open')) {
        skip('no second process can be started here');
    }
    /* "Undefined variable" while the file runs; "Octal escape sequence overflow" while PHP compiles it */
    foreach (['a $ between double quotes' => CONFIG_WARNING_TEST_QUOTED, 'an octal escape past \\377' => CONFIG_WARNING_TEST_OCTAL] as $case => $keyCode) {
        config_warning_test_with($keyCode, static function (string $private, string $path) use ($case): void {
            $log = $private . '/server-php.log';
            same('[["config_warning"],[]]', config_warning_test_process($path, $log), "$case: cPay is not set up, the enquiry form is");
            $serverLog = is_file($log) ? (string) file_get_contents($log) : '';
            truthy(!str_contains($serverLog, 'Octal'), "$case: no line about the escape");
            same('', $serverLog, "$case: the server's log got nothing, no piece of the key");
            $privatePhpLog = $private . '/logs/php-errors.log';
            same('', is_file($privatePhpLog) ? (string) file_get_contents($privatePhpLog) : '', "$case: nor the private PHP log");
            same(['config_warning'], notice_test_codes($private), "$case: the code is in the private log, once");
        });
    }
});
