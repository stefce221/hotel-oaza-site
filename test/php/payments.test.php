<?php
declare(strict_types=1);

require_once __DIR__ . '/payments-helpers.php';

test('payments: a reference is ten characters 0-9 A-Z, carries the time and sorts by it', function (): void {
    $ref = new_reference(PAYMENTS_TEST_NOW);
    same(1, preg_match('/\A[0-9A-Z]{10}\z/', $ref), $ref);
    truthy(valid_reference($ref), 'a new reference is valid');
    same(PAYMENTS_TEST_NOW, reference_time($ref));
    same(substr($ref, 0, 6), substr(new_reference(PAYMENTS_TEST_NOW), 0, 6), 'the same second gives the same first six');
    truthy(strcmp($ref, new_reference(PAYMENTS_TEST_NOW + 1)) < 0, 'a second later sorts later');
    truthy(strcmp(new_reference(PAYMENTS_TEST_NOW - 400 * 86400), $ref) < 0, 'a year earlier sorts earlier');
    same('000001', substr(new_reference(1), 0, 6), 'a short time is padded with zeros');
    same(1, reference_time(new_reference(1)));
    same(1792129690, reference_time('TMZK4A7Q2B'), "the plan's example");
    $many = array_map(static fn (int $i): string => new_reference(PAYMENTS_TEST_NOW), range(1, 40));
    truthy(count(array_unique($many)) > 1, 'the last four characters are random');
});

test('payments: a time that does not fit six base-36 characters gives no reference', function (): void {
    payments_test_thrown(InvalidArgumentException::class, static fn () => new_reference(-1));
    payments_test_thrown(InvalidArgumentException::class, static fn () => new_reference(36 ** 6));
    same('ZZZZZZ', substr(new_reference(36 ** 6 - 1), 0, 6));
});

test('payments: valid_reference accepts exactly ten of 0-9 A-Z', function (): void {
    truthy(valid_reference('TMZK4A7Q2B'), 'ten characters');
    truthy(valid_reference('0000000000'), 'digits only');
    foreach (['', 'TMZK4A7Q2', 'TMZK4A7Q2BB', 'tmzk4a7q2b', "TMZK4A7Q2B\n", 'TMZK4A7Q2-', '../x', 'TMZK4A7Q2Б'] as $bad) {
        truthy(!valid_reference($bad), 'refused: ' . json_encode($bad));
    }
    payments_test_thrown(InvalidArgumentException::class, static fn () => reference_time('../x'));
});

test('payments: a new record is complete, waiting, without a cPay reference or notices', function (): void {
    $record = new_payment('TMZK4A7Q2B', 'recepcija', 21802, '4567890123', 'booking 4567890123', PAYMENTS_TEST_URL, 'md5', PAYMENTS_TEST_NOW);
    same([
        'ref' => 'TMZK4A7Q2B',
        'created' => date('c', PAYMENTS_TEST_NOW),
        'updated' => date('c', PAYMENTS_TEST_NOW),
        'user' => 'recepcija',
        'amount' => 21802,
        'reservation' => '4567890123',
        'details1' => 'booking 4567890123',
        'payment_url' => PAYMENTS_TEST_URL,
        'hash' => 'md5',
        'status' => 'waiting',
        'cpay_ref' => '',
        'extra_refs' => [],
        'notices' => [],
    ], $record);
});

test('payments: creating twice with one reference writes once and keeps the first record', function (): void {
    payments_test_private(function (string $private): void {
        $dir = payments_dir($private);
        same($private . '/cpay-payments', $dir);
        $first = payments_test_record(PAYMENTS_TEST_NOW);
        same(true, create_payment($dir, $first), 'the first time');
        same(false, create_payment($dir, array_merge($first, ['amount' => 5, 'user' => 'other'])), 'the second time');
        same($first, find_payment($dir, $first['ref']), 'the first record is still there');
        $elsewhere = array_merge($first, ['reservation' => '999999', 'details1' => 'booking 999999']);
        same(false, create_payment($dir, $elsewhere), 'the same reference for another reservation');
        same(['.lock', $first['ref'] . '-4567890123.json'], payments_test_names($dir), 'one record and no temporary file');
        $text = (string) file_get_contents($dir . '/' . $first['ref'] . '-4567890123.json');
        same($first, json_decode($text, true), 'the file is the record as JSON');
    });
});

