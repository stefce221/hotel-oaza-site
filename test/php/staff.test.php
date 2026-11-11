<?php
/* POST /naplata/start.php (staff_start_answer()): the refusals, the start of a payment, the stale form, the bad
   input, the question before a reservation is charged again, the page's lock and the hourly cap. */
declare(strict_types=1);

require_once __DIR__ . '/staff-helpers.php';

test('staff: start.php refuses, in this order, no user, plain HTTP, the wrong host, another origin, another body, missing settings', function (): void {
    staff_test_private(static function (string $private): void {
        $config = staff_test_config($private);
        $post = staff_test_fields($config, STAFF_TEST_NOW);
        $cases = [
            'staff_no_user' => [403, ['REMOTE_USER' => '', 'HTTPS' => '', 'HTTP_HOST' => 'x.example', 'HTTP_ORIGIN' => '', 'CONTENT_TYPE' => '']],
            'staff_not_https' => [403, ['HTTPS' => '', 'HTTP_HOST' => 'x.example', 'HTTP_ORIGIN' => '', 'CONTENT_TYPE' => '']],
            'staff_wrong_host' => [403, ['HTTP_HOST' => 'oazahotel.test', 'HTTP_ORIGIN' => 'https://oazahotel.test', 'CONTENT_TYPE' => '']],
            'staff_other_origin' => [403, ['HTTP_ORIGIN' => 'https://www.booking.example', 'CONTENT_TYPE' => 'text/plain']],
            'staff_not_a_form' => [400, ['CONTENT_TYPE' => 'multipart/form-data; boundary=x']],
        ];
        foreach ($cases as $code => [$status, $changes]) {
            $answer = staff_start_answer($config, staff_test_server($changes), $post, 'litespeed', STAFF_TEST_NOW);
            same($status, $answer['status'], $code);
            truthy(str_contains($answer['html'], '<code>' . $code . '</code>'), $code . ' is on the page');
            same([], staff_test_hidden($answer['html']), $code . ': nothing for cPay');
        }
        $noOrigin = staff_test_server();
        unset($noOrigin['HTTP_ORIGIN']);
        same(403, staff_start_answer($config, $noOrigin, $post, 'litespeed', STAFF_TEST_NOW)['status'], 'no Origin and no Referer');
        $noKey = staff_start_answer(staff_test_config($private, ['key' => '']), staff_test_server(), $post, 'litespeed', STAFF_TEST_NOW);
        same(503, $noKey['status']);
        truthy(str_contains($noKey['html'], 'Наплатата не е поставена до крај. Јавете му се на сопственикот.'), 'not set up');
        same([], staff_test_records($private), 'none of them started a payment');
    });
});

test('staff: over HTTPS the form must come from the https page: an http, ftp or scheme-less Origin is another origin', function (): void {
    staff_test_private(static function (string $private): void {
        $config = staff_test_config($private);
        $post = staff_test_fields($config, STAFF_TEST_NOW);
        $sources = [
            ['HTTP_ORIGIN' => 'http://' . STAFF_TEST_HOST],
            ['HTTP_ORIGIN' => 'ftp://' . STAFF_TEST_HOST],
            ['HTTP_ORIGIN' => '//' . STAFF_TEST_HOST],
            ['HTTP_ORIGIN' => null, 'HTTP_REFERER' => 'http://' . STAFF_TEST_HOST . '/naplata/'],
        ];
        foreach ($sources as $changes) {
            $server = array_filter(staff_test_server($changes), static fn (mixed $value): bool => $value !== null);
            $answer = staff_start_answer($config, $server, $post, 'litespeed', STAFF_TEST_NOW);
            same(403, $answer['status'], (string) ($changes['HTTP_ORIGIN'] ?? $changes['HTTP_REFERER']));
            truthy(str_contains($answer['html'], '<code>staff_other_origin</code>'), 'refused as another origin');
        }
        same([], staff_test_records($private), 'none of them started a payment');
        $referer = array_filter(staff_test_server(['HTTP_ORIGIN' => null, 'HTTP_REFERER' => 'https://' . STAFF_TEST_HOST . '/naplata/']), static fn (mixed $value): bool => $value !== null);
        same(200, staff_start_answer($config, $referer, $post, 'litespeed', STAFF_TEST_NOW)['status'], 'the https page itself, named by its Referer, goes on');
    });
    same(true, staff_same_origin(['HTTP_HOST' => '127.0.0.1:8771', 'HTTP_ORIGIN' => 'http://127.0.0.1:8771']), 'plain HTTP on the built-in server');
    same(false, staff_same_origin(['HTTP_HOST' => '127.0.0.1:8771', 'HTTP_ORIGIN' => 'https://127.0.0.1:8771']), 'and there an https Origin is another origin');
});

