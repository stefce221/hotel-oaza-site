<?php
/* The staff page: who is signed in, which requests may use the page, its secret, its lock and its token. */
declare(strict_types=1);

require_once __DIR__ . '/staff-helpers.php';

test('staff: the user is REMOTE_USER, else REDIRECT_REMOTE_USER', function (): void {
    same('recepcija', staff_user(['REMOTE_USER' => 'recepcija'], 'litespeed'));
    same('recepcija', staff_user(['REDIRECT_REMOTE_USER' => 'recepcija'], 'litespeed'));
    same('prv', staff_user(['REMOTE_USER' => 'prv', 'REDIRECT_REMOTE_USER' => 'vtor'], 'apache2handler'));
    same('vtor', staff_user(['REMOTE_USER' => '', 'REDIRECT_REMOTE_USER' => 'vtor'], 'litespeed'), 'an empty REMOTE_USER counts as none');
    same('', staff_user([], 'litespeed'));
    same('', staff_user(['REMOTE_USER' => '', 'REDIRECT_REMOTE_USER' => ''], 'litespeed'));
});

test('staff: PHP_AUTH_USER counts only under PHP\'s built-in server', function (): void {
    foreach (['litespeed', 'apache2handler', 'fpm-fcgi', 'cgi-fcgi', 'cli', ''] as $sapi) {
        same('', staff_user(['PHP_AUTH_USER' => 'recepcija'], $sapi), $sapi);
    }
    same('recepcija', staff_user(['PHP_AUTH_USER' => 'recepcija'], 'cli-server'));
    same('prv', staff_user(['REMOTE_USER' => 'prv', 'PHP_AUTH_USER' => 'vtor'], 'cli-server'), 'REMOTE_USER comes first there too');
});

test('staff: a user name is 1 to 64 of A-Z a-z 0-9 . _ @ -', function (): void {
    foreach (['a', 'recepcija', 'Ana.Petrova', 'ana_p-2@oaza', str_repeat('a', 64)] as $good) {
        same($good, staff_user(['REMOTE_USER' => $good], 'litespeed'));
    }
    foreach ([str_repeat('a', 65), 'ana petrova', "ana\n", 'ana|b', 'ана', '<b>', 'a:b', "a\0b", ' ana'] as $bad) {
        same('', staff_user(['REMOTE_USER' => $bad], 'litespeed'), json_encode($bad));
    }
    same('', staff_user(['REMOTE_USER' => ['recepcija']], 'litespeed'), 'not text');
    same('', staff_user(['REMOTE_USER' => 'ana petrova', 'REDIRECT_REMOTE_USER' => 'recepcija'], 'litespeed'), 'a bad name is not replaced by the next one');
});

test('staff: without a user nothing else is looked at', function (): void {
    $onSite = ['HTTPS' => 'on', 'HTTP_HOST' => STAFF_TEST_HOST];
    same('staff_no_user', staff_problem($onSite, 'litespeed', STAFF_TEST_HOST));
    same('staff_no_user', staff_problem($onSite + ['PHP_AUTH_USER' => 'recepcija'], 'litespeed', STAFF_TEST_HOST), 'PHP_AUTH_USER is no user on the live server');
    same('staff_no_user', staff_problem($onSite + ['REMOTE_USER' => 'ana petrova'], 'litespeed', STAFF_TEST_HOST));
    same('staff_no_user', staff_problem([], 'litespeed', STAFF_TEST_HOST), 'before the HTTPS and host checks');
    same('staff_no_user', staff_problem([], 'cli-server', ''));
});

test('staff: outside the built-in server the request must be HTTPS', function (): void {
    $request = ['REMOTE_USER' => 'recepcija', 'HTTP_HOST' => STAFF_TEST_HOST];
    foreach ([[], ['HTTPS' => ''], ['HTTPS' => 'off'], ['HTTPS' => 'OFF'], ['HTTP_X_FORWARDED_PROTO' => 'https'], ['HTTPS' => ['on']]] as $https) {
        same('staff_not_https', staff_problem($https + $request, 'litespeed', STAFF_TEST_HOST), json_encode($https));
        same('staff_not_https', staff_problem($https + $request, 'litespeed', ''), 'also when the host is not checked');
    }
    same('', staff_problem(['HTTPS' => 'on'] + $request, 'litespeed', STAFF_TEST_HOST));
    same('', staff_problem(['HTTPS' => 'on'] + $request, 'apache2handler', STAFF_TEST_HOST));
});