test('payments: a reference or reservation of the wrong shape is refused before anything is written', function (): void {
    payments_test_private(function (string $private): void {
        $dir = payments_dir($private);
        $good = payments_test_record(PAYMENTS_TEST_NOW);
        foreach (['../x', 'tmzk4a7q2b', 'TMZK4A7Q2', "TMZK4A7Q2B\n", ''] as $ref) {
            payments_test_thrown(InvalidArgumentException::class, static fn () => create_payment($dir, array_merge($good, ['ref' => $ref])));
        }
        foreach (['12345', '123456789012345678901', '12345a', '../../x', "123456\n", ''] as $reservation) {
            payments_test_thrown(InvalidArgumentException::class, static fn () => create_payment($dir, array_merge($good, ['reservation' => $reservation])));
        }
        foreach ([['amount' => '21802'], ['amount' => 0], ['status' => 'done'], ['notices' => 'none'], ['ref' => 123]] as $wrong) {
            payments_test_thrown(InvalidArgumentException::class, static fn () => create_payment($dir, array_merge($good, $wrong)));
        }
        payments_test_thrown(InvalidArgumentException::class, static fn () => create_payment($dir, []));
        truthy(!file_exists($dir), 'not even the folder was made');
        same(null, find_payment($dir, '../x'));
        same([], payments_for_reservation($dir, '12345'));
        same([], payments_for_reservation($dir, '../../x'));
        same(null, apply_notice($dir, '../x', 'ok', 'push', '123456', date('c', PAYMENTS_TEST_NOW)));
        truthy(!file_exists($dir), 'reading makes no folder either');
    });
});

test('payments: a folder that cannot be written is reported as payments_write', function (): void {
    payments_test_private(function (string $private): void {
        $record = payments_test_record(PAYMENTS_TEST_NOW);
        $before = umask();
        $missing = payments_dir($private . '/no-such-folder');
        same('payments_write', payments_test_thrown(RuntimeException::class, static fn () => create_payment($missing, $record)));
        truthy(!file_exists($private . '/no-such-folder'), 'the private folder itself is never made');
        $dir = payments_dir($private);
        file_put_contents($dir, 'a plain file where the folder should be');
        same('payments_write', payments_test_thrown(RuntimeException::class, static fn () => create_payment($dir, $record)));
        same('payments_write', payments_test_thrown(RuntimeException::class, static fn () => prune_old($private, 548, 90, PAYMENTS_TEST_NOW)));
        same($before, umask(), 'the umask is put back after a failure too');
        same(null, find_payment($dir, $record['ref']));
        same([], latest_payments($dir));
    });
});

test('payments: the folder is 0700, the files 0600, and the umask is put back', function (): void {
    payments_test_private(function (string $private): void {
        $dir = payments_dir($private);
        $record = payments_test_record(PAYMENTS_TEST_NOW);
        $before = umask();
        create_payment($dir, $record);
        apply_notice($dir, $record['ref'], 'fail', 'browser', '', date('c', PAYMENTS_TEST_NOW + 60));
        same($before, umask(), 'the umask after writing');
        if (PHP_OS_FAMILY === 'Windows') {
            skip('the modes: Windows does not enforce them (the umask was checked)');
        }
        clearstatcache();
        same(0700, fileperms($dir) & 0777, 'folder');
        same(0600, fileperms($dir . '/' . $record['ref'] . '-4567890123.json') & 0777, 'record');
        same(0600, fileperms($dir . '/.lock') & 0777, 'lock file');
    });
});

test('payments: new content goes to a temporary file, which then takes the place of the record', function (): void {
    payments_test_private(function (string $private): void {
        $dir = payments_dir($private);
        $record = payments_test_record(PAYMENTS_TEST_NOW);
        create_payment($dir, $record);
        $path = $dir . '/' . $record['ref'] . '-4567890123.json';
        $at = date('c', PAYMENTS_TEST_NOW + 60);
        mkdir($path . '.tmp'); /* the temporary file's name is taken by a folder */
        $notice = static fn () => apply_notice($dir, $record['ref'], 'ok', 'push', '123456', $at);
        same('payments_write', payments_test_thrown(RuntimeException::class, $notice), 'nothing is written straight into the record');
        same($record, find_payment($dir, $record['ref']), 'the record is as it was');
        rmdir($path . '.tmp');
        clearstatcache();
        $inode = fileinode($path);
        same('paid', $notice()['status'], 'the same notice once the name is free');
        clearstatcache();
        truthy($inode === 0 || fileinode($path) !== $inode, 'another file has taken the place of the record');
        same(['.lock', basename($path)], payments_test_names($dir), 'no temporary file is left');
    });
});

test('payments: an OK notice with a cPay reference turns waiting into paid', function (): void {
    payments_test_private(function (string $private): void {
        $dir = payments_dir($private);
        $record = payments_test_record(PAYMENTS_TEST_NOW);
        create_payment($dir, $record);
        $at = date('c', PAYMENTS_TEST_NOW + 62);
        $after = apply_notice($dir, $record['ref'], 'ok', 'push', '123456', $at);
        $expected = array_merge($record, [
            'updated' => $at,
            'status' => 'paid',
            'cpay_ref' => '123456',
            'notices' => [['at' => $at, 'kind' => 'ok', 'via' => 'push']],
        ]);
        same($expected, $after, 'what apply_notice returns');
        same($expected, find_payment($dir, $record['ref']), 'what is stored');
        same(['.lock', $record['ref'] . '-4567890123.json'], payments_test_names($dir), 'renamed over the record, nothing left over');
    });
});

