<?php
declare(strict_types=1);

/* A POST body that PHP does not read into $_POST (no Content-Type, text/plain, application/octet-stream), or reads
   into lists only: the notice handler reads and parses it itself, so cPay's push is never answered 200 and lost
   (design 4.6). A form sent in chunks is read again to be measured (design 4.7). */
require_once __DIR__ . '/notice-helpers.php';

/** A reader for notice_input() that hands out $body and notes how many bytes it was asked for. */
function notice_body_test_reader(string $body, array &$asked): callable
{
    return static function (int $most) use ($body, &$asked): string {
        $asked[] = $most;
        return substr($body, 0, $most);
    };
}

/** A POST from cPay's own address with this Content-Type ('' for none). */
function notice_body_test_post(string $type): array
{
    return ['REQUEST_METHOD' => 'POST'] + ($type === '' ? [] : ['CONTENT_TYPE' => $type]) + NOTICE_TEST_PUSH;
}

test('notice_input: the POST fields as PHP read them, else the query string, and then no body is read', function (): void {
    $asked = [];
    $read = notice_body_test_reader('a=1', $asked);
    $form = ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'CONTENT_LENGTH' => '3'];
    same(['fields' => ['a' => '1'], 'body' => ''], notice_input(['a' => '1', 'list' => ['x']], ['b' => '2'], $form, $read), 'a form');
    same(['fields' => ['b' => '2'], 'body' => ''], notice_input([], ['b' => '2'], ['REQUEST_METHOD' => 'GET'], $read), 'a GET');
    same([], $asked, 'the body was never read');
});

test('notice_input: any Content-Type is read when PHP read no text field; a multipart body PHP took itself reads as empty', function (): void {
    /* PHP empties php://input only for a multipart body it parsed (one with a boundary); the reader stands in for that. */
    foreach (['multipart/form-data; boundary=y', 'MULTIPART/FORM-DATA; boundary=y', 'multipart/form-data,boundary=y', 'multipart/form-data boundary=y'] as $type) {
        $asked = [];
        same(['fields' => ['b' => '2'], 'body' => ''], notice_input([], ['b' => '2'], notice_body_test_post($type), notice_body_test_reader('', $asked)), "'$type'");
        same([CPAY_MAX_BYTES + 1], $asked, "'$type': looked at, and nothing was left of it");
    }
    /* Without a boundary, or with a type PHP does not take as multipart, PHP parses nothing and leaves the body. */
    foreach (['multipart/form-data', 'multipart/form-data; charset=utf-8', 'multipart/form-data; boundary', 'multipart/form-dataX', 'multipart/form-data-x; boundary=y', "multipart/form-data\t; boundary=y", ' multipart/form-data; boundary=y'] as $type) {
        $asked = [];
        same(['fields' => ['a' => '1'], 'body' => 'a=1'], notice_input([], [], notice_body_test_post($type), notice_body_test_reader('a=1', $asked)), json_encode($type));
        same([CPAY_MAX_BYTES + 1], $asked, json_encode($type) . ': PHP did not parse it, so the body is read');
    }
});

test('notice_input: a form that PHP read into lists only is read again, and its body handed on without fields', function (): void {
    $asked = [];
    $form = ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'CONTENT_LENGTH' => '12'];
    same(['fields' => [], 'body' => 'a[]=1&b[x]=2'], notice_input(['a' => ['1'], 'b' => ['x' => '2']], ['q' => 'x'], $form, notice_body_test_reader('a[]=1&b[x]=2', $asked)));
    same([CPAY_MAX_BYTES + 1], $asked);
    $asked = [];
    $multipart = ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'multipart/form-data; boundary=y'];
    same(['fields' => [], 'body' => ''], notice_input(['a' => ['1']], [], $multipart, notice_body_test_reader('', $asked)), 'multipart: nothing is left to read');
    same([CPAY_MAX_BYTES + 1], $asked, 'looked at, and empty');
});

test('notice_input: a form sent without a Content-Length is read again up to 16 KB and a byte, to be measured', function (): void {
    $asked = [];
    $chunked = ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded'];
    same(['fields' => ['a' => '1'], 'body' => 'a=1'], notice_input(['a' => '1'], ['q' => 'x'], $chunked, notice_body_test_reader('a=1', $asked)), 'the fields as PHP read them, the body as read');
    same([CPAY_MAX_BYTES + 1], $asked);
    $long = notice_input(['a' => 'x'], [], $chunked, notice_body_test_reader('a=' . str_repeat('%2F', 6000), $asked));
    same(CPAY_MAX_BYTES + 1, strlen($long['body']), 'a body past the limit shows as one');
    $asked = [];
    same(['fields' => ['a' => '1'], 'body' => ''], notice_input(['a' => '1'], [], $chunked + ['CONTENT_LENGTH' => '3'], notice_body_test_reader('a=1', $asked)), 'with a Content-Length');
    same([], $asked, 'the stated length measures it');
});

