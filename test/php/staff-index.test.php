<?php
/* GET /naplata/ (staff_index_answer()): the form for a signed-in user, the redirect to the site's own host, the
   refusals, the list with the day's total, and the pruning of the day. */
declare(strict_types=1);

require_once __DIR__ . '/staff-helpers.php';

test('staff: the page of a signed-in user carries a form with a fresh reference and its token', function (): void {
    staff_test_private(static function (string $private): void {
        $answer = staff_index_answer(staff_test_config($private), staff_test_server(['REQUEST_METHOD' => 'GET']), 'litespeed', STAFF_TEST_NOW);
        same(200, $answer['status']);
        same([], $answer['headers']);
        $fields = staff_test_hidden($answer['html']);
        same(['ref', 'token'], array_keys($fields));
        same(STAFF_TEST_NOW, reference_time($fields['ref']), 'a reference of this moment');
        truthy(staff_token_ok(staff_secret($private), 'recepcija', $fields['ref'], $fields['token'], STAFF_TEST_NOW), 'and its token');
        truthy(str_contains($answer['html'], 'Најавени сте како: <strong>recepcija</strong>'), 'the user');
        truthy(str_contains($answer['html'], 'data-max="300000"'), 'the limit for the echo');
        same([], staff_test_records($private), 'opening the page starts nothing');
    });
});

test('staff: index.php sends another host to the right address, and refuses plain HTTP and a missing user', function (): void {
    staff_test_private(static function (string $private): void {
        $config = staff_test_config($private);
        $get = static fn (array $changes): array => staff_index_answer($config, staff_test_server(['REQUEST_METHOD' => 'GET'] + $changes), 'litespeed', STAFF_TEST_NOW);
        $moved = $get(['HTTP_HOST' => 'oazahotel.test']);
        same(302, $moved['status']);
        same(['Location: https://www.oazahotel.test/naplata/'], $moved['headers']);
        truthy(!str_contains($moved['html'], '<form'), 'no form on the way');
        foreach (['staff_not_https' => ['HTTPS' => ''], 'staff_no_user' => ['REMOTE_USER' => '', 'PHP_AUTH_USER' => 'recepcija']] as $code => $changes) {
            $refused = $get($changes);
            same(403, $refused['status'], $code);
            same([], $refused['headers'], $code);
            truthy(str_contains($refused['html'], '<code>' . $code . '</code>') && !str_contains($refused['html'], '<form'), $code);
        }
        same("staff_not_https\nstaff_no_user\n", preg_replace('/^\S+ /m', '', staff_test_log($private)), 'the refusals are logged by code');
        truthy(!is_file($private . '/cpay-staff-secret') && !is_dir(payments_dir($private)), 'nothing was made for them');
    });
});