test('payments: a fail notice turns waiting into failed and keeps its cPay reference when the record has none', function (): void {
    payments_test_private(function (string $private): void {
        $dir = payments_dir($private);
        $record = payments_test_record(PAYMENTS_TEST_NOW);
        create_payment($dir, $record);
        $first = date('c', PAYMENTS_TEST_NOW + 60);
        $after = apply_notice($dir, $record['ref'], 'fail', 'browser', '', $first);
        same('failed', $after['status']);
        same('', $after['cpay_ref']);
        same([['at' => $first, 'kind' => 'fail', 'via' => 'browser']], $after['notices']);
        $second = date('c', PAYMENTS_TEST_NOW + 75);
        $after = apply_notice($dir, $record['ref'], 'fail', 'push', '777', $second);
        same('failed', $after['status']);
        same('777', $after['cpay_ref'], 'stored: the record had none');
        $after = apply_notice($dir, $record['ref'], 'fail', 'unlisted', '888', date('c', PAYMENTS_TEST_NOW + 90));
        same('777', $after['cpay_ref'], 'not replaced');
        same(['browser', 'push', 'unlisted'], array_column($after['notices'], 'via'));
        same($after, find_payment($dir, $record['ref']));
    });
});

test("payments: an OK notice turns failed into paid (cPay's documented case)", function (): void {
    payments_test_private(function (string $private): void {
        $dir = payments_dir($private);
        $record = payments_test_record(PAYMENTS_TEST_NOW);
        create_payment($dir, $record);
        apply_notice($dir, $record['ref'], 'fail', 'browser', '111', date('c', PAYMENTS_TEST_NOW + 60));
        $after = apply_notice($dir, $record['ref'], 'ok', 'push', '222', date('c', PAYMENTS_TEST_NOW + 75));
        same('paid', $after['status']);
        same('222', $after['cpay_ref'], "the paying notice's reference");
        same([], $after['extra_refs'], 'one charge, not two');
        same(['fail', 'ok'], array_column($after['notices'], 'kind'));
    });
});

test('payments: a fail notice after paid leaves it paid', function (): void {
    payments_test_private(function (string $private): void {
        $dir = payments_dir($private);
        $record = payments_test_record(PAYMENTS_TEST_NOW);
        create_payment($dir, $record);
        apply_notice($dir, $record['ref'], 'ok', 'push', '123456', date('c', PAYMENTS_TEST_NOW + 60));
        $after = apply_notice($dir, $record['ref'], 'fail', 'browser', '999', date('c', PAYMENTS_TEST_NOW + 70));
        same('paid', $after['status']);
        same('123456', $after['cpay_ref']);
        same([], $after['extra_refs']);
        same(['ok', 'fail'], array_column($after['notices'], 'kind'), 'the notice is still added');
        same($after, find_payment($dir, $record['ref']));
    });
});

test('payments: a repeated OK notice changes nothing but is added; another cPay reference is kept once', function (): void {
    payments_test_private(function (string $private): void {
        $dir = payments_dir($private);
        $record = payments_test_record(PAYMENTS_TEST_NOW);
        create_payment($dir, $record);
        $paid = apply_notice($dir, $record['ref'], 'ok', 'push', '123456', date('c', PAYMENTS_TEST_NOW + 60));
        $at = date('c', PAYMENTS_TEST_NOW + 75);
        $repeat = apply_notice($dir, $record['ref'], 'ok', 'browser', '123456', $at);
        $expected = array_merge($paid, ['updated' => $at, 'notices' => [...$paid['notices'], ['at' => $at, 'kind' => 'ok', 'via' => 'browser']]]);
        same($expected, $repeat, 'the same reference again');
        truthy(!has_two_charges($repeat), 'a repeat is not a second charge');

        $other = apply_notice($dir, $record['ref'], 'ok', 'push', '654321', date('c', PAYMENTS_TEST_NOW + 90));
        same('paid', $other['status']);
        same('123456', $other['cpay_ref'], 'the first reference stays');
        same(['654321'], $other['extra_refs']);
        truthy(has_two_charges($other), 'flagged');
        $again = apply_notice($dir, $record['ref'], 'ok', 'browser', '654321', date('c', PAYMENTS_TEST_NOW + 95));
        same(['654321'], $again['extra_refs'], 'the same second reference is kept once');
        same(4, count($again['notices']));
        same($again, find_payment($dir, $record['ref']));
    });
});

