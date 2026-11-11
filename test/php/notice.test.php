<?php
declare(strict_types=1);

require_once __DIR__ . '/notice-helpers.php';

/** Runs cpay_notice($kind) as a script of its own, without fields: what it printed, the status it set and what it logged. */
function notice_test_run(string $kind): array
{
    $script = (string) tempnam(sys_get_temp_dir(), 'oaza-notice-');
    $log = $script . '.log';
    file_put_contents($script, implode("\n", [
        '<?php',
        'declare(strict_types=1);',
        'ini_set(\'error_log\', ' . var_export($log, true) . ');',
        'register_shutdown_function(static fn () => fwrite(STDERR, \'status \' . http_response_code()));',
        'require ' . var_export(dirname(__DIR__, 2) . '/src/cpay/lib/notice.php', true) . ';',
        'cpay_notice(' . var_export($kind, true) . ');',
    ]));
    try {
        $process = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        truthy(is_resource($process), 'PHP started');
        $run = ['out' => (string) stream_get_contents($pipes[1]), 'err' => (string) stream_get_contents($pipes[2])];
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        return $run + ['log' => is_file($log) ? (string) file_get_contents($log) : ''];
    } finally {
        unlink($script);
        is_file($log) && unlink($log);
    }
}

test('cpay_notice without fields shows the neutral page; anything unexpected is logged by its class and answered 500', function (): void {
    same(['out' => neutral_page(), 'err' => 'status 200', 'log' => ''], notice_test_run('ok'));
    same(['out' => neutral_page(), 'err' => 'status 200', 'log' => ''], notice_test_run('fail'));
    $run = notice_test_run('paid'); /* no such address: the handler refuses it before it looks at anything */
    same([unconfirmed_page(), 'status 500'], [$run['out'], $run['err']]);
    same(1, preg_match('/\A\[[^\]]+\] Hotel Oaza cPay notice: uncaught InvalidArgumentException\R\z/', $run['log']), $run['log']);
});

test('notice_fields takes the POST fields, or the query string when nothing was posted', function (): void {
    same(['a' => '1'], notice_fields(['a' => '1'], ['b' => '2']), 'POST wins');
    same(['b' => '2'], notice_fields([], ['b' => '2']), 'the query string when POST is empty');
    same([], notice_fields([], []), 'nothing at all');
});

test('notice_fields keeps string values only', function (): void {
    same(['keep' => 'yes', 'empty' => ''], notice_fields(['a' => ['1', '2'], 'keep' => 'yes', 'empty' => ''], []));
    same(['q' => 'x'], notice_fields([], ['q' => 'x', 'list' => ['y']]));
    same([], notice_fields(['a' => ['1']], ['b' => '2']), 'a POST of lists only is not replaced by the query string');
});

test('notice_shaped needs both ReturnCheckSum and ReturnCheckSumHeader', function (): void {
    truthy(notice_shaped(['ReturnCheckSum' => 'x', 'ReturnCheckSumHeader' => 'y', 'more' => 'z']), 'both');
    truthy(!notice_shaped(['ReturnCheckSum' => 'x']), 'the checksum alone');
    truthy(!notice_shaped(['ReturnCheckSumHeader' => 'y']), 'the header alone');
    truthy(!notice_shaped(['ReturnCheckSum' => '', 'ReturnCheckSumHeader' => 'y']), 'an empty checksum');
    truthy(!notice_shaped(['ReturnCheckSum' => 'x', 'ReturnCheckSumHeader' => ['y']]), 'a list in place of the header');
    truthy(!notice_shaped(['CheckSum' => 'x', 'CheckSumHeader' => 'y']), 'the checksum of a request is not one of a return');
    truthy(!notice_shaped([]), 'nothing');
});

