<?php
/* The record store's lock: work runs holding it, every write waits for it, and what a write decides is decided on
   the files as they stand once the lock is had. A second PHP process holds the lock where one is needed. */
declare(strict_types=1);

require_once __DIR__ . '/payments-helpers.php';

/** True when the folder's lock can be taken at this moment (through a handle of its own, closed again). */
function payments_test_lock_free(string $dir): bool
{
    $handle = fopen($dir . '/.lock', 'c');
    try {
        return flock($handle, LOCK_EX | LOCK_NB);
    } finally {
        fclose($handle);
    }
}

/** Runs $work while a second PHP process holds the folder's lock for a moment. True when $work came back only after
    that process had let go: it leaves a file behind just before it does. */
function payments_test_waits_for_lock(string $dir, callable $work): bool
{
    $released = dirname($dir) . '/lock-released';
    payments_test_remove($released);
    $holder = "\$lock = fopen(\$argv[1], 'c'); flock(\$lock, LOCK_EX); echo 'held'; usleep(250000); touch(\$argv[2]); flock(\$lock, LOCK_UN);";
    $process = proc_open([PHP_BINARY, '-r', $holder, $dir . '/.lock', $released], [1 => ['pipe', 'w']], $pipes);
    truthy(is_resource($process), 'a second PHP process starts');
    try {
        same('held', fread($pipes[1], 4), 'the second process holds the lock');
        $work();
        clearstatcache();
        return is_file($released);
    } finally {
        fclose($pipes[1]);
        proc_close($process);
    }
}

/** Runs $work while a second PHP process holds the folder's lock and, a moment later and still holding it, runs
    $whileHeld (PHP code that reads $argv[2] and after; $argv[1] is the lock file). Returns what $work returns. */
function payments_test_while_other_holds(string $dir, string $whileHeld, array $args, callable $work): mixed
{
    $holder = "\$lock = fopen(\$argv[1], 'c'); flock(\$lock, LOCK_EX); echo 'held'; usleep(250000);\n" . $whileHeld . "\nflock(\$lock, LOCK_UN);";
    $process = proc_open([PHP_BINARY, '-r', $holder, $dir . '/.lock', ...$args], [1 => ['pipe', 'w']], $pipes);
    truthy(is_resource($process), 'a second PHP process starts');
    try {
        same('held', fread($pipes[1], 4), 'the second process holds the lock');
        return $work();
    } finally {
        fclose($pipes[1]);
        proc_close($process);
    }
}

test('payments: work runs holding the lock, which is free again afterwards, also when the work throws', function (): void {
    payments_test_private(function (string $private): void {
        $dir = payments_dir($private);
        $answer = payments_locked($dir, static function () use ($dir): string {
            truthy(!payments_test_lock_free($dir), 'taken while the work runs');
            return 'done';
        });
        same('done', $answer, 'what the work returns');
        truthy(payments_test_lock_free($dir), 'free afterwards');
        $message = payments_test_thrown(LogicException::class, static fn () => payments_locked($dir, static function () use ($dir): void {
            truthy(!payments_test_lock_free($dir), 'taken while the work runs');
            throw new LogicException('the work failed');
        }));
        same('the work failed', $message, "the work's own exception comes through");
        truthy(payments_test_lock_free($dir), 'free after work that throws');
    });
});

test('payments: creating, applying a notice and pruning each wait for the lock', function (): void {
    if (!function_exists('proc_open')) {
        skip('no second process can be started here');
    }
    payments_test_private(function (string $private): void {
        $dir = payments_dir($private);
        $record = payments_test_record(PAYMENTS_TEST_NOW);
        create_payment($dir, $record);
        $other = payments_test_record(PAYMENTS_TEST_NOW + 1, '222222');
        $writes = [
            'create_payment' => static fn () => create_payment($dir, $other),
            'apply_notice' => static fn () => apply_notice($dir, $record['ref'], 'ok', 'push', '123456', date('c', PAYMENTS_TEST_NOW + 60)),
            'prune_old' => static fn () => prune_old($private, 548, 90, PAYMENTS_TEST_NOW),
        ];
        foreach ($writes as $name => $write) {
            truthy(payments_test_waits_for_lock($dir, $write), "$name came back only after the other process let go of the lock");
        }
        same($other, find_payment($dir, $other['ref']), 'and then did its work');
        same('paid', find_payment($dir, $record['ref'])['status'], 'and then did its work');
    });
});

test('payments: a notice is applied to the record as it stands once the lock is had, not as it was read before', function (): void {
    if (!function_exists('proc_open')) {
        skip('no second process can be started here');
    }
    payments_test_private(function (string $private): void {
        $dir = payments_dir($private);
        $record = payments_test_record(PAYMENTS_TEST_NOW);
        create_payment($dir, $record);
        $path = $dir . '/' . payment_file_name($record['ref'], $record['reservation']);
        /* While it holds the lock, the other process marks the record paid, as an OK notice would. */
        $markPaid = "\$record = json_decode(file_get_contents(\$argv[2]), true);\n"
            . "file_put_contents(\$argv[2], json_encode(array_merge(\$record, ['status' => 'paid', 'cpay_ref' => '123456'])));";
        $after = payments_test_while_other_holds($dir, $markPaid, [$path],
            static fn (): ?array => apply_notice($dir, $record['ref'], 'fail', 'browser', '', date('c', PAYMENTS_TEST_NOW + 60)));
        $stored = find_payment($dir, $record['ref']);
        same(['paid', '123456'], [$stored['status'], $stored['cpay_ref']], 'the fail notice leaves the paid record paid, with its cPay reference');
        same([['at' => date('c', PAYMENTS_TEST_NOW + 60), 'kind' => 'fail', 'via' => 'browser']], $stored['notices'], 'and joins its list');
        same($stored, $after, 'what apply_notice returns is what was stored');
    });
});

test('payments: whether a reference is taken is looked at once the lock is had: a record written meanwhile stays, and creating says false', function (): void {
    if (!function_exists('proc_open')) {
        skip('no second process can be started here');
    }
    payments_test_private(function (string $private): void {
        $dir = payments_dir($private);
        mkdir($dir, 0700);
        $mine = payments_test_record(PAYMENTS_TEST_NOW);
        $theirs = array_merge($mine, ['user' => 'ana.petrova', 'amount' => 5]);
        $path = $dir . '/' . payment_file_name($mine['ref'], $mine['reservation']);
        /* While it holds the lock, the other process writes a record with the same reference. */
        $created = payments_test_while_other_holds($dir, 'file_put_contents($argv[2], $argv[3]);', [$path, json_encode($theirs)],
            static fn (): bool => create_payment($dir, $mine));
        same(false, $created, 'the reference is taken by the time the lock is had');
        same($theirs, find_payment($dir, $mine['ref']), 'the record written meanwhile is left as it is');
    });
});