test('payments: an OK notice without a usable cPay reference marks nothing', function (): void {
    payments_test_private(function (string $private): void {
        $dir = payments_dir($private);
        $record = payments_test_record(PAYMENTS_TEST_NOW);
        create_payment($dir, $record);
        $at = date('c', PAYMENTS_TEST_NOW + 60);
        $after = apply_notice($dir, $record['ref'], 'ok', 'browser', '', $at);
        same(array_merge($record, ['updated' => $at, 'notices' => [['at' => $at, 'kind' => 'ok', 'via' => 'browser']]]), $after);
        foreach (["12'34", 'abc', '123456789012345678901', "123\n", ' 123'] as $notDigits) {
            $after = apply_notice($dir, $record['ref'], 'ok', 'push', $notDigits, $at);
            same('waiting', $after['status'], json_encode($notDigits));
            same('', $after['cpay_ref'], json_encode($notDigits));
        }
        $after = apply_notice($dir, $record['ref'], 'fail', 'push', '<b>', $at);
        same('', $after['cpay_ref'], 'a fail notice stores only digits too');
    });
});

test('payments: a notice for an unknown reference returns null and writes nothing', function (): void {
    payments_test_private(function (string $private): void {
        $dir = payments_dir($private);
        $at = date('c', PAYMENTS_TEST_NOW);
        same(null, apply_notice($dir, 'TMZK4A7Q2B', 'ok', 'push', '123456', $at), 'no folder yet');
        truthy(!file_exists($dir), 'and none is made');
        $record = payments_test_record(PAYMENTS_TEST_NOW);
        create_payment($dir, $record);
        same(null, apply_notice($dir, 'TMZK4A7Q2B', 'ok', 'push', '123456', $at), 'another reference');
        same(null, find_payment($dir, 'TMZK4A7Q2B'));
        same($record, find_payment($dir, $record['ref']), 'the other record is untouched');
        same(['.lock', $record['ref'] . '-4567890123.json'], payments_test_names($dir));
    });
});

test('payments: a notice of an unknown kind or channel, or with a time that is not ISO 8601, is refused', function (): void {
    payments_test_private(function (string $private): void {
        $dir = payments_dir($private);
        $record = payments_test_record(PAYMENTS_TEST_NOW);
        create_payment($dir, $record);
        $at = date('c', PAYMENTS_TEST_NOW);
        payments_test_thrown(InvalidArgumentException::class, static fn () => apply_notice($dir, $record['ref'], 'paid', 'push', '123456', $at));
        payments_test_thrown(InvalidArgumentException::class, static fn () => apply_notice($dir, $record['ref'], 'ok', 'email', '123456', $at));
        foreach (['', 'now', '2026-11-11', "$at\n", '<script>alert(1)</script>', (string) PAYMENTS_TEST_NOW] as $notTime) {
            payments_test_thrown(InvalidArgumentException::class, static fn () => apply_notice($dir, $record['ref'], 'fail', 'push', '55', $notTime));
        }
        same($record, find_payment($dir, $record['ref']));
    });
});

test('payments: the 21st notice is not added, and still counts for the status', function (): void {
    payments_test_private(function (string $private): void {
        $dir = payments_dir($private);
        $record = payments_test_record(PAYMENTS_TEST_NOW);
        create_payment($dir, $record);
        same(20, CPAY_MAX_NOTICES);
        foreach (range(1, 20) as $i) {
            $twenty = apply_notice($dir, $record['ref'], 'fail', 'browser', '', date('c', PAYMENTS_TEST_NOW + $i));
        }
        same(20, count($twenty['notices']));
        $next = apply_notice($dir, $record['ref'], 'fail', 'push', '', date('c', PAYMENTS_TEST_NOW + 21));
        same($twenty, $next, 'nothing changed, so nothing is rewritten');
        truthy(!in_array('push', array_column($next['notices'], 'via'), true), 'the dropped notice left no trace');
        $paid = apply_notice($dir, $record['ref'], 'ok', 'push', '123456', date('c', PAYMENTS_TEST_NOW + 22));
        same('paid', $paid['status'], 'a full list never stops a payment being marked');
        same('123456', $paid['cpay_ref']);
        same($twenty['notices'], $paid['notices']);
        same($paid, find_payment($dir, $record['ref']));
    });
});