test('staff: without the key index.php shows "not set up" and the list, and a wrong ok_url skips the host check', function (): void {
    staff_test_private(static function (string $private): void {
        create_payment(payments_dir($private), staff_test_record(STAFF_TEST_NOW - 60));
        $answer = staff_index_answer(staff_test_config($private, ['key' => '']), staff_test_server(['REQUEST_METHOD' => 'GET']), 'litespeed', STAFF_TEST_NOW);
        same(200, $answer['status']);
        truthy(str_contains($answer['html'], 'Наплатата не е поставена до крај.') && !str_contains($answer['html'], '<form'), 'no form');
        truthy(str_contains($answer['html'], 'Чека одговор'), 'the list is there');
        truthy(!is_file($private . '/cpay-staff-secret'), 'no secret is needed yet');
        $elsewhere = staff_index_answer(staff_test_config($private, ['ok_url' => 'http://x']), staff_test_server(['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'other.example']), 'litespeed', STAFF_TEST_NOW);
        same(200, $elsewhere['status'], 'no host to compare with');
        truthy(str_contains($elsewhere['html'], 'Наплатата не е поставена до крај.'), 'and nothing can be charged');
    });
});

test('staff: "today" is the day in Skopje, and only today\'s paid charges are added up', function (): void {
    same(STAFF_TEST_DAY, staff_day_start(STAFF_TEST_NOW));
    same(STAFF_TEST_DAY, staff_day_start(STAFF_TEST_DAY), 'midnight belongs to the new day');
    same(STAFF_TEST_DAY - 86400, staff_day_start(STAFF_TEST_DAY - 1));
    same(1782856800, staff_day_start(1782907200), 'in summer time: 1 July 2026, 12:00 UTC');
    staff_test_private(static function (string $private): void {
        $dir = payments_dir($private);
        create_payment($dir, staff_test_record(STAFF_TEST_DAY + 60, ['status' => 'paid', 'cpay_ref' => '1'], 'AAA1'));
        create_payment($dir, staff_test_record(STAFF_TEST_DAY - 60, ['status' => 'paid', 'cpay_ref' => '2', 'amount' => 1000], 'AAA2'));
        create_payment($dir, staff_test_record(STAFF_TEST_DAY + 120, ['amount' => 700], 'AAA3'));
        $answer = staff_index_answer(staff_test_config($private), staff_test_server(['REQUEST_METHOD' => 'GET']), 'litespeed', STAFF_TEST_NOW);
        truthy(str_contains($answer['html'], 'Денес наплатено: 1 наплата, 21.802 ден.'), 'yesterday\'s and the waiting one are left out');
        same(3, substr_count($answer['html'], '<tr class='), 'all three are listed');
    });
});

test('staff: a charge counts for the day it was started, which its row shows, also from a form opened before midnight', function (): void {
    staff_test_private(static function (string $private): void {
        $dir = payments_dir($private);
        $started = static fn (int $time): array => ['created' => date('c', $time), 'updated' => date('c', $time)];
        /* Forms opened 10 minutes and 1 h 50 min before midnight in Skopje, posted after it: today's. */
        create_payment($dir, staff_test_record(STAFF_TEST_DAY - 600, ['status' => 'paid', 'cpay_ref' => '1', 'amount' => 1000] + $started(STAFF_TEST_DAY + 30), 'LATE'));
        create_payment($dir, staff_test_record(STAFF_TEST_DAY - 6600, ['status' => 'paid', 'cpay_ref' => '2', 'amount' => 300] + $started(STAFF_TEST_DAY + 60), 'OLD2'));
        /* A form opened and posted before midnight: yesterday's. */
        create_payment($dir, staff_test_record(STAFF_TEST_DAY - 900, ['status' => 'paid', 'cpay_ref' => '3', 'amount' => 50] + $started(STAFF_TEST_DAY - 300), 'EVE1'));
        $answer = staff_index_answer(staff_test_config($private), staff_test_server(['REQUEST_METHOD' => 'GET']), 'litespeed', STAFF_TEST_NOW);
        truthy(str_contains($answer['html'], 'Денес наплатено: 2 наплати, 1.300 ден.'), 'the two started after midnight count, the one started before does not');
        truthy(str_contains(staff_test_row($answer['html'], 'LATE</td>'), '11.11.2026 00:00'), 'its row shows the time it was started');
    });
});

test('staff: the list shows the latest 50 charges, and the day\'s total counts the others too', function (): void {
    staff_test_private(static function (string $private): void {
        $dir = payments_dir($private);
        $refs = [];
        for ($i = 1; $i <= 51; $i++) {
            $record = staff_test_record(STAFF_TEST_NOW - $i * 60, $i === 51 ? ['status' => 'paid', 'cpay_ref' => '51'] : [], sprintf('N%03d', $i));
            create_payment($dir, $record);
            $refs[] = $record['ref'];
        }
        $answer = staff_index_answer(staff_test_config($private), staff_test_server(['REQUEST_METHOD' => 'GET']), 'litespeed', STAFF_TEST_NOW);
        same(50, substr_count($answer['html'], '<tr class='), 'fifty rows');
        truthy(staff_test_row($answer['html'], $refs[0] . '</td>') !== '' && staff_test_row($answer['html'], $refs[49] . '</td>') !== '', 'the newest and the fiftieth are listed');
        same('', staff_test_row($answer['html'], $refs[50] . '</td>'), 'the oldest of the 51 is not');
        truthy(str_contains($answer['html'], 'Денес наплатено: 1 наплата, 21.802 ден.'), 'a charge of today that is no longer listed still counts');
        same(51, count(staff_test_records($private)), 'nothing was deleted for it');
    });
});

test('staff: opening the page prunes old records and old raw copies, and a failure there does not stop the page', function (): void {
    staff_test_private(static function (string $private): void {
        $dir = payments_dir($private);
        $inbox = $private . '/cpay-inbox';
        mkdir($inbox);
        $copy = static fn (int $daysBack): string => date('Ymd', STAFF_TEST_NOW - $daysBack * 86400) . '-101500-ok-0a1b2c3d.json';
        foreach ([91, 90, 89] as $daysBack) {
            file_put_contents($inbox . '/' . $copy($daysBack), '{}');
        }
        create_payment($dir, staff_test_record(STAFF_TEST_NOW - 549 * 86400, [], 'OLD1'));
        create_payment($dir, staff_test_record(STAFF_TEST_NOW - 547 * 86400, [], 'NEW1'));
        $answer = staff_index_answer(staff_test_config($private), staff_test_server(['REQUEST_METHOD' => 'GET']), 'litespeed', STAFF_TEST_NOW);
        same(200, $answer['status']);
        same(1, count(staff_test_records($private)), 'the record past 548 days is gone');
        truthy(str_contains(staff_test_records($private)[0], 'NEW1'), 'the younger one stays');
        same([$copy(90), $copy(89)], array_values(array_diff(scandir($inbox), ['.', '..'])), 'the raw copy of 91 days back is gone; the 90th day back and later ones stay');
        same(date('Ymd', STAFF_TEST_NOW), file_get_contents($dir . '/.pruned'));
    });
    $configured = [30 => [29 => true, 31 => false], 3650 => [600 => true, 3651 => false]];
    foreach ($configured as $days => $kept) {
        staff_test_private(static function (string $private) use ($days, $kept): void {
            foreach (array_keys($kept) as $daysBack) {
                create_payment(payments_dir($private), staff_test_record(STAFF_TEST_NOW - $daysBack * 86400, [], sprintf('D%03d', $daysBack % 1000)));
            }
            $answer = staff_index_answer(staff_test_config($private, ['retention_days' => $days]), staff_test_server(['REQUEST_METHOD' => 'GET']), 'litespeed', STAFF_TEST_NOW);
            same(200, $answer['status']);
            foreach ($kept as $daysBack => $stays) {
                $left = array_filter(staff_test_records($private), static fn (string $name): bool => str_contains($name, sprintf('D%03d', $daysBack % 1000)));
                same($stays, $left !== [], "retention_days $days: a record $daysBack days old " . ($stays ? 'stays' : 'is deleted'));
            }
        });
    }
    staff_test_private(static function (string $private): void {
        create_payment(payments_dir($private), staff_test_record(STAFF_TEST_NOW - 40 * 86400, [], 'KEEP'));
        $answer = staff_index_answer(staff_test_config($private, ['retention_days' => 29]), staff_test_server(['REQUEST_METHOD' => 'GET']), 'litespeed', STAFF_TEST_NOW);
        same(200, $answer['status']);
        same(1, count(staff_test_records($private)), 'a wrong number of days deletes nothing');
    });
    staff_test_private(static function (string $private): void {
        file_put_contents(payments_dir($private), 'a file where the folder should be');
        $answer = staff_index_answer(staff_test_config($private), staff_test_server(['REQUEST_METHOD' => 'GET']), 'litespeed', STAFF_TEST_NOW);
        same(200, $answer['status'], 'the page is still shown');
        truthy(str_contains($answer['html'], 'name="amount"') && str_contains($answer['html'], 'Сè уште нема наплати.'), 'with the form and an empty list');
        truthy(str_contains(staff_test_log($private), ' staff_prune_failed'), 'and the failure is logged by code');
    });
});
