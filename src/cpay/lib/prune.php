<?php
/* Hotel Oaza — retention for the cPay records and raw copies (design section 4.8). Part of the record store:
   payments.php requires this file, and it uses the store's lock and file names. */
declare(strict_types=1);

require_once __DIR__ . '/payments.php';

/* A raw copy in <private>/cpay-inbox: YYYYMMDD-HHMMSS-<kind>-<8 hex>.json */
const PAYMENT_INBOX_PATTERN = '/\A([0-9]{8})-[0-9]{6}-[a-z]+-[0-9a-f]{8}\.json\z/';
/* In the record folder; holds the day (YYYYMMDD) of the last pruning. */
const PAYMENT_PRUNED_MARKER = '.pruned';

/** The record files whose reference was issued before $before. */
function prunable_payment_names(string $dir, int $before): array
{
    return array_values(array_filter(
        payment_file_names($dir),
        static fn (string $name): bool => reference_time(substr($name, 0, 10)) < $before,
    ));
}

/** The raw copies in the inbox whose name carries a date before the day $before falls on. */
function prunable_inbox_names(string $inbox, int $before): array
{
    $found = is_dir($inbox) ? payments_quietly(static fn () => scandir($inbox)) : false;
    $day = date('Ymd', $before);
    return array_values(array_filter(
        is_array($found) ? $found : [],
        static fn (string $name): bool => preg_match(PAYMENT_INBOX_PATTERN, $name, $parts) === 1 && strcmp($parts[1], $day) < 0,
    ));
}

/** Temporary copies (<record file>.tmp) that a write left behind when the server stopped between writing and
    renaming. Pruning holds the store's lock, so no write is under way and every such file is a left-over. */
function leftover_temporary_names(string $dir): array
{
    $found = is_dir($dir) ? payments_quietly(static fn () => scandir($dir)) : false;
    return array_values(array_filter(
        is_array($found) ? $found : [],
        static fn (string $name): bool => str_ends_with($name, '.tmp') && preg_match(PAYMENT_FILE_PATTERN, substr($name, 0, -4)) === 1,
    ));
}

/** Deletes those files of a folder; one that will not go is left for another day. */
function prune_files(string $folder, array $names): void
{
    foreach ($names as $name) {
        $path = $folder . '/' . $name;
        payments_quietly(static fn (): bool => is_file($path) && unlink($path));
    }
}

/** At most once a day (marker file .pruned in the record folder): deletes records older than $recordDays (by reference
    time) and inbox files older than $noticeDays (by the date in their name). A file with any other name is left alone.
    Throws InvalidArgumentException for less than one day, RuntimeException('payments_write') when the lock or the
    marker cannot be written. */
function prune_old(string $privateDir, int $recordDays, int $noticeDays, int $now): void
{
    if ($recordDays < 1 || $noticeDays < 1) {
        throw new InvalidArgumentException('prune_days');
    }
    $dir = payments_dir($privateDir);
    $inbox = $privateDir . '/cpay-inbox';
    payments_locked($dir, static function () use ($dir, $inbox, $recordDays, $noticeDays, $now): void {
        $marker = $dir . '/' . PAYMENT_PRUNED_MARKER;
        $today = date('Ymd', $now);
        if (is_file($marker) && payments_quietly(static fn () => file_get_contents($marker)) === $today) {
            return;
        }
        prune_files($dir, prunable_payment_names($dir, $now - $recordDays * 86400));
        prune_files($dir, leftover_temporary_names($dir));
        prune_files($inbox, prunable_inbox_names($inbox, $now - $noticeDays * 86400));
        if (payments_quietly(static fn () => file_put_contents($marker, $today)) !== strlen($today)) {
            throw new RuntimeException('payments_write');
        }
    });
}