test('payments: apply_notice_result says whether the notice joined the record, which ends at 20', function (): void {
    payments_test_private(function (string $private): void {
        $dir = payments_dir($private);
        $record = payments_test_record(PAYMENTS_TEST_NOW);
        create_payment($dir, $record);
        $at = static fn (int $second): string => date('c', PAYMENTS_TEST_NOW + $second);
        same(null, apply_notice_result($dir, 'TMZK4A0000', 'ok', 'push', '1', $at(1)), 'no such record');
        $first = apply_notice_result($dir, $record['ref'], 'ok', 'push', '123456', $at(1));
        same(['record' => find_payment($dir, $record['ref']), 'added' => true], $first, 'the record as it now stands, and the notice joined it');
        foreach (range(2, 20) as $i) {
            same(true, apply_notice_result($dir, $record['ref'], 'ok', 'unlisted', '123456', $at($i))['added'], "notice $i");
        }
        $full = apply_notice_result($dir, $record['ref'], 'ok', 'unlisted', '123456', $at(21));
        same([false, 20], [$full['added'], count($full['record']['notices'])], 'the 21st does not join');
        $other = apply_notice_result($dir, $record['ref'], 'ok', 'unlisted', '654321', $at(22));
        same([false, ['654321']], [$other['added'], $other['record']['extra_refs']], 'nor one that changes the record');
        same($other['record'], apply_notice($dir, $record['ref'], 'ok', 'unlisted', '654321', $at(23)), 'apply_notice gives the same record');
        same('payment_notice', payments_test_thrown(InvalidArgumentException::class, static fn () => apply_notice_result($dir, $record['ref'], 'paid', 'push', '', $at(24))));
    });
});

test('payments: waiting is shown as waiting for 45 minutes and as no answer after that', function (): void {
    same(2700, CPAY_NO_ANSWER_AFTER);
    $record = payments_test_record(PAYMENTS_TEST_NOW);
    same('waiting', display_status($record, PAYMENTS_TEST_NOW));
    same('waiting', display_status($record, PAYMENTS_TEST_NOW + 44 * 60), '44 minutes');
    same('waiting', display_status($record, PAYMENTS_TEST_NOW + 2699));
    same('no_answer', display_status($record, PAYMENTS_TEST_NOW + 2700));
    same('no_answer', display_status($record, PAYMENTS_TEST_NOW + 46 * 60), '46 minutes');
    same('paid', display_status(array_merge($record, ['status' => 'paid']), PAYMENTS_TEST_NOW + 86400), 'time never changes paid');
    same('failed', display_status(array_merge($record, ['status' => 'failed']), PAYMENTS_TEST_NOW + 86400), 'or failed');

    /* The form may be open for a while before it is sent: the wait counts from the start, not from the reference. */
    $issued = substr(new_reference(PAYMENTS_TEST_NOW - 3000), 0, 6) . 'AAAA';
    $late = new_payment($issued, 'recepcija', 21802, '4567890123', 'booking 4567890123', PAYMENTS_TEST_URL, 'md5', PAYMENTS_TEST_NOW);
    same('waiting', display_status($late, PAYMENTS_TEST_NOW + 600));
    same('no_answer', display_status(array_merge($late, ['created' => 'not a time']), PAYMENTS_TEST_NOW + 600), 'without a readable start the reference decides');
});

test('payments: what makes staff confirm before charging a reservation again', function (): void {
    $now = PAYMENTS_TEST_NOW;
    $waiting = payments_test_record($now);
    truthy(blocks_repeat($waiting, $now + 60), 'waiting');
    truthy(blocks_repeat($waiting, $now + 86400), 'waiting without an answer, whatever its age');
    truthy(blocks_repeat(array_merge($waiting, ['status' => 'paid', 'cpay_ref' => '123456']), $now + 400 * 86400), 'paid');

    $failNotice = [['at' => date('c', $now + 600), 'kind' => 'fail', 'via' => 'browser']];
    $noCard = array_merge($waiting, ['status' => 'failed', 'notices' => $failNotice]);
    truthy(!blocks_repeat($noCard, $now + 601), 'failed without a cPay reference: no card was tried');

    $declined = array_merge($noCard, ['cpay_ref' => '123456']);
    truthy(blocks_repeat($declined, $now + 600 + 44 * 60), 'failed with a cPay reference 44 minutes ago');
    truthy(blocks_repeat($declined, $now + 600 + 2699), 'one second short of 45 minutes');
    truthy(!blocks_repeat($declined, $now + 600 + 2700), '45 minutes ago');
    truthy(!blocks_repeat($declined, $now + 600 + 46 * 60), '46 minutes ago');

    $later = array_merge($declined, ['notices' => [...$failNotice, ['at' => date('c', $now + 4000), 'kind' => 'fail', 'via' => 'push']]]);
    truthy(blocks_repeat($later, $now + 600 + 2700), 'a later fail notice prolongs the confirmation');
    truthy(blocks_repeat($later, $now + 4000 + 2699), 'counted from the latest fail notice');
    truthy(!blocks_repeat($later, $now + 4000 + 2700), '45 minutes after the latest fail notice');

    $okBefore = ['at' => date('c', $now + 300), 'kind' => 'ok', 'via' => 'browser'];
    $okAfter = ['at' => date('c', $now + 4000), 'kind' => 'ok', 'via' => 'browser'];
    $okToo = array_merge($declined, ['notices' => [$okBefore, ...$failNotice, $okAfter]]);
    truthy(blocks_repeat($okToo, $now + 600 + 2699), 'an earlier OK notice without a cPay reference is not counted from');
    truthy(!blocks_repeat($okToo, $now + 600 + 2700), 'a later OK notice without a cPay reference is not counted from');

    $unrecorded = array_merge($declined, ['notices' => []]);
    truthy(blocks_repeat($unrecorded, $now + 2699), 'no fail notice kept: counted from the start');
    truthy(!blocks_repeat($unrecorded, $now + 2700), 'no fail notice kept: counted from the start');

    foreach (['yesterday', '', '2026-11-11', "2026-11-11T11:10:00+01:00\n", ['x']] as $notTime) {
        $odd = array_merge($declined, ['notices' => ['text', ['at' => $notTime, 'kind' => 'fail', 'via' => 'browser']]]);
        truthy(blocks_repeat($odd, $now + 2699), 'a notice time that is not ISO 8601 is not used: ' . json_encode($notTime));
        truthy(!blocks_repeat($odd, $now + 2700), 'a notice time that is not ISO 8601 is not used: ' . json_encode($notTime));
    }
});