test('staff: a good POST writes one waiting record and answers with the form for cPay; the same POST again starts nothing', function (): void {
    staff_test_private(static function (string $private): void {
        $config = staff_test_config($private);
        $post = staff_test_fields($config, STAFF_TEST_NOW);
        $answer = staff_start_answer($config, staff_test_server(), $post, 'litespeed', STAFF_TEST_NOW + 30);
        same(200, $answer['status']);
        same([], $answer['headers']);
        $expected = new_payment($post['ref'], 'recepcija', 21802, '4567890123', 'booking 4567890123', CPAY_DEFAULT_URL, 'md5', STAFF_TEST_NOW + 30);
        same($expected, find_payment(payments_dir($private), $post['ref']), 'the record');
        $request = charge_request(cpay_settings($config), 21802, '4567890123', $post['ref']);
        same($request['fields'], staff_test_hidden($answer['html']), 'the ten fields, signed');
        truthy(str_contains($answer['html'], '<form method="post" action="' . esc(CPAY_DEFAULT_URL) . '">'), 'to the payment address');
        truthy(!str_contains($answer['html'], 'TEST_PASS'), 'never the key');
        $again = staff_start_answer($config, staff_test_server(), $post, 'litespeed', STAFF_TEST_NOW + 40);
        same(409, $again['status']);
        truthy(str_contains($again['html'], 'Оваа наплата е веќе започната. Не ја праќаме повторно. Погледнете ја листата.'), 'already started');
        same([], staff_test_hidden($again['html']), 'nothing for cPay');
        same(1, count(staff_test_records($private)), 'still one record');
        same($expected, find_payment(payments_dir($private), $post['ref']), 'unchanged');
    });
});

test('staff: a reference whose record file cannot be read is taken all the same', function (): void {
    staff_test_private(static function (string $private): void {
        $config = staff_test_config($private);
        $post = staff_test_fields($config, STAFF_TEST_NOW);
        $dir = payments_dir($private);
        mkdir($dir);
        file_put_contents($dir . '/' . $post['ref'] . '-4567890123.json', '{not json');
        truthy(staff_reference_taken($dir, $post['ref']), 'taken');
        truthy(!staff_reference_taken($dir, 'TMZK4A7Q2B') && !staff_reference_taken($dir . '/none', $post['ref']), 'others are free');
        $answer = staff_start_answer($config, staff_test_server(), $post, 'litespeed', STAFF_TEST_NOW);
        same(409, $answer['status']);
        same([], staff_test_hidden($answer['html']));
    });
});

test('staff: a stale or foreign token shows the form again with what was typed and a fresh reference', function (): void {
    staff_test_private(static function (string $private): void {
        $config = staff_test_config($private);
        $post = staff_test_fields($config, STAFF_TEST_NOW, ['amount' => '21.802', 'reservation' => '45678']);
        $cases = [
            'two hours later' => [$post, 'recepcija', STAFF_TEST_NOW + 7200],
            'a changed token' => [['token' => strrev($post['token'])] + $post, 'recepcija', STAFF_TEST_NOW],
            'the token of another user' => [$post, 'ana', STAFF_TEST_NOW],
            'no token' => [['token' => ''] + $post, 'recepcija', STAFF_TEST_NOW],
            'a token that is a list' => [['token' => ['x']] + $post, 'recepcija', STAFF_TEST_NOW],
            'another reference' => [['ref' => 'TMZK4A7Q2B'] + $post, 'recepcija', STAFF_TEST_NOW],
            'no reference' => [['ref' => '../x'] + $post, 'recepcija', STAFF_TEST_NOW],
        ];
        foreach ($cases as $label => [$fields, $user, $now]) {
            $answer = staff_start_answer($config, staff_test_server(['REMOTE_USER' => $user]), $fields, 'litespeed', $now);
            same(409, $answer['status'], $label);
            truthy(str_contains($answer['html'], 'Страницата беше отворена предолго.'), $label . ': the message');
            truthy(!str_contains($answer['html'], 'Износот не е добар'), $label . ': the typed values are not judged yet');
            truthy(str_contains($answer['html'], 'name="amount" value="21.802"') && str_contains($answer['html'], 'name="reservation" value="45678"'), $label . ': typed values');
            $fresh = staff_test_hidden($answer['html']);
            truthy($fresh['ref'] !== $post['ref'] && staff_token_ok(staff_secret($private), $user, $fresh['ref'], $fresh['token'], $now), $label . ': a fresh reference and token');
        }
        same([], staff_test_records($private));
        $late = staff_start_answer($config, staff_test_server(), ['amount' => '21802', 'reservation' => '4567890123'] + $post, 'litespeed', STAFF_TEST_NOW + 7199);
        same(200, $late['status'], 'the last second of the next hour is still in time');
    });
});