test('staff: outside the built-in server the request must be on the site\'s own host', function (): void {
    $request = ['REMOTE_USER' => 'recepcija', 'HTTPS' => 'on'];
    foreach (['oazahotel.test', 'www.oazahotel.test.example', 'xwww.oazahotel.test', STAFF_TEST_HOST . ':8443', ''] as $other) {
        same('staff_wrong_host', staff_problem($request + ['HTTP_HOST' => $other], 'litespeed', STAFF_TEST_HOST), $other);
    }
    same('staff_wrong_host', staff_problem($request, 'litespeed', STAFF_TEST_HOST), 'no Host header');
    foreach ([STAFF_TEST_HOST, 'WWW.OazaHotel.test', STAFF_TEST_HOST . ':443'] as $same) {
        same('', staff_problem($request + ['HTTP_HOST' => $same], 'litespeed', STAFF_TEST_HOST), $same);
    }
    same('', staff_problem($request + ['HTTP_HOST' => 'anything.example'], 'litespeed', ''), 'no host to compare with: the check is skipped');
});

test('staff: under the built-in server the HTTPS and host checks are skipped', function (): void {
    same('', staff_problem(['PHP_AUTH_USER' => 'recepcija', 'HTTP_HOST' => '127.0.0.1:8771'], 'cli-server', STAFF_TEST_HOST));
});

test('staff: the secret is made on first use and stays the same', function (): void {
    staff_test_private(static function (string $private): void {
        $secret = staff_secret($private);
        same(1, preg_match('/\A[0-9a-f]{64}\z/', $secret), '64 hex characters');
        same($secret, file_get_contents($private . '/cpay-staff-secret'));
        same($secret, staff_secret($private), 'the second call reads it');
        staff_test_private(static function (string $other) use ($secret): void {
            truthy(staff_secret($other) !== $secret, 'another folder gets another secret');
        });
    });
});

test('staff: the secret file is readable by the owner only', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        skip('file modes: Windows does not enforce them');
    }
    staff_test_private(static function (string $private): void {
        staff_secret($private);
        clearstatcache();
        same(0600, fileperms($private . '/cpay-staff-secret') & 0777, 'readable by the owner only');
    });
});

test('staff: a secret file that holds something else is replaced', function (): void {
    staff_test_private(static function (string $private): void {
        foreach (['', 'not a secret', str_repeat('A', 64), str_repeat('a', 63), str_repeat('a', 64) . "\n"] as $stored) {
            file_put_contents($private . '/cpay-staff-secret', $stored);
            $secret = staff_secret($private);
            same(1, preg_match('/\A[0-9a-f]{64}\z/', $secret), json_encode($stored));
            truthy($secret !== $stored, 'a new one');
            same($secret, file_get_contents($private . '/cpay-staff-secret'), 'and nothing of the old content is left');
        }
    });
});

test('staff: without a private folder there is no secret', function (): void {
    staff_test_private(static function (string $private): void {
        try {
            staff_secret($private . '/no-such-folder');
            truthy(false, 'should have thrown');
        } catch (RuntimeException $e) {
            same('staff_secret', $e->getMessage());
        }
        truthy(!is_dir($private . '/no-such-folder'), 'the folder is not made');
    });
});

