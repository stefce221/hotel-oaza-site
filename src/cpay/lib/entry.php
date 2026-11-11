<?php
/* Hotel Oaza — what every entry point of the cPay addresses and the staff page does first: catch what nobody
   caught, load the config without ever logging a piece of it, and keep PHP's own log in the private folder. */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/api/lib/bootstrap.php';

/**
 * Uncaught errors: logs "Hotel Oaza <who>: uncaught <class>" and nothing else (a message could quote the config
 * or the key), then calls $answer unless headers were sent. If $answer fails too, only its class is logged.
 */
function guard_uncaught(string $who, callable $answer): void
{
    set_exception_handler(static function (Throwable $e) use ($who, $answer): void {
        error_log('Hotel Oaza ' . $who . ': uncaught ' . get_class($e));
        if (headers_sent()) {
            return;
        }
        try {
            $answer();
        } catch (Throwable $again) {
            error_log('Hotel Oaza ' . $who . ': uncaught ' . get_class($again) . ' while answering');
        }
    });
}

/**
 * The config, or null after logging why in the way api/enquiry.php does: a RuntimeException's message (a code and
 * setting names), else class and line only, as a ParseError in config.php quotes the code around the error.
 * Whatever config.php prints while it is read is thrown away, also when it ends the request itself: a file whose
 * opening tag is damaged would otherwise be sent to the visitor whole, key included, and a byte-order mark or a
 * blank line above the tag would go out before the status and the headers of the page.
 * After a successful load PHP's own log is pointed at the private folder (see private_php_log(); $sapi is for tests).
 * A config that raised a warning while it loaded (see load_config()) is returned all the same, and the code
 * config_warning goes into the private log: each entry point loads the config once, so once per request.
 */
function try_load_config(string $who, string $sapi = PHP_SAPI): ?array
{
    $level = ob_get_level();
    ob_start(static fn (): string => '');
    try {
        $config = load_config(config_path());
    } catch (Throwable $e) {
        error_log('Hotel Oaza ' . $who . ': ' . ($e instanceof RuntimeException ? $e->getMessage() : get_class($e) . ' on line ' . $e->getLine()));
        return null;
    } finally {
        while (ob_get_level() > $level && ob_end_clean()) {
            /* also closes a buffer the config left open; one that cannot be closed ends the loop */
        }
    }
    private_php_log($config['private_dir'], $sapi);
    if (config_warned($config)) {
        log_error($config['private_dir'], CONFIG_WARNING);
    }
    return $config;
}

/**
 * Points PHP's own error log at <private>/logs/php-errors.log, so that PHP's warnings never land in a file inside
 * the web folder. Does nothing (false) under cli and cli-server, where the tests choose the log, and when the
 * private folder does not exist or its logs folder cannot be made (0700, as log_error() makes it).
 */
function private_php_log(string $privateDir, string $sapi = PHP_SAPI): bool
{
    if ($privateDir === '' || !is_dir($privateDir) || in_array($sapi, ['cli', 'cli-server'], true)) {
        return false;
    }
    $dir = $privateDir . '/logs';
    if (!is_dir($dir) && !@mkdir($dir, 0700) && !is_dir($dir)) {
        return false;
    }
    return ini_set('error_log', $dir . '/php-errors.log') !== false;
}