test('staff: a bad amount or reservation number shows the form again with the message and starts nothing', function (): void {
    staff_test_private(static function (string $private): void {
        $config = staff_test_config($private);
        $format = 'Износот не е добар. Внесете го само со цифри, без точки и запирки (пример: 21802).';
        $limit = 'Износот е поголем од дозволениот. Проверете дали има цифра повеќе.';
        $reservation = 'Бројот на резервација не е добар. Внесете го само со цифри, како што стои во Booking.';
        $cases = [
            [['amount' => '21.802'], [$format]], [['amount' => '0'], [$format]], [['amount' => ''], [$format]], [['amount' => ' 21802'], [$format]],
            [['amount' => ['21802']], [$format]], [['amount' => '300001'], [$limit]], [['amount' => '12345678'], [$limit]],
            [['reservation' => '45678901a3'], [$reservation]], [['reservation' => ''], [$reservation]], [['reservation' => '12345'], [$reservation]],
            [['amount' => '21802,00', 'reservation' => '"><script>'], [$format, $reservation]],
        ];
        foreach ($cases as [$typed, $messages]) {
            $answer = staff_start_answer($config, staff_test_server(), staff_test_fields($config, STAFF_TEST_NOW, $typed), 'litespeed', STAFF_TEST_NOW);
            same(422, $answer['status'], json_encode($typed));
            foreach ([$format, $limit, $reservation] as $message) {
                same(in_array($message, $messages, true), str_contains($answer['html'], $message), json_encode($typed) . ': ' . $message);
            }
            truthy(!str_contains($answer['html'], '<script>alert') && !str_contains($answer['html'], '"><script>'), 'escaped');
            same(['ref', 'token'], array_keys(staff_test_hidden($answer['html'])), 'the form again');
        }
        same([], staff_test_records($private));
        $max = staff_start_answer($config, staff_test_server(), staff_test_fields($config, STAFF_TEST_NOW, ['amount' => '300000']), 'litespeed', STAFF_TEST_NOW);
        same(200, $max['status'], 'the limit itself is allowed');
    });
});

test('staff: of a typed value that is too long, only the first 64 bytes are shown again', function (): void {
    staff_test_private(static function (string $private): void {
        $config = staff_test_config($private);
        $long = str_repeat('1234567890', 10);
        $cut = substr($long, 0, 64);
        $refused = staff_start_answer($config, staff_test_server(), staff_test_fields($config, STAFF_TEST_NOW, ['amount' => $long, 'reservation' => $long]), 'litespeed', STAFF_TEST_NOW);
        $stale = staff_start_answer($config, staff_test_server(), ['amount' => $long, 'reservation' => $long], 'litespeed', STAFF_TEST_NOW);
        foreach ([422 => $refused, 409 => $stale] as $status => $answer) {
            same($status, $answer['status']);
            truthy(str_contains($answer['html'], 'name="amount" value="' . $cut . '"'), $status . ': the amount ends after 64 characters');
            truthy(str_contains($answer['html'], 'name="reservation" value="' . $cut . '"'), $status . ': the reservation too');
            truthy(!str_contains($answer['html'], $cut . '5'), $status . ': the 65th character is nowhere on the page');
        }
        $exact = staff_start_answer($config, staff_test_server(), ['amount' => $cut, 'reservation' => substr($cut, 0, 63)], 'litespeed', STAFF_TEST_NOW);
        truthy(str_contains($exact['html'], 'name="amount" value="' . $cut . '"') && str_contains($exact['html'], 'name="reservation" value="' . substr($cut, 0, 63) . '"'), 'up to 64 bytes nothing is cut');
        $letters = staff_start_answer($config, staff_test_server(), ['amount' => str_repeat('a', 63) . 'жж', 'reservation' => '1'], 'litespeed', STAFF_TEST_NOW);
        truthy(str_contains($letters['html'], 'name="amount" value="' . str_repeat('a', 63) . "\u{FFFD}" . '"'), 'a letter cut in two is shown as a replacement character');
        same(1, preg_match('//u', $letters['html']), 'and the page stays valid UTF-8');
        same([], staff_test_records($private));
    });
});