test('payments: a fail notice that brings a card attempt asks for confirmation for 45 minutes from that notice', function (): void {
    payments_test_private(function (string $private): void {
        $dir = payments_dir($private);
        $record = payments_test_record(PAYMENTS_TEST_NOW);
        create_payment($dir, $record);
        $noCard = apply_notice($dir, $record['ref'], 'fail', 'browser', '', date('c', PAYMENTS_TEST_NOW + 60));
        truthy(!blocks_repeat($noCard, PAYMENTS_TEST_NOW + 61), 'failed, and no card was tried');
        $declined = apply_notice($dir, $record['ref'], 'fail', 'push', '777', date('c', PAYMENTS_TEST_NOW + 3000));
        same('777', $declined['cpay_ref']);
        truthy(blocks_repeat($declined, PAYMENTS_TEST_NOW + 3001), 'cPay has just reported a card attempt');
        truthy(blocks_repeat($declined, PAYMENTS_TEST_NOW + 3000 + 2699), 'one second short of 45 minutes after it');
        truthy(!blocks_repeat($declined, PAYMENTS_TEST_NOW + 3000 + 2700), '45 minutes after it');
    });
});

test('payments: two charges are read off the record', function (): void {
    $record = payments_test_record(PAYMENTS_TEST_NOW);
    truthy(!has_two_charges($record), 'no extra reference');
    truthy(has_two_charges(array_merge($record, ['extra_refs' => ['654321']])), 'an extra reference');
    /* Whether cPay confirmed a row depends on the kind of its push too (design 8): staff_confirmed_by_cpay() and its tests. */
    truthy(!function_exists('push_received'), 'no helper that would count a push of either kind');
});

test('payments: the records of one reservation, newest first, and never those of a longer number', function (): void {
    payments_test_private(function (string $private): void {
        $dir = payments_dir($private);
        same([], payments_for_reservation($dir, '4567890123'), 'no folder yet');
        $older = payments_test_record(PAYMENTS_TEST_NOW, '4567890123', 'ZZZZ');
        $newer = payments_test_record(PAYMENTS_TEST_NOW + 300, '4567890123', 'AAAA');
        $longer = payments_test_record(PAYMENTS_TEST_NOW + 100, '14567890123', 'BBBB');
        $shorter = payments_test_record(PAYMENTS_TEST_NOW + 200, '567890123', 'CCCC');
        $prefix = payments_test_record(PAYMENTS_TEST_NOW + 400, '45678901234', 'DDDD');
        foreach ([$older, $longer, $shorter, $newer, $prefix] as $record) {
            same(true, create_payment($dir, $record));
        }
        same([$newer, $older], payments_for_reservation($dir, '4567890123'));
        same([$shorter], payments_for_reservation($dir, '567890123'));
        same([], payments_for_reservation($dir, '456789'));
    });
});

test('payments: the latest records come newest first, up to the limit', function (): void {
    payments_test_private(function (string $private): void {
        $dir = payments_dir($private);
        same([], latest_payments($dir), 'no folder yet');
        same([], payments_since($dir, 0), 'no folder yet');
        $records = array_map(
            static fn (int $i): array => payments_test_record(PAYMENTS_TEST_NOW + $i * 60, (string) (100000 + $i), 'AAAA', 1000 + $i),
            [3, 0, 4, 1, 2],
        );
        foreach ($records as $record) {
            create_payment($dir, $record);
        }
        $newestFirst = [$records[2], $records[0], $records[4], $records[3], $records[1]];
        same($newestFirst, latest_payments($dir), 'all five, the default limit is 50');
        same(array_slice($newestFirst, 0, 3), latest_payments($dir, 3));
        same([], latest_payments($dir, 0));
        same(array_slice($newestFirst, 0, 3), payments_since($dir, PAYMENTS_TEST_NOW + 120), 'from that second on');
        same(array_slice($newestFirst, 0, 2), payments_since($dir, PAYMENTS_TEST_NOW + 121));
        same([], payments_since($dir, PAYMENTS_TEST_NOW + 241));
        same($newestFirst, payments_since($dir, 0));
    });
});