test('notice_input: an empty POST and a GET without fields have no fields and no body', function (): void {
    $asked = [];
    same(['fields' => [], 'body' => ''], notice_input([], [], notice_body_test_post(''), notice_body_test_reader('', $asked)), 'an empty POST');
    same([CPAY_MAX_BYTES + 1], $asked, 'it was looked at');
    same(['fields' => ['q' => 'x'], 'body' => ''], notice_input([], ['q' => 'x'], notice_body_test_post('text/plain'), notice_body_test_reader('', $asked)), 'the query string, as before');
    same(['fields' => [], 'body' => ''], notice_input([], [], ['REQUEST_METHOD' => 'GET'], notice_body_test_reader('a=1', $asked)), 'a GET: its body is never read');
});

test('notice_input: a POST body that PHP did not parse is read, at most 16 KB and a byte, and parsed as a form body', function (): void {
    $record = new_payment('TMZK4A7Q2B', 'recepcija', 21802, '4567890123', 'booking 4567890123', CPAY_DEFAULT_URL, 'md5', time());
    $notice = notice_test_return(notice_test_pairs($record));
    $body = http_build_query($notice);
    foreach (['', 'text/plain', 'text/plain; charset=utf-8', 'application/octet-stream', 'application/json'] as $type) {
        $asked = [];
        $input = notice_input([], ['q' => 'not used'], notice_body_test_post($type), notice_body_test_reader($body, $asked));
        same($notice, $input['fields'], "Content-Type '$type': the notice as sent");
        same($body, $input['body'], "Content-Type '$type': the body as read");
        same([CPAY_MAX_BYTES + 1], $asked, "Content-Type '$type': one read, one byte past the limit");
    }
    $asked = [];
    $form = ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded'];
    same(['a' => '1'], notice_input([], [], $form, notice_body_test_reader('a=1&&list[]=2', $asked))['fields'], 'a form that PHP read nothing from is read again: string values only');
    $long = notice_input([], [], notice_body_test_post(''), notice_body_test_reader(str_repeat('x', 20000), $asked));
    same(CPAY_MAX_BYTES + 1, strlen($long['body']), 'a body past the limit shows as one');
});

test('notice_input: a body that holds no field is handed on as read, without fields and without the query string', function (): void {
    foreach (['&&=only-a-value&list[]=1', '[]=x', "\x00\x01\xFF", ' '] as $body) {
        $asked = [];
        same(['fields' => [], 'body' => $body], notice_input([], ['q' => 'x'], notice_body_test_post('text/plain'), notice_body_test_reader($body, $asked)), json_encode(bin2hex($body)));
    }
});

test('notice_body_fields: string values only, and no warning escapes past max_input_vars', function (): void {
    same(['a' => '1', 'b' => ''], notice_body_fields('a=1&b&c[]=2&d[e]=3'));
    same(['ж' => 'ш'], notice_body_fields(rawurlencode('ж') . '=' . rawurlencode('ш')), 'decoded as a form body is');
    same([], notice_body_fields(''));
    $many = notice_body_fields(implode('&', array_map(static fn (int $i): string => "f$i=v", range(1, 1500))));
    truthy(count($many) >= 1 && count($many) <= 1500, 'too many fields for PHP is no warning here (the runner would fail on one)');
});

test('notice_outcome: a notice read from a body without Content-Type marks the record like a form', function (): void {
    notice_test_private(function (string $private): void {
        $record = notice_test_record($private);
        $asked = [];
        $server = notice_body_test_post('');
        $input = notice_input([], [], $server, notice_body_test_reader(http_build_query(notice_test_return(notice_test_pairs($record))), $asked));
        same(['status' => 200, 'show' => 'ok', 'record' => null], notice_outcome('ok', $input['fields'], notice_test_config($private), $server, strlen($input['body'])));
        same(['paid', '123456'], [notice_test_stored($private, $record)['status'], notice_test_stored($private, $record)['cpay_ref']]);
        same([], notice_test_codes($private));
    });
});

test('notice_outcome: the 16 KB count the body as it was read as well', function (): void {
    notice_test_private(function (string $private): void {
        $record = notice_test_record($private);
        $config = notice_test_config($private);
        $fields = notice_test_return(notice_test_pairs($record));
        $unmeasured = ['REMOTE_ADDR' => NOTICE_TEST_CPAY_ADDRESS];
        same(['status' => 200, 'show' => 'neutral', 'record' => null], notice_outcome('ok', $fields, $config, $unmeasured, CPAY_MAX_BYTES + 1));
        same(['cpay_too_large'], notice_test_codes($private));
        same([], notice_test_copies($private), 'nothing is kept');
        same($record, notice_test_stored($private, $record), 'nothing is marked');
        same('ok', notice_outcome('ok', $fields, $config, $unmeasured, CPAY_MAX_BYTES)['show'], '16 KB itself is taken');
    });
});