test('staff: a reservation that was charged before asks first, and goes on with confirm=yes', function (): void {
    staff_test_private(static function (string $private): void {
        $config = staff_test_config($private);
        $dir = payments_dir($private);
        $fail = [['at' => date('c', STAFF_TEST_NOW - 2760), 'kind' => 'fail', 'via' => 'push']];
        $paidRef = substr(new_reference(STAFF_TEST_NOW - 600), 0, 6) . 'PAID';
        create_payment($dir, staff_test_record(STAFF_TEST_NOW - 600, ['status' => 'paid', 'cpay_ref' => '123456'], 'PAID'));
        create_payment($dir, staff_test_record(STAFF_TEST_NOW - 3000, ['status' => 'failed', 'cpay_ref' => '5', 'notices' => $fail, 'reservation' => '111111'], 'FAIL'));
        create_payment($dir, staff_test_record(STAFF_TEST_NOW - 4000, ['status' => 'failed', 'cpay_ref' => '6', 'notices' => $fail], 'OLDF'));
        $post = staff_test_fields($config, STAFF_TEST_NOW);
        $asked = staff_start_answer($config, staff_test_server(), $post, 'litespeed', STAFF_TEST_NOW);
        same(200, $asked['status']);
        truthy(str_contains($asked['html'], 'За резервација 4567890123 веќе има наплата:') && str_contains($asked['html'], 'Да, наплати повторно'), 'the question');
        truthy(str_contains($asked['html'], 'PAID<') && !str_contains($asked['html'], 'OLDF<') && !str_contains($asked['html'], 'FAIL<'), 'only the charges that stand in the way');
        same($post + ['seen' => $paidRef, 'confirm' => 'yes'], staff_test_hidden($asked['html']), 'the same values again, with the charges it showed');
        same(3, count(staff_test_records($private)), 'nothing was started');
        foreach (['no', 'YES', ['yes']] as $other) {
            same([], array_intersect(STAFF_TEST_CPAY_NAMES, array_keys(staff_test_hidden(staff_start_answer($config, staff_test_server(), $post + ['seen' => $paidRef, 'confirm' => $other], 'litespeed', STAFF_TEST_NOW)['html']))), 'only "yes" confirms');
        }
        foreach (['no charges seen' => [], 'another charge seen' => ['seen' => 'TMZK4A7Q2B'], 'seen as a list' => ['seen' => [$paidRef]]] as $label => $seen) {
            truthy(staff_test_asked(staff_start_answer($config, staff_test_server(), $post + $seen + ['confirm' => 'yes'], 'litespeed', STAFF_TEST_NOW)['html']), $label . ': asked again');
        }
        same(3, count(staff_test_records($private)), 'still nothing was started');
        $confirmed = staff_start_answer($config, staff_test_server(), staff_test_hidden($asked['html']), 'litespeed', STAFF_TEST_NOW);
        same(200, $confirmed['status']);
        same(STAFF_TEST_CPAY_NAMES, array_keys(staff_test_hidden($confirmed['html'])), 'the form for cPay');
        same(4, count(staff_test_records($private)), 'now it is started');
        $free = staff_start_answer($config, staff_test_server(), staff_test_fields($config, STAFF_TEST_NOW, ['reservation' => '111111']), 'litespeed', STAFF_TEST_NOW);
        same(STAFF_TEST_CPAY_NAMES, array_keys(staff_test_hidden($free['html'])), 'a charge that failed 46 minutes ago does not stand in the way');
    });
});

