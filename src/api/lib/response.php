<?php
/* Hotel Oaza — JSON answers in the site's envelope, and the error log. */
declare(strict_types=1);

/** Sends {"success", "data": null, "error"} and ends the request. */
function respond(int $status, ?array $error = null): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['success' => $error === null, 'data' => null, 'error' => $error], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

/** @param string[] $fields the invalid fields, for code "invalid" */
function respond_error(int $status, string $code, array $fields = []): void
{
    respond($status, $fields === [] ? ['code' => $code] : ['code' => $code, 'fields' => $fields]);
}

/** One line per error, timestamp and code only — never personal data. Falls back to PHP's own log. */
function log_error(string $privateDir, string $code): void
{
    $dir = $privateDir . '/logs';
    $line = date('c') . ' ' . $code . "\n";
    if ((!is_dir($dir) && !mkdir($dir, 0700, true)) || file_put_contents($dir . '/errors.log', $line, FILE_APPEND | LOCK_EX) === false) {
        error_log('Hotel Oaza: ' . trim($line));
    }
}