test('notice_unparsed_outcome: logs the code only, keeps the first 1024 characters unverified and answers with the neutral page', function (): void {
    notice_test_private(function (string $private): void {
        $body = '&&=SECRETFRAGMENT&' . str_repeat('ж', 2000);
        $neutral = ['status' => 200, 'show' => 'neutral', 'record' => null];
        same($neutral, notice_unparsed_outcome('ok', $body, notice_test_config($private), ['CONTENT_LENGTH' => (string) strlen($body)] + NOTICE_TEST_BROWSER));
        same(['cpay_unparsed_body'], notice_test_codes($private));
        truthy(!str_contains((string) file_get_contents($private . '/logs/errors.log'), 'SECRETFRAGMENT'), 'nothing of the body is logged');
        $copies = notice_test_copies($private);
        same(1, count($copies));
        same(['file', 'received', 'kind', 'via', 'verified', 'fields', 'body'], array_keys($copies[0]));
        same(['ok', 'browser', false, []], [$copies[0]['kind'], $copies[0]['via'], $copies[0]['verified'], $copies[0]['fields']]);
        same(cut_cpay_value($body), $copies[0]['body'], 'the first 1024 characters');
        same(1024, preg_match_all('/./su', $copies[0]['body']));
    });
});

test('notice_unparsed_outcome: a stranger\'s copies keep the hourly cap and the file ceilings, cPay\'s address its own', function (): void {
    notice_test_private(function (string $private): void {
        $config = notice_test_config($private);
        for ($i = 0; $i < 25; $i++) {
            notice_unparsed_outcome('fail', "&&$i", $config, NOTICE_TEST_UNLISTED);
        }
        same(20, count(notice_test_copies($private)), '20 in the hour');
        same(['cpay_unparsed_body' => 25, 'cpay_rate_limited' => 5], array_count_values(notice_test_codes($private)));
        notice_unparsed_outcome('fail', '&&', $config, NOTICE_TEST_PUSH);
        same(21, count(notice_test_copies($private)), 'cPay\'s own address is past the hourly cap');
    });
    notice_test_private(function (string $private): void {
        $config = notice_test_config($private);
        notice_test_fill_inbox($private, 500);
        notice_unparsed_outcome('ok', '&&', $config, NOTICE_TEST_BROWSER);
        same([], notice_test_copies($private), 'a full inbox takes nothing more from a stranger');
        same(['cpay_unparsed_body', 'cpay_inbox_full'], notice_test_codes($private));
        notice_test_fill_inbox($private, 5000);
        notice_unparsed_outcome('ok', '&&', $config, NOTICE_TEST_PUSH);
        same([], notice_test_copies($private), 'nor past 5000 files from cPay\'s address');
    });
});

test('notice_unparsed_outcome: over 16 KB, stated or read, is logged as too large and kept nowhere', function (): void {
    notice_test_private(function (string $private): void {
        $config = notice_test_config($private);
        $neutral = ['status' => 200, 'show' => 'neutral', 'record' => null];
        same($neutral, notice_unparsed_outcome('ok', '&&', $config, ['CONTENT_LENGTH' => '16385'] + NOTICE_TEST_PUSH));
        same($neutral, notice_unparsed_outcome('ok', str_repeat('&', CPAY_MAX_BYTES + 1), $config, ['REMOTE_ADDR' => NOTICE_TEST_CPAY_ADDRESS]));
        same(['cpay_too_large', 'cpay_too_large'], notice_test_codes($private));
        same([], notice_test_copies($private));
        notice_unparsed_outcome('ok', str_repeat('&', CPAY_MAX_BYTES), $config, ['REMOTE_ADDR' => NOTICE_TEST_CPAY_ADDRESS]);
        same(1, count(notice_test_copies($private)), '16 KB itself is kept');
    });
});

test('save_cpay_body keeps a body that is not UTF-8 by its first 1024 bytes, and never throws', function (): void {
    notice_test_private(function (string $private): void {
        save_cpay_body(notice_test_config($private), 'ok', str_repeat("\xFF", 3000), 'push');
        $copies = notice_test_copies($private);
        same(1, count($copies));
        same(1024, preg_match_all('/\x{FFFD}/u', $copies[0]['body']), 'each byte kept as a replacement character');
    });
    $log = (string) tempnam(sys_get_temp_dir(), 'oaza-body-log-');
    ini_set('error_log', $log);
    try {
        save_cpay_body(['private_dir' => ['not a folder']], 'ok', 'SECRETVALUE', 'push');
        $logged = (string) file_get_contents($log);
    } finally {
        ini_restore('error_log');
        unlink($log);
    }
    same(1, preg_match('/\A\[[^\]]+\] Hotel Oaza cPay notice: raw copy not kept, [A-Za-z]+\R\z/', $logged), $logged);
    truthy(!str_contains($logged, 'SECRETVALUE'), 'nothing of the body');
});