test('staff: two forms for one reservation sent at the same moment cannot both pass the look at its earlier charges', function (): void {
    staff_test_private(static function (string $private): void {
        $config = staff_test_config($private);
        $form = static function (string $tail) use ($private): array {
            $ref = substr(new_reference(STAFF_TEST_NOW), 0, 6) . $tail;
            $token = staff_token(staff_secret($private), 'recepcija', $ref, intdiv(STAFF_TEST_NOW, 3600));
            return ['amount' => '21802', 'reservation' => '4567890123', 'ref' => $ref, 'token' => $token, 'confirm' => ''];
        };
        [$first, $second] = [$form('ONE1'), $form('TWO2')];
        /* Another start is between its look at the earlier charges and its write: it holds the page's lock. What does this form get meanwhile? */
        $meanwhile = static fn (array $typed): array => staff_locked($private, static function () use ($private, $config, $typed): array {
            try {
                $got = staff_charge_answer($config, 'recepcija', $typed, STAFF_TEST_NOW, 1)['status'];
            } catch (RuntimeException $e) {
                $got = $e->getMessage();
            }
            return [$got, count(staff_test_records($private))];
        });
        same(['staff_lock', 0], $meanwhile($second), 'the second form neither looks nor writes while the first is at it');
        $started = staff_start_answer($config, staff_test_server(), $first, 'litespeed', STAFF_TEST_NOW);
        same(STAFF_TEST_CPAY_NAMES, array_keys(staff_test_hidden($started['html'])), 'the first form goes on to cPay');
        same(['staff_lock', 1], $meanwhile($second), 'the look is done holding the lock: no answer comes from outside it');
        same(['staff_lock', 1], $meanwhile($first), 'the same for a form that was sent before');
        $asked = staff_start_answer($config, staff_test_server(), $second, 'litespeed', STAFF_TEST_NOW);
        same(200, $asked['status']);
        truthy(str_contains($asked['html'], 'Да се наплати уште еднаш?') && str_contains($asked['html'], $first['ref'] . '</td>'), 'once the first is done the second is asked, and shown the first charge');
        same([], array_intersect(STAFF_TEST_CPAY_NAMES, array_keys(staff_test_hidden($asked['html']))), 'it gets nothing for cPay');
        same(409, staff_start_answer($config, staff_test_server(), $first, 'litespeed', STAFF_TEST_NOW)['status'], 'and the first form, sent twice, is "already started"');
        same(1, count(staff_test_records($private)), 'one record');
    });
});

test('staff: a confirmation goes on only for the charges its page showed; one started since is shown and asked about first', function (): void {
    staff_test_private(static function (string $private): void {
        $config = staff_test_config($private);
        $paid = staff_test_record(STAFF_TEST_NOW - 600, ['status' => 'paid', 'cpay_ref' => '123456'], 'PAID');
        create_payment(payments_dir($private), $paid);
        $post = static fn (array $fields): array => staff_start_answer($config, staff_test_server(), $fields, 'litespeed', STAFF_TEST_NOW);
        [$askedA, $askedB] = [$post(staff_test_form($private, 'AAA1')), $post(staff_test_form($private, 'BBB2'))];
        foreach ([$askedA, $askedB] as $asked) {
            truthy(staff_test_asked($asked['html']) && str_contains($asked['html'], $paid['ref'] . '</td>'), 'two people are each asked about the paid charge');
        }
        [$confirmA, $confirmB] = [staff_test_hidden($askedA['html']), staff_test_hidden($askedB['html'])];
        truthy(staff_test_to_cpay($post($confirmA)['html']), 'the first confirmation goes on to cPay with one press');
        $againB = $post($confirmB);
        same(200, $againB['status']);
        truthy(staff_test_asked($againB['html']), 'the second confirmation is asked again, and gets nothing for cPay');
        truthy(str_contains(staff_test_row($againB['html'], $confirmA['ref'] . '</td>'), 'Чека одговор'), 'it is shown the charge the first one started');
        truthy(str_contains($againB['html'], $paid['ref'] . '</td>'), 'and still the paid one');
        same(2, count(staff_test_records($private)), 'nothing more was started');
        truthy(staff_test_to_cpay($post(staff_test_hidden($againB['html']))['html']), 'confirmed once more, knowing both, it goes on');
        same(3, count(staff_test_records($private)));
        same(409, $post($confirmA)['status'], 'the first confirmation, sent twice, is "already started"');
    });
});