test('staff: the page\'s own lock lets one request in at a time', function (): void {
    staff_test_private(static function (string $private): void {
        same('done', staff_locked($private, static fn (): string => 'done'), 'what the work returns');
        truthy(is_file($private . '/cpay-staff-start.lock'), 'the lock is a file of the staff page\'s own');
        truthy(!file_exists(payments_dir($private)), 'the store and its lock are not touched');
        $ran = [];
        $second = static function (int $tries) use ($private, &$ran): string {
            try {
                staff_locked($private, static function () use ($tries, &$ran): void {
                    $ran[] = $tries;
                }, $tries);
                return 'went in';
            } catch (RuntimeException $e) {
                return $e->getMessage();
            }
        };
        $while = staff_locked($private, static fn (): array => [$second(1), $second(2), $second(0)]);
        same(['staff_lock', 'staff_lock', 'staff_lock'], $while, 'while one is inside, another is refused once its tries are used up');
        same([], $ran, 'and its work is not done');
        same('went in', $second(1), 'afterwards the lock is free');
        same('went in', $second(0), 'it is asked for at least once');
        try {
            staff_locked($private, static function (): void {
                throw new RuntimeException('payments_write');
            });
            truthy(false, 'should have thrown');
        } catch (RuntimeException $e) {
            same('payments_write', $e->getMessage(), 'what the work throws comes through');
        }
        same('went in', $second(1), 'and the lock is free after work that failed');
    });
});

test('staff: the page\'s lock file is readable by the owner only', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        skip('file modes: Windows does not enforce them');
    }
    staff_test_private(static function (string $private): void {
        staff_locked($private, static fn (): string => 'done');
        clearstatcache();
        same(0600, fileperms($private . '/cpay-staff-start.lock') & 0777, 'readable by the owner only');
    });
});

test('staff: by default the page\'s lock is waited for about ten seconds, so a start that comes right after another goes in', function (): void {
    $wait = STAFF_LOCK_TRIES * STAFF_LOCK_PAUSE_MICROSECONDS;
    truthy($wait >= 5000000 && $wait <= 15000000, "about ten seconds (design 4.3), not $wait microseconds");
    if (!function_exists('proc_open')) {
        skip('no second process can be started here');
    }
    staff_test_private(static function (string $private): void {
        $lock = $private . '/cpay-staff-start.lock';
        $holder = "\$lock = fopen(\$argv[1], 'c'); flock(\$lock, LOCK_EX); echo 'held'; usleep(300000); flock(\$lock, LOCK_UN);";
        $process = proc_open([PHP_BINARY, '-r', $holder, $lock], [1 => ['pipe', 'w']], $pipes);
        truthy(is_resource($process), 'a second PHP process starts');
        try {
            same('held', fread($pipes[1], 4), 'the other request is inside');
            $began = microtime(true);
            same('went in', staff_locked($private, static fn (): string => 'went in'), 'with the default tries the work waits, then runs');
            truthy(microtime(true) - $began >= 0.1, 'it did wait');
        } finally {
            fclose($pipes[1]);
            proc_close($process);
        }
    });
});

test('staff: without a private folder, or with a folder where the lock file should be, there is no lock', function (): void {
    staff_test_private(static function (string $private): void {
        mkdir($private . '/taken');
        mkdir($private . '/taken/cpay-staff-start.lock');
        foreach ([$private . '/no-such-folder', $private . '/taken'] as $folder) {
            $ran = false;
            try {
                staff_locked($folder, static function () use (&$ran): void {
                    $ran = true;
                });
                truthy(false, 'should have thrown');
            } catch (RuntimeException $e) {
                same('staff_lock', $e->getMessage());
            }
            truthy(!$ran, 'the work is not done');
        }
        truthy(!is_dir($private . '/no-such-folder'), 'the folder is not made');
    });
});

test('staff: the token is the HMAC-SHA256 of user, reference and hour', function (): void {
    $token = staff_token(STAFF_TEST_SECRET, 'recepcija', 'TMZK4A7Q2B', 498442);
    same(hash_hmac('sha256', 'recepcija|TMZK4A7Q2B|498442', STAFF_TEST_SECRET), $token);
    same(1, preg_match('/\A[0-9a-f]{64}\z/', $token));
    truthy($token !== staff_token(STAFF_TEST_SECRET, 'ana', 'TMZK4A7Q2B', 498442), 'another user');
    truthy($token !== staff_token(STAFF_TEST_SECRET, 'recepcija', 'TMZK4A7Q2C', 498442), 'another reference');
    truthy($token !== staff_token(STAFF_TEST_SECRET, 'recepcija', 'TMZK4A7Q2B', 498443), 'another hour');
    truthy($token !== staff_token(str_repeat('f', 64), 'recepcija', 'TMZK4A7Q2B', 498442), 'another secret');
});