test('payments: payments_since goes by the time in the reference', function (): void {
    payments_test_private(function (string $private): void {
        $dir = payments_dir($private);
        $issued = substr(new_reference(PAYMENTS_TEST_NOW - 3000), 0, 6) . 'AAAA';
        $record = new_payment($issued, 'recepcija', 21802, '4567890123', 'booking 4567890123', PAYMENTS_TEST_URL, 'md5', PAYMENTS_TEST_NOW);
        create_payment($dir, $record);
        same([], payments_since($dir, PAYMENTS_TEST_NOW - 2999));
        same([$record], payments_since($dir, PAYMENTS_TEST_NOW - 3000));
    });
});

test('payments: the paid total counts and sums paid records only', function (): void {
    $record = payments_test_record(PAYMENTS_TEST_NOW);
    same(['count' => 0, 'sum' => 0], paid_total([]));
    same(['count' => 0, 'sum' => 0], paid_total([$record]));
    same(['count' => 2, 'sum' => 26802], paid_total([
        array_merge($record, ['status' => 'paid']),
        array_merge($record, ['status' => 'waiting', 'amount' => 100]),
        array_merge($record, ['status' => 'failed', 'amount' => 200]),
        array_merge($record, ['status' => 'paid', 'amount' => 5000]),
    ]));
});

test('payments: a record file that does not parse is skipped', function (): void {
    payments_test_private(function (string $private): void {
        $dir = payments_dir($private);
        $good = payments_test_record(PAYMENTS_TEST_NOW, '4567890123', 'GOOD');
        create_payment($dir, $good);
        $time = substr($good['ref'], 0, 6);
        $other = array_merge($good, ['ref' => $time . 'ELSE']);
        $damaged = [
            $time . 'BAD1-4567890123.json' => 'not json',
            $time . 'BAD2-4567890123.json' => '[]',
            $time . 'BAD3-4567890123.json' => '"text"',
            $time . 'BAD4-4567890123.json' => json_encode($other), /* says it is another payment */
            $time . 'BAD5-4567890123.json' => json_encode(array_merge($good, ['ref' => $time . 'BAD5', 'status' => 'refunded'])),
            $time . 'BAD6-4567890123.json' => json_encode(array_merge($good, ['ref' => $time . 'BAD6', 'amount' => '21802'])),
            $time . 'BAD7-4567890123.json' => '',
        ];
        foreach ($damaged as $name => $content) {
            file_put_contents($dir . '/' . $name, $content);
        }
        mkdir($dir . '/' . $time . 'BAD8-4567890123.json'); /* a folder with a record's name */
        same([$good], latest_payments($dir));
        same([$good], payments_for_reservation($dir, '4567890123'));
        same([$good], payments_since($dir, 0));
        foreach (range(1, 8) as $i) {
            same(null, find_payment($dir, $time . 'BAD' . $i), "BAD$i");
            same(null, apply_notice($dir, $time . 'BAD' . $i, 'ok', 'push', '123456', date('c', PAYMENTS_TEST_NOW)), "BAD$i");
        }
        same('not json', file_get_contents($dir . '/' . $time . 'BAD1-4567890123.json'), 'a damaged file is left as it is');
        same(false, create_payment($dir, array_merge($good, ['ref' => $time . 'BAD1'])), 'and its reference stays taken');

        $path = $dir . '/' . $good['ref'] . '-4567890123.json';
        if (PHP_OS_FAMILY === 'Windows' || !chmod($path, 0000)) {
            skip('the last part: read permission cannot be taken away here');
        }
        clearstatcache();
        if (!is_readable($path)) { /* root reads everything */
            same(null, find_payment($dir, $good['ref']), 'a file without read permission');
            same([], latest_payments($dir), 'a file without read permission');
        }
        chmod($path, 0600);
    });
});

test('payments: a record written by hand without the optional parts is read with them filled in', function (): void {
    payments_test_private(function (string $private): void {
        $dir = payments_dir($private);
        mkdir($dir);
        $created = date('c', PAYMENTS_TEST_NOW);
        $short = ['ref' => 'TMZK4A7Q2B', 'created' => $created, 'amount' => 21802, 'reservation' => '4567890123', 'status' => 'waiting'];
        file_put_contents($dir . '/TMZK4A7Q2B-4567890123.json', json_encode($short));
        $read = find_payment($dir, 'TMZK4A7Q2B');
        same($created, $read['updated']);
        same('', $read['cpay_ref']);
        same([], $read['extra_refs']);
        same([], $read['notices']);
        $paid = apply_notice($dir, 'TMZK4A7Q2B', 'ok', 'push', '123456', date('c', PAYMENTS_TEST_NOW + 60));
        same('paid', $paid['status']);
        same($paid, find_payment($dir, 'TMZK4A7Q2B'));
    });
});