/** Runs staff_start_answer() for each form in a PHP process of its own, all let go at the same moment; what each answered. */
function staff_test_at_once(array $config, array $forms): array
{
    $private = $config['private_dir'];
    $script = $private . '/at-once.php';
    file_put_contents($script, implode("\n", [
        '<?php', 'declare(strict_types=1);', 'require ' . var_export(dirname(__DIR__, 2) . '/src/cpay/lib/staff-app.php', true) . ';',
        '$in = json_decode((string) file_get_contents($argv[1]), true);', 'echo "ready\n";',
        'while (!is_file($in["go"])) { usleep(1000); }',
        '$answer = staff_start_answer($in["config"], $in["server"], $in["fields"], "litespeed", $in["now"]);',
        'echo $answer["status"], " ", str_contains($answer["html"], "name=\"CheckSum\"") ? "cpay" : (str_contains($answer["html"], "Да се наплати уште еднаш?") ? "asked" : "other");',
    ]));
    $runs = [];
    foreach ($forms as $index => $fields) {
        $input = $private . '/at-once-' . $index . '.json';
        file_put_contents($input, json_encode(['go' => $private . '/go', 'config' => $config, 'server' => staff_test_server(), 'fields' => $fields, 'now' => STAFF_TEST_NOW]));
        $process = proc_open([PHP_BINARY, $script, $input], [1 => ['pipe', 'w']], $pipes);
        truthy(is_resource($process), 'a PHP process starts');
        same("ready\n", fgets($pipes[1]), 'it waits for the others');
        $runs[] = [$process, $pipes[1]];
    }
    touch($private . '/go');
    return array_map(static function (array $run): string {
        $answer = (string) stream_get_contents($run[1]);
        fclose($run[1]);
        proc_close($run[0]);
        return $answer;
    }, $runs);
}

test('staff: of confirmations for one reservation sent at the same moment, one goes on and the others are asked again', function (): void {
    if (!function_exists('proc_open')) {
        skip('no second process can be started here');
    }
    staff_test_private(static function (string $private): void {
        $config = staff_test_config($private);
        $paid = staff_test_record(STAFF_TEST_NOW - 600, ['status' => 'paid', 'cpay_ref' => '123456'], 'PAID');
        create_payment(payments_dir($private), $paid);
        $forms = array_map(static function (string $tail) use ($private, $config): array {
            $asked = staff_start_answer($config, staff_test_server(), staff_test_form($private, $tail), 'litespeed', STAFF_TEST_NOW);
            truthy(staff_test_asked($asked['html']), 'each form is asked about the paid charge first');
            return staff_test_hidden($asked['html']);
        }, ['CCC1', 'CCC2', 'CCC3', 'CCC4']);
        $answers = staff_test_at_once($config, $forms);
        sort($answers);
        same(['200 asked', '200 asked', '200 asked', '200 cpay'], $answers, 'one goes on to cPay');
        same(2, count(staff_test_records($private)), 'one more record');
    });
});

test('staff: the 81st charge of a user within the hour is refused; another user and the next hour go on', function (): void {
    staff_test_private(static function (string $private): void {
        $config = staff_test_config($private);
        $file = $private . '/cpay-staff-ratelimit.json';
        for ($i = 0; $i < 80; $i++) {
            same(false, rate_limited($file, staff_rate_key($config, 'recepcija'), STAFF_MAX_PER_HOUR, 3600, STAFF_TEST_NOW - 10));
        }
        $answer = staff_start_answer($config, staff_test_server(), staff_test_fields($config, STAFF_TEST_NOW), 'litespeed', STAFF_TEST_NOW);
        same(429, $answer['status']);
        truthy(str_contains($answer['html'], 'Премногу наплати во еден час. Почекајте малку или јавете му се на сопственикот.'), 'too many');
        same([], staff_test_hidden($answer['html']), 'nothing for cPay');
        same([], staff_test_records($private), 'no record');
        truthy(str_contains(staff_test_log($private), ' staff_rate_limited'), 'logged by code');
        $other = staff_start_answer($config, staff_test_server(['REMOTE_USER' => 'ana']), staff_test_fields($config, STAFF_TEST_NOW, ['reservation' => '222222'], 'ana'), 'litespeed', STAFF_TEST_NOW);
        same(200, $other['status'], 'another user is counted separately');
        $later = staff_start_answer($config, staff_test_server(), staff_test_fields($config, STAFF_TEST_NOW + 3600, ['reservation' => '333333']), 'litespeed', STAFF_TEST_NOW + 3600);
        same(200, $later['status'], 'an hour later the count starts again');
        $stored = (string) file_get_contents($file);
        truthy(!str_contains($stored, 'recepcija') && !str_contains($stored, 'ana'), 'the file holds hashes, never a user name');
        same(80, STAFF_MAX_PER_HOUR);
    });
});