test('staff: a token is good for its hour and the one after it', function (): void {
    $token = staff_token(STAFF_TEST_SECRET, 'recepcija', 'TMZK4A7Q2B', intdiv(STAFF_TEST_NOW, 3600));
    $at = static fn (int $now): bool => staff_token_ok(STAFF_TEST_SECRET, 'recepcija', 'TMZK4A7Q2B', $token, $now);
    truthy($at(STAFF_TEST_NOW), 'the first second of its hour');
    truthy($at(STAFF_TEST_NOW + 3599), 'the last second of its hour');
    truthy($at(STAFF_TEST_NOW + 7199), 'the last second of the next hour');
    truthy(!$at(STAFF_TEST_NOW + 7200), 'two hours later');
    truthy(!$at(STAFF_TEST_NOW - 1), 'a token of the hour to come');
});

test('staff: a token for another user, reference or secret is refused, and so is anything that is not a token', function (): void {
    $token = staff_token(STAFF_TEST_SECRET, 'recepcija', 'TMZK4A7Q2B', intdiv(STAFF_TEST_NOW, 3600));
    truthy(staff_token_ok(STAFF_TEST_SECRET, 'recepcija', 'TMZK4A7Q2B', $token, STAFF_TEST_NOW), 'the token itself');
    truthy(!staff_token_ok(STAFF_TEST_SECRET, 'ana', 'TMZK4A7Q2B', $token, STAFF_TEST_NOW), 'another user');
    truthy(!staff_token_ok(STAFF_TEST_SECRET, 'recepcija', 'TMZK4A7Q2C', $token, STAFF_TEST_NOW), 'another reference');
    truthy(!staff_token_ok(str_repeat('f', 64), 'recepcija', 'TMZK4A7Q2B', $token, STAFF_TEST_NOW), 'another secret');
    foreach (['', 'x', strtoupper($token), $token . 'a', substr($token, 0, 63), $token . "\n", str_repeat('0', 64)] as $other) {
        truthy(!staff_token_ok(STAFF_TEST_SECRET, 'recepcija', 'TMZK4A7Q2B', $other, STAFF_TEST_NOW), json_encode($other));
    }
    $unkeyed = hash_hmac('sha256', 'recepcija|TMZK4A7Q2B|' . intdiv(STAFF_TEST_NOW, 3600), '');
    truthy(!staff_token_ok('', 'recepcija', 'TMZK4A7Q2B', $unkeyed, STAFF_TEST_NOW), 'an empty secret verifies nothing');
});

test('staff: the rules that are written twice agree (reservation number, reference)', function (): void {
    $reservations = ['123456', '4567890123', str_repeat('9', 20), '000000', '12345', str_repeat('9', 21), '', '45678901a3', ' 4567890123',
        '4567890123 ', "4567890123\n", '4567 890123', "\u{FF14}\u{FF15}\u{FF16}\u{FF17}\u{FF18}\u{FF19}", '-123456', '1234.56', '../../x', '"><script>'];
    foreach ($reservations as $typed) {
        same(valid_reservation($typed), payment_reservation_ok($typed), 'reservation ' . json_encode($typed));
    }
    same([true, true, true, true, false], array_map('valid_reservation', array_slice($reservations, 0, 5)), 'the list holds good and bad numbers');
    $references = ['TMZK4A7Q2B', '0000000000', 'ZZZZZZZZZZ', '', 'TMZK4A7Q2', 'TMZK4A7Q2BB', 'tmzk4a7q2b', "TMZK4A7Q2B\n", 'TMZK4A7Q2-', '../x',
        "TMZK4A7Q2\u{0411}", ' TMZK4A7Q2B', 'TMZK4A7Q2B '];
    foreach ($references as $ref) {
        same(preg_match(CHARGE_REFERENCE, $ref) === 1, valid_reference($ref), 'reference ' . json_encode($ref));
    }
    same([true, true, true, false], array_map('valid_reference', array_slice($references, 0, 4)), 'the list holds good and bad references');
});