test('payments: pruning deletes old records and old inbox files, keeps the rest, and runs once a day', function (): void {
    payments_test_private(function (string $private): void {
        $now = PAYMENTS_TEST_NOW;
        $dir = payments_dir($private);
        $inbox = $private . '/cpay-inbox';
        mkdir($inbox);
        $old = payments_test_record($now - 549 * 86400, '111111');
        $recent = payments_test_record($now - 547 * 86400, '222222');
        create_payment($dir, $old);
        create_payment($dir, $recent);
        file_put_contents($dir . '/notes.txt', 'x');
        file_put_contents($dir . '/' . $old['ref'] . '-111111.json.bak', 'x');
        /* What a write leaves behind when the server stops between writing and renaming: a full copy of a record. */
        $leftover = $dir . '/' . $recent['ref'] . '-222222.json.tmp';
        file_put_contents($leftover, '{}');
        $oldNotice =date('Ymd-His', $now - 100 * 86400) . '-ok-0a1b2c3d.json';
        $recentNotice = date('Ymd-His', $now - 80 * 86400) . '-fail-0a1b2c3d.json';
        $dayBeforeCut = date('Ymd', $now - 91 * 86400) . '-235959-ok-0a1b2c3d.json';
        $cutDay = date('Ymd', $now - 90 * 86400) . '-000000-ok-0a1b2c3d.json';
        $otherNames = ['20200101-000000-ok-0a1b2c3.json', '20200101-000000-ok-0a1b2c3d.json.bak', '20200101-notes.json', 'readme.txt', 'x20200101-000000-ok-0a1b2c3d.json'];
        foreach ([$oldNotice, $dayBeforeCut, $cutDay, $recentNotice, ...$otherNames] as $name) {
            file_put_contents($inbox . '/' . $name, '{}');
        }
        file_put_contents($private . '/config.php', 'x');

        prune_old($private, 548, 90, $now);
        same(null, find_payment($dir, $old['ref']), 'the old record is gone');
        same($recent, find_payment($dir, $recent['ref']), 'the recent record stays');
        truthy(is_file($dir . '/notes.txt') && is_file($dir . '/' . $old['ref'] . '-111111.json.bak'), 'other names in the record folder stay');
        truthy(!file_exists($leftover), 'a left-over temporary copy of a record is gone, so it cannot outlive the retention');
        $kept = [$cutDay, $recentNotice, ...$otherNames];
        sort($kept, SORT_STRING);
        same($kept, payments_test_names($inbox), 'raw copies from before the day 90 days ago are gone; that day, later ones and other names stay');
        truthy(is_file($private . '/config.php'), 'nothing else in the private folder is touched');

        $second = payments_test_record($now - 600 * 86400, '333333');
        create_payment($dir, $second);
        file_put_contents($inbox . '/' . $oldNotice, '{}');
        prune_old($private, 548, 90, $now + 60);
        same($second, find_payment($dir, $second['ref']), 'a second call the same day does nothing');
        truthy(is_file($inbox . '/' . $oldNotice), 'a second call the same day does nothing');

        prune_old($private, 548, 90, $now + 86400);
        same(null, find_payment($dir, $second['ref']), 'the next day it runs again');
        truthy(!file_exists($inbox . '/' . $oldNotice), 'the next day it runs again');
        same($recent, find_payment($dir, $recent['ref']), 'still inside 548 days');
    });
});

test('payments: pruning needs whole days and copes with folders that are not there yet', function (): void {
    payments_test_private(function (string $private): void {
        $dir = payments_dir($private);
        $record = payments_test_record(PAYMENTS_TEST_NOW - 86400);
        create_payment($dir, $record);
        payments_test_thrown(InvalidArgumentException::class, static fn () => prune_old($private, 0, 90, PAYMENTS_TEST_NOW));
        payments_test_thrown(InvalidArgumentException::class, static fn () => prune_old($private, 548, -1, PAYMENTS_TEST_NOW));
        prune_old($private, 548, 90, PAYMENTS_TEST_NOW); /* no inbox folder */
        same($record, find_payment($dir, $record['ref']));
    });
    payments_test_private(function (string $private): void {
        prune_old($private, 548, 90, PAYMENTS_TEST_NOW); /* no folder at all */
        same([], latest_payments(payments_dir($private)));
        unlink(payments_dir($private) . '/.pruned');
        mkdir(payments_dir($private) . '/.pruned'); /* the marker of the day cannot be written */
        same('payments_write', payments_test_thrown(RuntimeException::class, static fn () => prune_old($private, 548, 90, PAYMENTS_TEST_NOW)));
    });
});