test('staff: the question about a reservation charged before comes before the hourly cap, and asking uses up no start', function (): void {
    staff_test_private(static function (string $private): void {
        $config = staff_test_config($private);
        $file = $private . '/cpay-staff-ratelimit.json';
        $key = staff_rate_key($config, 'recepcija');
        for ($i = 0; $i < 80; $i++) {
            same(false, rate_limited($file, $key, STAFF_MAX_PER_HOUR, 3600, STAFF_TEST_NOW - 10));
        }
        create_payment(payments_dir($private), staff_test_record(STAFF_TEST_NOW - 600, ['status' => 'paid', 'cpay_ref' => '123456'], 'PAID'));
        $starts = static fn (): int => count(json_decode((string) file_get_contents($file), true)[$key] ?? []);
        $asked = staff_start_answer($config, staff_test_server(), staff_test_fields($config, STAFF_TEST_NOW), 'litespeed', STAFF_TEST_NOW);
        same(200, $asked['status'], 'the question, not "too many"');
        truthy(staff_test_asked($asked['html']), 'the confirm page');
        same(80, $starts(), 'asking used up no start');
        $confirmed = staff_start_answer($config, staff_test_server(), staff_test_hidden($asked['html']), 'litespeed', STAFF_TEST_NOW);
        same(429, $confirmed['status'], 'the confirmed charge meets the cap');
        same(1, count(staff_test_records($private)), 'and starts nothing');
    });
});

test('staff: a counter that cannot be written does not stop a charge; a record that cannot be written or a lock that cannot be had does', function (): void {
    staff_test_private(static function (string $private): void {
        $config = staff_test_config($private);
        mkdir($private . '/cpay-staff-ratelimit.json');
        $answer = staff_start_answer($config, staff_test_server(), staff_test_fields($config, STAFF_TEST_NOW), 'litespeed', STAFF_TEST_NOW);
        same(200, $answer['status']);
        truthy(str_contains(staff_test_log($private), ' staff_rate_unavailable'), 'logged by code');
    });
    staff_test_private(static function (string $private): void {
        $config = staff_test_config($private);
        $post = staff_test_fields($config, STAFF_TEST_NOW);
        file_put_contents(payments_dir($private), 'a file where the folder should be');
        try {
            staff_start_answer($config, staff_test_server(), $post, 'litespeed', STAFF_TEST_NOW);
            truthy(false, 'should have thrown');
        } catch (RuntimeException $e) {
            same('payments_write', $e->getMessage());
        }
        same('staff_failed payments_write', staff_failure_code(new RuntimeException('payments_write')));
        same('staff_failed RuntimeException', staff_failure_code(new RuntimeException('a message with a value: TEST_PASS')), 'only a code is ever logged');
    });
    staff_test_private(static function (string $private): void {
        $config = staff_test_config($private);
        $post = staff_test_fields($config, STAFF_TEST_NOW);
        mkdir($private . '/cpay-staff-start.lock'); /* the page's lock cannot be had */
        try {
            staff_start_answer($config, staff_test_server(), $post, 'litespeed', STAFF_TEST_NOW);
            truthy(false, 'should have thrown');
        } catch (RuntimeException $e) {
            same('staff_lock', $e->getMessage());
        }
        same([], staff_test_records($private), 'without the lock nothing is started');
        same('staff_failed staff_lock', staff_failure_code(new RuntimeException('staff_lock')));
    });
});