test('notice_channel: push for cPay\'s own address, browser for a browser\'s headers, else unlisted', function (): void {
    same('push', notice_channel(true, []));
    same('push', notice_channel(true, ['HTTP_ORIGIN' => 'https://www.cpay.com.mk']), 'the address decides before any header');
    same('browser', notice_channel(false, ['HTTP_ORIGIN' => 'https://www.cpay.com.mk']));
    same('browser', notice_channel(false, ['HTTP_ORIGIN' => 'null']), 'an opaque origin is still a browser');
    same('browser', notice_channel(false, ['HTTP_SEC_FETCH_SITE' => 'cross-site']));
    same('unlisted', notice_channel(false, ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_USER_AGENT' => 'Mozilla/5.0']));
    same('unlisted', notice_channel(false, ['HTTP_ORIGIN' => '']), 'an empty header is no header');
    same('unlisted', notice_channel(false, []));
});

test('notice_bytes is the largest of the body, the query string and the fields as PHP read them', function (): void {
    same(700, notice_bytes(['CONTENT_LENGTH' => '700', 'QUERY_STRING' => 'a=1'], ['a' => '1']));
    same(20000, notice_bytes(['CONTENT_LENGTH' => '0', 'QUERY_STRING' => str_repeat('a', 20000)], []));
    same(0, notice_bytes([], []));
    same(20004, notice_bytes([], ['name' => str_repeat('x', 20000)]), 'a body sent in chunks has no Content-Length');
    same(20004, notice_bytes(['CONTENT_LENGTH' => '10'], ['name' => str_repeat('x', 20000)]), 'nor is a Content-Length believed that says less');
    same(20003, notice_bytes([], [str_repeat('n', 20000) => 'v', 7 => 'x']), 'names count too, also one PHP made a number of');
    same(4, notice_bytes([], ['a' => '1', 'list' => ['x', 'y'], 'b' => '2']), 'only text is counted: nothing else is ever used or kept');
});

test('checked_notice accepts a notice signed for this merchant and returns only what the header names', function (): void {
    $record = new_payment('TMZK4A7Q2B', 'recepcija', 21802, '4567890123', 'booking 4567890123', CPAY_DEFAULT_URL, 'md5', time());
    $fields = notice_test_return(notice_test_pairs($record)) + ['Extra' => 'not signed'];
    $checked = checked_notice($fields, cpay_settings(notice_test_config('')));
    same('', $checked['code']);
    same(
        ['PayToMerchant', 'AmountToPay', 'MerchantName', 'AmountCurrency', 'Details1', 'Details2', 'PaymentOKURL', 'PaymentFailURL', 'cPayPaymentRef'],
        array_keys($checked['signed']),
        'the names of the header, in its order',
    );
    same('2180200', $checked['signed']['AmountToPay']);
    same('TMZK4A7Q2B', $checked['signed']['Details2']);
    same('123456', $checked['signed']['cPayPaymentRef']);
    same('', checked_notice(array_merge($fields, ['ReturnCheckSum' => strtolower($fields['ReturnCheckSum'])]), cpay_settings(notice_test_config('')))['code'], 'a checksum in small letters');
});

test('checked_notice: the specification\'s return sample passes checksum and merchant, and its Details2 is not one of this site\'s references', function (): void {
    same(['code' => 'cpay_bad_reference', 'signed' => []], checked_notice(notice_test_sample(), notice_test_sample_settings()));
    same('cpay_bad_checksum', checked_notice(notice_test_sample(['AmountToPay' => '900']), notice_test_sample_settings())['code'], 'a changed amount');
    same('cpay_bad_checksum', checked_notice(notice_test_sample(), notice_test_sample_settings(['key' => 'OTHER_PASS']))['code'], 'another key');
    same('cpay_wrong_merchant', checked_notice(notice_test_sample(), notice_test_sample_settings(['merchant_id' => '1000000003']))['code'], 'another merchant number');
    same('cpay_wrong_merchant', checked_notice(notice_test_sample(), notice_test_sample_settings(['merchant_name' => 'Bookstore']))['code'], 'another merchant name');
});

test('checked_notice refuses a checksum that does not hold, and gives nothing back', function (): void {
    $settings = cpay_settings(notice_test_config(''));
    $record = new_payment('TMZK4A7Q2B', 'recepcija', 21802, '4567890123', 'booking 4567890123', CPAY_DEFAULT_URL, 'md5', time());
    $fields = notice_test_return(notice_test_pairs($record));
    $refused = ['code' => 'cpay_bad_checksum', 'signed' => []];
    same($refused, checked_notice(array_merge($fields, ['ReturnCheckSum' => str_repeat('0', 32)]), $settings), 'another checksum');
    same($refused, checked_notice(array_merge($fields, ['AmountToPay' => '100']), $settings), 'a changed value');
    same($refused, checked_notice(array_merge($fields, ['cPayPaymentRef' => '999999']), $settings), 'a changed cPay reference');
    same($refused, checked_notice(notice_test_return(notice_test_pairs($record), '123456', 'OTHER_PASS'), $settings), 'signed with another key');
    same($refused, checked_notice(array_diff_key($fields, ['ReturnCheckSumHeader' => 0]), $settings), 'no header');
    same($refused, checked_notice(array_diff_key($fields, ['Details1' => 0]), $settings), 'a named field that was not sent');
    same($refused, checked_notice([], $settings), 'nothing');
});

test('checked_notice refuses another merchant, name or currency although the checksum holds', function (): void {
    $settings = cpay_settings(notice_test_config(''));
    $record = new_payment('TMZK4A7Q2B', 'recepcija', 21802, '4567890123', 'booking 4567890123', CPAY_DEFAULT_URL, 'md5', time());
    $refused = ['code' => 'cpay_wrong_merchant', 'signed' => []];
    same($refused, checked_notice(notice_test_return(notice_test_pairs($record, ['PayToMerchant' => '1234567890'])), $settings), 'merchant number');
    same($refused, checked_notice(notice_test_return(notice_test_pairs($record, ['MerchantName' => 'Bookshop'])), $settings), 'merchant name');
    same($refused, checked_notice(notice_test_return(notice_test_pairs($record, ['AmountCurrency' => 'EUR'])), $settings), 'currency');
    $unnamed = array_diff_key(notice_test_pairs($record), ['PayToMerchant' => 0]);
    same($refused, checked_notice(notice_test_return($unnamed) + ['PayToMerchant' => '1000000003'], $settings), 'a merchant number that is posted but not signed');
});

test('checked_notice takes a header without merchant name and currency, and refuses one without a usable Details2', function (): void {
    $settings = cpay_settings(notice_test_config(''));
    $record = new_payment('TMZK4A7Q2B', 'recepcija', 21802, '4567890123', 'booking 4567890123', CPAY_DEFAULT_URL, 'md5', time());
    $short = array_diff_key(notice_test_pairs($record), ['MerchantName' => 0, 'AmountCurrency' => 0]);
    same('', checked_notice(notice_test_return($short), $settings)['code'], 'name and currency are checked only when named');
    $refused = ['code' => 'cpay_bad_reference', 'signed' => []];
    foreach (['../../config', 'tmzk4a7q2b', 'TMZK4A7Q2', 'TMZK4A7Q2BB', "TMZK4A7Q2B\n", 'TMZK4A7Q2Б'] as $details2) {
        same($refused, checked_notice(notice_test_return(notice_test_pairs($record, ['Details2' => $details2])), $settings), json_encode($details2));
    }
    $unnamed = array_diff_key(notice_test_pairs($record), ['Details2' => 0]);
    same($refused, checked_notice(notice_test_return($unnamed) + ['Details2' => 'TMZK4A7Q2B'], $settings), 'a reference that is posted but not signed');
});

test('neutral_page is the page these addresses always showed', function (): void {
    /* A checkout on Windows may give this file CRLF line ends; the page itself always has LF. */
    same(str_replace("\r\n", "\n", NOTICE_TEST_NEUTRAL) . "\n", neutral_page());
});

test('result_page for a paid record: amount, reservation, cPay reference, code and the way to the next charge', function (): void {
    $record = array_merge(
        new_payment('TMZK4A7Q2B', 'recepcija', 21802, '4567890123', 'booking 4567890123', CPAY_DEFAULT_URL, 'md5', time()),
        ['status' => 'paid', 'cpay_ref' => '123456'],
    );
    $page = result_page($record);
    truthy(str_contains($page, 'Наплатено: 21.802 ден.'), 'the amount as staff read it');
    truthy(str_contains($page, 'Резервација 4567890123 · cPay реф. 123456 · шифра TMZK4A7Q2B'), 'reservation, cPay reference, code');
    same(1, preg_match('~<a [^>]*href="/naplata/"[^>]*>Следна наплата</a>~', $page), 'the button to the staff page');
    truthy(str_starts_with($page, "<!doctype html>\n<html lang=\"mk\">"), 'a complete page in Macedonian');
    truthy(str_contains($page, '<meta name="robots" content="noindex">'), 'noindex');
    truthy(!str_contains($page, 'Две наплати'), 'no flag for one charge');
    truthy(!str_contains($page, 'Не е наплатено'), 'not the other page');
});

test('result_page flags a record with a second cPay reference', function (): void {
    $record = array_merge(
        new_payment('TMZK4A7Q2B', 'recepcija', 500, '4567890123', 'booking 4567890123', CPAY_DEFAULT_URL, 'md5', time()),
        ['status' => 'paid', 'cpay_ref' => '123456', 'extra_refs' => ['123457']],
    );
    $page = result_page($record);
    truthy(str_contains($page, 'Наплатено: 500 ден.'), 'paid');
    truthy(str_contains($page, 'Две наплати: проверете во cPay порталот.'), 'the flag');
});

test('result_page for a failed record: the two hints and the way back', function (): void {
    $record = array_merge(
        new_payment('TMZK4A7Q2B', 'recepcija', 21802, '4567890123', 'booking 4567890123', CPAY_DEFAULT_URL, 'md5', time()),
        ['status' => 'failed'],
    );
    $page = result_page($record);
    truthy(str_contains($page, 'Не е наплатено.'), 'the heading');
    truthy(str_contains($page, 'Најчесто: погрешни податоци од картичката или износ поголем од состојбата на картичката.'), 'the usual reasons');
    truthy(str_contains($page, 'Ако на cPay притиснавте Назад или ја освеживте страницата, плаќањето можеби сепак поминало. Погледнете ја листата по неколку минути пред да пробате пак.'), 'the warning');
    same(1, preg_match('~<a [^>]*href="/naplata/"[^>]*>Назад кон наплата</a>~', $page), 'the button to the staff page');
    truthy(!str_contains($page, 'Наплатено:'), 'not the other page');
});

test('result_page escapes what a record holds and shows nothing certain for a record that is neither paid nor failed', function (): void {
    $record = ['status' => 'paid', 'amount' => 21802, 'reservation' => '<b>1</b>', 'cpay_ref' => '"><script>x</script>', 'ref' => "a'b&c"];
    $page = result_page($record);
    same(0, substr_count($page, '<script>'), 'no script element');
    truthy(str_contains($page, 'Резервација &lt;b&gt;1&lt;/b&gt; · cPay реф. &quot;&gt;&lt;script&gt;x&lt;/script&gt; · шифра a&#039;b&amp;c'), 'escaped');
    same(unconfirmed_page(), result_page(['status' => 'waiting', 'amount' => 21802]), 'a waiting record');
    same(unconfirmed_page(), result_page([]), 'no record at all');
});

test('unconfirmed_page says so in both languages, with the hotel\'s phone and e-mail and no way to the staff page', function (): void {
    $page = unconfirmed_page();
    truthy(str_contains($page, 'Статусот на плаќањето не можеше да се потврди.'), 'the heading');
    truthy(str_contains($page, 'Ако сте од хотелот: не наплаќајте повторно, прво проверете во cPay порталот.'), 'the advice to staff');
    same(1, preg_match('~<div lang="en">.*The payment status could not be confirmed\. Please contact the hotel\..*</div>~s', $page), 'English, marked as such');
    same(1, substr_count($page, '<a href="tel:+38970310300">+389 70 310 300</a>, <a href="mailto:info@oazahotel.com.mk">info@oazahotel.com.mk</a>'), 'phone and e-mail as on the neutral page');
    truthy(!str_contains($page, 'naplata'), 'a stranger is not shown the staff page');
    truthy(str_contains($page, '<meta name="robots" content="noindex">'), 'noindex');
});

test('limit_cpay_fields keeps 50 string fields and cuts a value to 1024 characters, not bytes', function (): void {
    $many = [];
    for ($i = 0; $i < 60; $i++) {
        $many['f' . $i] = 'v';
    }
    same(50, count(limit_cpay_fields($many)));
    same(['keep' => 'yes'], limit_cpay_fields(['list' => ['1'], 'keep' => 'yes']), 'a list is dropped');
    $cut = limit_cpay_fields(['latin' => str_repeat('x', 3000), 'cyrillic' => str_repeat('ж', 3000), 'short' => 'ж', 'broken' => str_repeat("\xFF", 3000)]);
    same(str_repeat('x', 1024), $cut['latin']);
    same(str_repeat('ж', 1024), $cut['cyrillic'], 'whole characters');
    same('ж', $cut['short']);
    same(1024, strlen($cut['broken']), 'bytes when the value is not UTF-8');
});

test('save_cpay_notice writes received, kind, via, verified and the fields into a file that pruning will find', function (): void {
    notice_test_private(function (string $private): void {
        save_cpay_notice(notice_test_config($private), 'fail', ['Details2' => 'TMZK4A7Q2B', 'list' => ['x']], 'browser', false);
        $copies = notice_test_copies($private);
        same(1, count($copies));
        same(['file', 'received', 'kind', 'via', 'verified', 'fields'], array_keys($copies[0]));
        same(1, preg_match('/\A\d{8}-\d{6}-fail-[0-9a-f]{8}\.json\z/', $copies[0]['file']), $copies[0]['file']);
        truthy(payment_time($copies[0]['received']) !== null, 'the time as date(c) writes it');
        same(['fail', 'browser', false, ['Details2' => 'TMZK4A7Q2B']], [$copies[0]['kind'], $copies[0]['via'], $copies[0]['verified'], $copies[0]['fields']]);
        save_cpay_notice(notice_test_config($private), 'ok', ['a' => '1'], 'push', true);
        $second = notice_test_copies($private)[1]; /* "fail" sorts before "ok" */
        same(['ok', 'push', true], [$second['kind'], $second['via'], $second['verified']], 'a verified one says so');
        same([], notice_test_codes($private), 'nothing to log');
    });
});

test('save_cpay_notice: the hourly cap stops a stranger\'s copies, verified or not, and nothing that is exempt', function (): void {
    notice_test_private(function (string $private): void {
        $config = notice_test_config($private);
        for ($i = 0; $i < 25; $i++) {
            save_cpay_notice($config, 'ok', ['n' => (string) $i], 'browser', false);
        }
        same(20, count(notice_test_copies($private)), '20 in the hour');
        same(array_fill(0, 5, 'cpay_rate_limited'), notice_test_codes($private));
        save_cpay_notice($config, 'ok', ['n' => 'verified, joined its record'], 'browser', true, true);
        save_cpay_notice($config, 'ok', ['n' => 'unlisted, verified, joined its record'], 'unlisted', true, true);
        save_cpay_notice($config, 'ok', ['n' => 'from cPay'], 'push', false);
        same(23, count(notice_test_copies($private)), 'an exempt copy and cPay\'s own address are past the cap');
        same(5, count(notice_test_codes($private)), 'and nothing more was refused');
        save_cpay_notice($config, 'ok', ['n' => 'verified, marks nothing'], 'browser', true);
        same(23, count(notice_test_copies($private)), 'a verified copy that is not exempt keeps the cap (design 4.7)');
        same(array_fill(0, 6, 'cpay_rate_limited'), notice_test_codes($private));
    });
});

test('save_cpay_notice: a full inbox stops a stranger\'s copies, verified or not, and an exempt copy is still kept', function (): void {
    notice_test_private(function (string $private): void {
        $config = notice_test_config($private);
        notice_test_fill_inbox($private, 500);
        save_cpay_notice($config, 'ok', ['n' => 'stranger'], 'browser', false);
        save_cpay_notice($config, 'ok', ['n' => 'verified, marks nothing'], 'unlisted', true);
        same([], notice_test_copies($private), 'nothing more from a stranger');
        same(['cpay_inbox_full', 'cpay_inbox_full'], notice_test_codes($private));
        save_cpay_notice($config, 'ok', ['n' => 'from cPay'], 'push', false);
        save_cpay_notice($config, 'ok', ['n' => 'verified, joined its record'], 'browser', true, true);
        same(2, count(notice_test_copies($private)), 'cPay\'s own address has more room, an exempt copy has no ceiling');
        same(['cpay_inbox_full', 'cpay_inbox_full'], notice_test_codes($private));
    });
});

test('save_cpay_notice never throws: an inbox that cannot be made or written is logged by its code', function (): void {
    notice_test_private(function (string $private): void {
        file_put_contents($private . '/cpay-inbox', 'a file where the folder should be');
        save_cpay_notice(notice_test_config($private), 'ok', ['a' => '1'], 'push', true);
        same(['cpay_inbox'], notice_test_codes($private));
    });
    notice_test_private(function (string $private): void {
        save_cpay_notice(notice_test_config($private), '../x', ['a' => '1'], 'push', true);
        same(['cpay_write'], notice_test_codes($private), 'a kind that is no part of a file name');
        same([], notice_test_copies($private), 'nothing was written');
        same(['logs'], array_values(array_diff(scandir($private), ['.', '..', 'cpay-inbox'])), 'nor anywhere else');
    });
});

test('save_cpay_notice never throws: whatever else goes wrong ends as one line with a class name in PHP\'s log', function (): void {
    $log = (string) tempnam(sys_get_temp_dir(), 'oaza-notice-log-');
    ini_set('error_log', $log);
    try {
        save_cpay_notice(['private_dir' => ['not a folder'], 'cpay' => ['key' => 'TEST_PASS']], 'ok', ['a' => 'SECRETVALUE'], 'push', true);
        $logged = (string) file_get_contents($log);
    } finally {
        ini_restore('error_log');
        unlink($log);
    }
    same(1, preg_match('/\A\[[^\]]+\] Hotel Oaza cPay notice: raw copy not kept, [A-Za-z]+\R\z/', $logged), $logged);
    truthy(!str_contains($logged, 'SECRETVALUE') && !str_contains($logged, 'TEST_PASS'), 'no value and no key');
});

test('notice_outcome: a verified push to the OK address marks the record paid and is answered OK', function (): void {
    notice_test_private(function (string $private): void {
        $record = notice_test_record($private);
        $outcome = notice_outcome('ok', notice_test_return(notice_test_pairs($record)), notice_test_config($private), NOTICE_TEST_PUSH);
        same(['status' => 200, 'show' => 'ok', 'record' => null], $outcome);
        $stored = notice_test_stored($private, $record);
        same(['paid', '123456', []], [$stored['status'], $stored['cpay_ref'], $stored['extra_refs']]);
        same(1, count($stored['notices']));
        same(['ok', 'push'], [$stored['notices'][0]['kind'], $stored['notices'][0]['via']]);
        same($stored['notices'][0]['at'], $stored['updated'], 'updated moves with the notice');
        $copies = notice_test_copies($private);
        same([['ok', 'push', true]], array_map(static fn (array $copy): array => [$copy['kind'], $copy['via'], $copy['verified']], $copies));
        same('TMZK4A7Q2B', $copies[0]['fields']['Details2']);
        same([], notice_test_codes($private));
    });
});

test('notice_outcome: a verified browser return is answered with the record as it now stands', function (): void {
    notice_test_private(function (string $private): void {
        $record = notice_test_record($private);
        $config = notice_test_config($private);
        $failed = notice_outcome('fail', notice_test_return(notice_test_pairs($record), null), $config, NOTICE_TEST_BROWSER);
        same([200, 'result', 'failed'], [$failed['status'], $failed['show'], $failed['record']['status']]);
        $paid = notice_outcome('ok', notice_test_return(notice_test_pairs($record)), $config, NOTICE_TEST_BROWSER);
        same([200, 'result', 'paid', '123456'], [$paid['status'], $paid['show'], $paid['record']['status'], $paid['record']['cpay_ref']]);
        $late = notice_outcome('fail', notice_test_return(notice_test_pairs($record)), $config, NOTICE_TEST_BROWSER);
        same('paid', $late['record']['status'], 'paid stays paid, and the fail address shows it');
        same(notice_test_stored($private, $record), $late['record'], 'the record of the answer is the stored one');
        same(['browser', 'browser', 'browser'], array_column($late['record']['notices'], 'via'));
        same([], notice_test_codes($private), 'a browser is not an unlisted sender');
    });
});

test('notice_outcome: a verified notice from an address that is neither cPay\'s nor a browser is applied and logged with the address', function (): void {
    notice_test_private(function (string $private): void {
        $record = notice_test_record($private);
        $outcome = notice_outcome('ok', notice_test_return(notice_test_pairs($record)), notice_test_config($private), NOTICE_TEST_UNLISTED);
        same([200, 'result', 'paid'], [$outcome['status'], $outcome['show'], $outcome['record']['status']]);
        same(['unlisted'], array_column($outcome['record']['notices'], 'via'));
        same(['cpay_push_from_unlisted 198.51.100.7'], notice_test_codes($private));
        same(['unlisted'], array_column(notice_test_copies($private), 'via'));
    });
});

test('notice_outcome: what cannot be confirmed changes nothing, is logged by its code and answered 200', function (): void {
    $cases = [
        'cpay_bad_checksum' => [static fn (array $r): array => array_merge(notice_test_return(notice_test_pairs($r)), ['ReturnCheckSum' => str_repeat('A', 32)]), false],
        'cpay_wrong_merchant' => [static fn (array $r): array => notice_test_return(notice_test_pairs($r, ['PayToMerchant' => '1234567890'])), false],
        'cpay_bad_reference' => [static fn (array $r): array => notice_test_return(notice_test_pairs($r, ['Details2' => '../' . $r['ref']])), false],
        'cpay_unknown_payment' => [static fn (array $r): array => notice_test_return(notice_test_pairs($r, ['Details2' => 'TMZK4A0000'])), true],
        'cpay_amount_mismatch' => [static fn (array $r): array => notice_test_return(notice_test_pairs($r, ['AmountToPay' => '100'])), true],
        'cpay_ok_without_ref' => [static fn (array $r): array => notice_test_return(notice_test_pairs($r), null), true],
    ];
    foreach ($cases as $code => [$fields, $verified]) {
        notice_test_private(function (string $private) use ($code, $fields, $verified): void {
            $record = notice_test_record($private);
            $config = notice_test_config($private);
            same(['status' => 200, 'show' => 'ok', 'record' => null], notice_outcome('ok', $fields($record), $config, NOTICE_TEST_PUSH), "$code, push");
            same(['status' => 200, 'show' => 'unconfirmed', 'record' => null], notice_outcome('ok', $fields($record), $config, NOTICE_TEST_BROWSER), "$code, browser");
            same(['status' => 200, 'show' => 'unconfirmed', 'record' => null], notice_outcome('ok', $fields($record), $config, NOTICE_TEST_UNLISTED), "$code, unlisted");
            same([$code, $code, $code], notice_test_codes($private), "$code is logged each time, and nothing else");
            same($record, notice_test_stored($private, $record), "$code leaves the record as it was");
            same([$verified, $verified, $verified], array_column(notice_test_copies($private), 'verified'), "$code: the copies");
            same(['.lock', $record['ref'] . '-' . $record['reservation'] . '.json'], array_values(array_diff(scandir(payments_dir($private)), ['.', '..'])), "$code: no other record file");
        });
    }
});

test('notice_outcome: an OK notice needs a cPay reference of 1 to 20 digits that the header names; a fail notice needs none', function (): void {
    notice_test_private(function (string $private): void {
        $record = notice_test_record($private);
        $config = notice_test_config($private);
        foreach (['12A456', '123456789012345678901', ' 123456'] as $cpayRef) {
            same('ok', notice_outcome('ok', notice_test_return(notice_test_pairs($record), $cpayRef), $config, NOTICE_TEST_PUSH)['show']);
        }
        $unsigned = notice_test_return(notice_test_pairs($record), null) + ['cPayPaymentRef' => '123456'];
        notice_outcome('ok', $unsigned, $config, NOTICE_TEST_PUSH);
        same(array_fill(0, 4, 'cpay_ok_without_ref'), notice_test_codes($private), 'a reference that is posted but not signed counts as none');
        same($record, notice_test_stored($private, $record), 'still waiting');
        notice_outcome('fail', notice_test_return(notice_test_pairs($record), null), $config, NOTICE_TEST_PUSH);
        same(['failed', ''], [notice_test_stored($private, $record)['status'], notice_test_stored($private, $record)['cpay_ref']]);
        notice_outcome('ok', notice_test_return(notice_test_pairs($record), '12345678901234567890'), $config, NOTICE_TEST_PUSH);
        same(['paid', '12345678901234567890'], [notice_test_stored($private, $record)['status'], notice_test_stored($private, $record)['cpay_ref']], 'failed becomes paid');
    });
});

test('notice_outcome: a header that does not name AmountToPay marks nothing, and its copy does not count as verified', function (): void {
    notice_test_private(function (string $private): void {
        $record = notice_test_record($private);
        $unnamed = array_diff_key(notice_test_pairs($record), ['AmountToPay' => 0]);
        $fields = notice_test_return($unnamed) + ['AmountToPay' => '2180200'];
        same(
            ['code' => 'cpay_amount_mismatch', 'signed' => []],
            checked_notice($fields, cpay_settings(notice_test_config($private))),
            'an amount that is posted but not signed',
        );
        same('ok', notice_outcome('ok', $fields, notice_test_config($private), NOTICE_TEST_PUSH)['show']);
        same(['cpay_amount_mismatch'], notice_test_codes($private));
        same($record, notice_test_stored($private, $record));
        same([false], array_column(notice_test_copies($private), 'verified'), 'the header must name the amount (design 4.5, point 3)');
    });
});

test('notice_outcome: AmountToPay must be the record\'s amount digit for digit, not merely the same number', function (): void {
    notice_test_private(function (string $private): void {
        $record = notice_test_record($private);
        $config = notice_test_config($private);
        $amounts = ['02180200', ' 2180200', '2180200 ', '2180200.0', '2.1802e6', '+2180200', '21802'];
        foreach ($amounts as $amount) {
            $fields = notice_test_return(notice_test_pairs($record, ['AmountToPay' => $amount]));
            same('ok', notice_outcome('ok', $fields, $config, NOTICE_TEST_PUSH)['show'], json_encode($amount));
            same($record, notice_test_stored($private, $record), json_encode($amount) . ' marks nothing');
        }
        same(array_fill(0, count($amounts), 'cpay_amount_mismatch'), notice_test_codes($private));
    });
});

test('notice_outcome: a fail notice keeps its cPay reference, and the same fields at the OK address then mark the record paid with it', function (): void {
    notice_test_private(function (string $private): void {
        $record = notice_test_record($private);
        $config = notice_test_config($private);
        $fields = notice_test_return(notice_test_pairs($record), '4711');
        same('ok', notice_outcome('fail', $fields, $config, NOTICE_TEST_PUSH)['show']);
        $failed = notice_test_stored($private, $record);
        same(['failed', '4711', []], [$failed['status'], $failed['cpay_ref'], $failed['extra_refs']], 'a declined card: cPay had the card details');
        truthy(blocks_repeat($failed, time()), 'so staff must confirm before charging this reservation again (design 4.3)');
        same('ok', notice_outcome('ok', $fields, $config, NOTICE_TEST_PUSH)['show']);
        $paid = notice_test_stored($private, $record);
        same(['paid', '4711', []], [$paid['status'], $paid['cpay_ref'], $paid['extra_refs']], 'the outcome is not signed: the address decides');
        same(['fail', 'ok'], array_column($paid['notices'], 'kind'));
        same([], notice_test_codes($private));
    });
});

test('notice_outcome: the copy of a verified notice holds what was verified, whatever was posted in front of it', function (): void {
    notice_test_private(function (string $private): void {
        $record = notice_test_record($private);
        $notice = notice_test_return(notice_test_pairs($record));
        $outcome = notice_outcome('ok', notice_test_padding(60) + $notice, notice_test_config($private), NOTICE_TEST_BROWSER);
        same([200, 'result', 'paid'], [$outcome['status'], $outcome['show'], $outcome['record']['status']]);
        $copies = notice_test_copies($private);
        same([true], array_column($copies, 'verified'));
        $kept = $copies[0]['fields'];
        same(CPAY_MAX_FIELDS, count($kept), 'still at most 50 fields');
        $proof = ['ReturnCheckSumHeader', 'ReturnCheckSum', 'PayToMerchant', 'AmountToPay', 'MerchantName', 'AmountCurrency', 'Details1', 'Details2', 'PaymentOKURL', 'PaymentFailURL', 'cPayPaymentRef'];
        same($proof, array_slice(array_keys($kept), 0, count($proof)), 'the header and the checksum, then the values in the header\'s order');
        foreach ($proof as $name) {
            same($notice[$name], $kept[$name], $name . ' as it was sent');
        }
        same('', checked_notice($kept, cpay_settings(notice_test_config($private)))['code'], 'the copy can be verified again');
        same('x', $kept['pad0'], 'what else was posted follows while there is room');
    });
});

test('notice_outcome: a verified notice that marks nothing is kept the same way', function (): void {
    notice_test_private(function (string $private): void {
        $record = notice_test_record($private);
        $notice = notice_test_return(notice_test_pairs($record, ['AmountToPay' => '100']));
        same('unconfirmed', notice_outcome('ok', notice_test_padding(60) + $notice, notice_test_config($private), NOTICE_TEST_BROWSER)['show']);
        $copies = notice_test_copies($private);
        same([true], array_column($copies, 'verified'));
        same(['TMZK4A7Q2B', '100', $notice['ReturnCheckSum']], [$copies[0]['fields']['Details2'] ?? null, $copies[0]['fields']['AmountToPay'] ?? null, $copies[0]['fields']['ReturnCheckSum'] ?? null]);
    });
});

test('notice_outcome: what was not verified is kept as it was posted, its first 50 fields', function (): void {
    notice_test_private(function (string $private): void {
        $record = notice_test_record($private);
        $notice = array_merge(notice_test_return(notice_test_pairs($record)), ['ReturnCheckSum' => str_repeat('A', 32)]);
        notice_outcome('ok', notice_test_padding(60) + $notice, notice_test_config($private), NOTICE_TEST_BROWSER);
        $copies = notice_test_copies($private);
        same([false], array_column($copies, 'verified'));
        same(array_keys(notice_test_padding(CPAY_MAX_FIELDS)), array_keys($copies[0]['fields']));
    });
});

test('notice_outcome: a second OK notice with another cPay reference is kept as a second charge, once', function (): void {
    notice_test_private(function (string $private): void {
        $record = notice_test_record($private);
        $config = notice_test_config($private);
        foreach (['111', '222', '222', '111'] as $cpayRef) {
            notice_outcome('ok', notice_test_return(notice_test_pairs($record), $cpayRef), $config, NOTICE_TEST_PUSH);
        }
        $stored = notice_test_stored($private, $record);
        same(['paid', '111', ['222']], [$stored['status'], $stored['cpay_ref'], $stored['extra_refs']]);
        same(4, count($stored['notices']));
        truthy(has_two_charges($stored), 'flagged');
    });
});

test('notice_outcome: a record that cannot be written is answered 500, also to cPay, and the copy is still kept', function (): void {
    notice_test_private(function (string $private): void {
        $record = notice_test_record($private);
        $config = notice_test_config($private);
        $blocker = payments_dir($private) . '/' . $record['ref'] . '-' . $record['reservation'] . '.json.tmp';
        mkdir($blocker); /* the temporary file of the write cannot be made where a folder has its name */
        notice_test_fill_inbox($private, 500); /* the copy is then the only trace: no ceiling stops it */
        $fields = notice_test_return(notice_test_pairs($record));
        same(['status' => 500, 'show' => 'unconfirmed', 'record' => null], notice_outcome('ok', $fields, $config, NOTICE_TEST_PUSH));
        same(['status' => 500, 'show' => 'unconfirmed', 'record' => null], notice_outcome('ok', $fields, $config, NOTICE_TEST_BROWSER));
        same(['cpay_record_write', 'cpay_record_write'], notice_test_codes($private));
        same($record, notice_test_stored($private, $record), 'unchanged');
        same([true, true], array_column(notice_test_copies($private), 'verified'), 'the only trace of the notice');
        rmdir($blocker);
        same('ok', notice_outcome('ok', $fields, $config, NOTICE_TEST_PUSH)['show'], 'cPay\'s next try');
        same('paid', notice_test_stored($private, $record)['status']);
    });
});

test('notice_outcome: the raw copy never decides the answer', function (): void {
    notice_test_private(function (string $private): void {
        $record = notice_test_record($private);
        file_put_contents($private . '/cpay-inbox', 'a file where the folder should be');
        $outcome = notice_outcome('ok', notice_test_return(notice_test_pairs($record)), notice_test_config($private), NOTICE_TEST_BROWSER);
        same([200, 'result', 'paid'], [$outcome['status'], $outcome['show'], $outcome['record']['status']]);
        same('paid', notice_test_stored($private, $record)['status']);
        same(['cpay_inbox'], notice_test_codes($private));
    });
});

test('notice_outcome: a verified notice is kept in a full inbox and past the hourly cap, and still marks the record', function (): void {
    notice_test_private(function (string $private): void {
        $config = notice_test_config($private);
        notice_test_fill_inbox($private, 500);
        for ($i = 0; $i < 25; $i++) {
            $record = notice_test_record($private, ['ref' => sprintf('TMZK4A%04d', $i)]);
            same('result', notice_outcome('ok', notice_test_return(notice_test_pairs($record)), $config, NOTICE_TEST_BROWSER)['show']);
            same('paid', notice_test_stored($private, $record)['status']);
        }
        same(25, count(notice_test_copies($private)));
        same([], notice_test_codes($private));
    });
});

test('notice_outcome: one verified notice sent again and again keeps 20 copies past the limits; the rest keep the hourly cap', function (): void {
    notice_test_private(function (string $private): void {
        $record = notice_test_record($private);
        $config = notice_test_config($private);
        $fields = notice_test_return(notice_test_pairs($record));
        for ($i = 0; $i < 60; $i++) {
            same('result', notice_outcome('ok', $fields, $config, NOTICE_TEST_UNLISTED)['show'], "send $i");
        }
        $stored = notice_test_stored($private, $record);
        same(['paid', '123456', CPAY_MAX_NOTICES], [$stored['status'], $stored['cpay_ref'], count($stored['notices'])], 'the record ends right');
        same(40, count(notice_test_copies($private)), 'the 20 that joined the record, then the 20 that the hourly cap lets through');
        same([true], array_values(array_unique(array_column(notice_test_copies($private), 'verified'))), 'every copy says it was verified');
        same(['cpay_push_from_unlisted 198.51.100.7' => 20, 'cpay_rate_limited' => 20], array_count_values(notice_test_codes($private)), 'the address is logged only for a notice that joined the record');
    });
});

test('notice_outcome: verified notices that mark nothing keep a stranger\'s limits, and their copies still say verified', function (): void {
    notice_test_private(function (string $private): void {
        $record = notice_test_record($private);
        $config = notice_test_config($private);
        $notices = [
            notice_test_return(notice_test_pairs($record, ['Details2' => 'TMZK4A0000'])),
            notice_test_return(notice_test_pairs($record, ['AmountToPay' => '100'])),
            notice_test_return(notice_test_pairs($record), null),
        ];
        for ($i = 0; $i < 25; $i++) {
            same('unconfirmed', notice_outcome('ok', $notices[$i % 3], $config, NOTICE_TEST_BROWSER)['show'], "send $i");
        }
        same($record, notice_test_stored($private, $record), 'nothing is marked');
        same(array_fill(0, 20, true), array_column(notice_test_copies($private), 'verified'), '20 copies in the hour, each saying it was verified');
        same(['cpay_unknown_payment' => 9, 'cpay_amount_mismatch' => 8, 'cpay_ok_without_ref' => 8, 'cpay_rate_limited' => 5], array_count_values(notice_test_codes($private)));
    });
});

test('notice_outcome: what is not a notice, or arrives before the key is set, is kept as before and answered with the neutral page', function (): void {
    notice_test_private(function (string $private): void {
        $record = notice_test_record($private);
        $neutral = ['status' => 200, 'show' => 'neutral', 'record' => null];
        same($neutral, notice_outcome('ok', ['Details2' => $record['ref'], 'AmountToPay' => '2180200'], notice_test_config($private), NOTICE_TEST_PUSH), 'no checksum');
        $fields = notice_test_return(notice_test_pairs($record));
        same($neutral, notice_outcome('ok', $fields, notice_test_config($private, ['key' => '']), NOTICE_TEST_PUSH), 'no key');
        same($neutral, notice_outcome('ok', $fields, ['cpay' => []] + notice_test_config($private), NOTICE_TEST_BROWSER), 'no cPay settings');
        same([false, false, false], array_column(notice_test_copies($private), 'verified'));
        $via = array_count_values(array_column(notice_test_copies($private), 'via'));
        same([2, 1], [$via['push'] ?? 0, $via['browser'] ?? 0], 'two from cPay\'s address, one from a browser');
        same($record, notice_test_stored($private, $record), 'nothing is marked');
        same([], notice_test_codes($private));
    });
});

test('notice_outcome: more than 16 KB is logged, kept nowhere and answered with the neutral page, verified or not', function (): void {
    notice_test_private(function (string $private): void {
        $record = notice_test_record($private);
        $fields = notice_test_return(notice_test_pairs($record));
        $large = ['CONTENT_LENGTH' => '16385'] + NOTICE_TEST_PUSH;
        same(['status' => 200, 'show' => 'neutral', 'record' => null], notice_outcome('ok', $fields, notice_test_config($private), $large));
        same(['cpay_too_large'], notice_test_codes($private));
        same([], notice_test_copies($private));
        same($record, notice_test_stored($private, $record));
        same('ok', notice_outcome('ok', $fields, notice_test_config($private), ['CONTENT_LENGTH' => '16384'] + NOTICE_TEST_PUSH)['show'], '16 KB itself is taken');
    });
});

test('notice_outcome: the 16 KB are counted on the fields as well, so a body without a Content-Length (sent in chunks) is not past the limit', function (): void {
    notice_test_private(function (string $private): void {
        $record = notice_test_record($private);
        $config = notice_test_config($private);
        $neutral = ['status' => 200, 'show' => 'neutral', 'record' => null];
        $unmeasured = ['REMOTE_ADDR' => NOTICE_TEST_CPAY_ADDRESS];
        $padded = notice_test_return(notice_test_pairs($record)) + ['Padding' => str_repeat('x', 17000)];
        same('', checked_notice($padded, cpay_settings($config))['code'], 'a valid notice, only too large');
        same($neutral, notice_outcome('ok', $padded, $config, $unmeasured), 'no Content-Length');
        same($neutral, notice_outcome('ok', $padded, $config, NOTICE_TEST_PUSH), 'a Content-Length that says 700 bytes');
        same($neutral, notice_outcome('ok', [str_repeat('n', 17000) => '1', 'keep' => 'yes'], $config, $unmeasured), 'one long name');
        same(['cpay_too_large', 'cpay_too_large', 'cpay_too_large'], notice_test_codes($private));
        same([], notice_test_copies($private), 'nothing is kept');
        same($record, notice_test_stored($private, $record), 'nothing is marked');
        same($neutral, notice_outcome('ok', ['a' => str_repeat('x', 16383)], $config, $unmeasured));
        same(1, count(notice_test_copies($private)), 'fields of 16 KB in all are taken');
        same($neutral, notice_outcome('ok', ['a' => str_repeat('x', 16384)], $config, $unmeasured));
        same(1, count(notice_test_copies($private)), 'one byte more is not');
        same(4, count(notice_test_codes($private)));
    });
});
