<?php
/* The staff page's HTML (staff-pages.php): the form with the list, the question before a second charge, the page that
   posts itself to cPay, and the message and refusal pages. */
declare(strict_types=1);

require_once __DIR__ . '/staff-helpers.php';

test('staff: the form page says what the design says, in one form', function (): void {
    $page = staff_form_page(staff_test_view());
    $texts = [
        '<title>Наплата на картички од Booking</title>', '<h1>Наплата на картички од Booking</h1>', 'Најавени сте како: <strong>recepcija</strong>',
        'Износ во денари', 'Само цифри, без точки и запирки. Пример: 21802', 'Број на резервација (Booking)', 'Само цифри.',
        '<button type="submit">Продолжи кон cPay</button>',
        'Картичката ја внесувате на страницата на cPay. Таму не притискајте Назад и не освежувајте.',
        'Ако cPay покаже грешка наместо форма за картичка, застанете и јавете му се на сопственикот.',
        'Последни наплати', 'Сè уште нема наплати.',
    ];
    foreach ($texts as $text) {
        truthy(str_contains($page, $text), $text);
    }
    same(1, substr_count($page, '<form '), 'one form');
    truthy(str_contains($page, '<form class="charge" method="post" action="/naplata/start.php" autocomplete="off">'), 'it posts to start.php');
    same(['ref' => 'TMZK4A7Q2B', 'token' => str_repeat('a', 64)], staff_test_hidden($page));
    same(1, preg_match('/<input type="text" id="amount" name="amount" value="" inputmode="numeric" autocomplete="off"[^>]* autofocus>/', $page), 'the amount is focused');
    same(1, preg_match('/<input type="text" id="reservation" name="reservation" value="" inputmode="numeric" autocomplete="off"[^>]*>/', $page), 'the reservation');
    same(1, substr_count($page, ' autofocus'), 'one field is focused');
    same(0, preg_match('/<button[^>]* name=/', $page), 'the button has no name');
    same(1, substr_count($page, '<script>'), 'one inline script');
    truthy(str_contains($page, 'addEventListener("input"') && str_contains($page, 'addEventListener("submit"'), 'the echo while typing, and a double click sends once');
    truthy(!str_contains($page, 'Денес наплатено'), 'no total under an empty list');
    truthy(!str_contains($page, 'http://') && !str_contains($page, ' src=') && !str_contains($page, '<link'), 'no external files');
});

test('staff: the form page keeps what was typed, escaped, and says what is wrong', function (): void {
    $page = staff_form_page(staff_test_view(['amount' => '21.802', 'reservation' => '"><script>alert(1)</script>', 'errors' => ['amount_format', 'reservation']]));
    truthy(str_contains($page, 'name="amount" value="21.802"'), 'the amount as typed');
    truthy(str_contains($page, 'name="reservation" value="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"'), 'the reservation, escaped');
    truthy(!str_contains($page, '<script>alert'), 'nothing typed becomes markup');
    truthy(str_contains($page, 'Износот не е добар. Внесете го само со цифри, без точки и запирки (пример: 21802).'), 'amount format');
    truthy(str_contains($page, 'Бројот на резервација не е добар. Внесете го само со цифри, како што стои во Booking.'), 'reservation');
    truthy(str_contains($page, 'role="alert"'), 'the messages are announced');
    $limit = staff_form_page(staff_test_view(['amount' => '300001', 'errors' => ['amount_limit']]));
    truthy(str_contains($limit, 'Износот е поголем од дозволениот. Проверете дали има цифра повеќе.'), 'amount limit');
    truthy(!str_contains($limit, 'Ќе се наплатат: 300.001'), 'an amount over the limit is not announced');
    $stale = staff_form_page(staff_test_view(['amount' => '21802', 'reservation' => '4567890123', 'errors' => ['stale']]));
    truthy(str_contains($stale, 'Страницата беше отворена предолго. Проверете ги износот и резервацијата и притиснете повторно.'), 'stale');
    truthy(str_contains($stale, 'Ќе се наплатат: 21.802 ден.'), 'a good amount is echoed by the server too');
    $user = staff_form_page(staff_test_view(['user' => '<b>&']));
    truthy(str_contains($user, 'Најавени сте како: <strong>&lt;b&gt;&amp;</strong>'), 'the user name is escaped');
});

test('staff: without complete settings the page says so instead of the form, and still shows the list', function (): void {
    $page = staff_form_page(staff_test_view(['ready' => false, 'ref' => '', 'token' => '', 'rows' => [staff_test_record(STAFF_TEST_NOW - 60)]]));
    truthy(str_contains($page, 'Наплатата не е поставена до крај. Јавете му се на сопственикот.'), 'not set up');
    truthy(!str_contains($page, '<form') && !str_contains($page, 'name="amount"'), 'no form');
    truthy(str_contains($page, 'Последни наплати') && str_contains($page, '4567890123'), 'the list');
});

test('staff: the list shows each state as text, with amounts, references and the day\'s total', function (): void {
    $push = [['at' => date('c', STAFF_TEST_NOW - 500), 'kind' => 'ok', 'via' => 'push']];
    $rows = [
        staff_test_record(STAFF_TEST_NOW - 60, [], 'WAIT'),
        staff_test_record(STAFF_TEST_NOW - 600, ['status' => 'paid', 'cpay_ref' => '123456', 'notices' => $push], 'PAID'),
        staff_test_record(STAFF_TEST_NOW - 700, ['status' => 'failed', 'amount' => 5, 'notices' => [['at' => date('c', STAFF_TEST_NOW - 650), 'kind' => 'fail', 'via' => 'browser']]], 'FAIL'),
        staff_test_record(STAFF_TEST_NOW - 2760, ['amount' => 1234567], 'NONE'),
        staff_test_record(STAFF_TEST_NOW - 3000, ['status' => 'paid', 'cpay_ref' => '777001', 'extra_refs' => ['777002'], 'reservation' => '998877665544'], 'TWO2'),
    ];
    $page = staff_form_page(staff_test_view(['rows' => $rows, 'today' => ['count' => 2, 'sum' => 43604]]));
    foreach (['Време', 'Резервација', 'Износ', 'Состојба', 'cPay реф.', 'Шифра'] as $column) {
        truthy(str_contains($page, '>' . $column . '</th>'), $column);
    }
    $row = static fn (string $tail): string => staff_test_row($page, $tail . '</td>');
    truthy(str_contains($row('WAIT'), 'Чека одговор') && str_contains($row('WAIT'), 'Не наплаќајте повторно додека чека.'), 'waiting');
    truthy(str_contains($row('WAIT'), '>21.802<') && str_contains($row('WAIT'), '11.11.2026 10:59'), 'amount and time in Skopje');
    truthy(str_contains($row('PAID'), 'Наплатено') && str_contains($row('PAID'), '123456') && str_contains($row('PAID'), 'потврдено од cPay'), 'paid, confirmed by a push');
    truthy(str_contains($row('FAIL'), 'Не е наплатено') && str_contains($row('FAIL'), '>5<') && !str_contains($row('FAIL'), 'потврдено од cPay'), 'failed, seen by the browser only');
    truthy(str_contains($row('NONE'), 'Нема одговор') && str_contains($row('NONE'), 'Проверете во cPay порталот по шифрата пред да наплатите повторно.'), 'no answer after 45 minutes');
    truthy(str_contains($row('NONE'), '>1.234.567<') && !str_contains($row('NONE'), 'Чека одговор'), 'its amount');
    truthy(str_contains($row('TWO2'), 'Две наплати: проверете во cPay порталот.') && str_contains($row('TWO2'), '777001') && str_contains($row('TWO2'), '777002'), 'two charges, both references');
    truthy(str_contains($row('TWO2'), '998877665544') && !str_contains($row('PAID'), 'Две наплати'), 'the flag is on that row only');
    truthy(str_contains($page, 'Денес наплатено: 2 наплати, 43.604 ден.'), 'the total of the day');
    truthy(!str_contains($page, 'Сè уште нема наплати.'), 'not the text of an empty list');
});

test('staff: today\'s total says наплата for a count that ends in 1 but not in 11, otherwise наплати', function (): void {
    $words = [0 => 'наплати', 1 => 'наплата', 2 => 'наплати', 5 => 'наплати', 11 => 'наплати', 12 => 'наплати', 21 => 'наплата',
        101 => 'наплата', 111 => 'наплати', 1011 => 'наплати', 1021 => 'наплата'];
    foreach ($words as $count => $word) {
        same($word, staff_count_word($count), (string) $count);
    }
    $rows = [staff_test_record(STAFF_TEST_NOW - 600, ['status' => 'paid', 'cpay_ref' => '123456'], 'PAID')];
    $page = staff_form_page(staff_test_view(['rows' => $rows, 'today' => ['count' => 21, 'sum' => 457842]]));
    truthy(str_contains($page, 'Денес наплатено: 21 наплата, 457.842 ден.'), 'on the page');
});

test('staff: "потврдено од cPay" needs a push of the kind that the row shows', function (): void {
    $notice = static fn (string $kind, string $via): array => ['at' => date('c', STAFF_TEST_NOW - 500), 'kind' => $kind, 'via' => $via];
    $paid = ['status' => 'paid', 'cpay_ref' => '222001'];
    $failed = ['status' => 'failed'];
    $cases = [
        'paid, pushed to the OK address' => [$paid, [$notice('ok', 'push')], true],
        'paid, pushed and then seen by the browser' => [$paid, [$notice('ok', 'push'), $notice('ok', 'browser')], true],
        'failed in the browser, then cPay pushed that it is paid (its documented case)' => [$paid, [$notice('fail', 'browser'), $notice('ok', 'push')], true],
        'paid, and a later push to the fail address' => [$paid, [$notice('ok', 'push'), $notice('fail', 'push')], true],
        'cPay pushed a failure, then the same fields came to ok.php through a browser' => [$paid, [$notice('fail', 'push'), $notice('ok', 'browser')], false],
        'cPay pushed a failure, then the same fields came to ok.php from an unlisted address' => [$paid, [$notice('fail', 'push'), $notice('ok', 'unlisted')], false],
        'paid, seen by the browser only' => [$paid, [$notice('ok', 'browser')], false],
        'paid, a push whose kind is not written' => [$paid, [['via' => 'push']], false],
        'failed, pushed to the fail address' => [$failed, [$notice('fail', 'push')], true],
        'failed, seen by the browser only' => [$failed, [$notice('fail', 'browser')], false],
        'failed, with a push to the OK address that marked nothing' => [$failed, [$notice('fail', 'browser'), $notice('ok', 'push')], false],
        'waiting, with a push that marked nothing' => [[], [$notice('ok', 'push')], false],
    ];
    foreach ($cases as $label => [$changes, $notices, $confirmed]) {
        $state = staff_state(staff_test_record(STAFF_TEST_NOW - 600, $changes + ['notices' => $notices]), STAFF_TEST_NOW);
        same($confirmed, str_contains($state, 'потврдено од cPay'), $label);
    }
    $replayed = staff_test_record(STAFF_TEST_NOW - 600, $paid + ['notices' => [$notice('fail', 'push'), $notice('ok', 'browser')]], 'RPLY');
    $row = staff_test_row(staff_form_page(staff_test_view(['rows' => [$replayed]])), 'RPLY</td>');
    truthy(str_contains($row, 'Наплатено') && str_contains($row, '222001') && !str_contains($row, 'потврдено од cPay'), 'the row says paid, and does not say that cPay confirmed it');
});

test('staff: a record written by hand cannot put markup into the list', function (): void {
    $record = staff_test_record(STAFF_TEST_NOW - 60, ['cpay_ref' => '<b>1</b>', 'extra_refs' => ['<i>2</i>', ['x']], 'notices' => ['x', ['via' => 'push']]]);
    $page = staff_form_page(staff_test_view(['rows' => [$record]]));
    truthy(str_contains($page, '&lt;b&gt;1&lt;/b&gt;') && str_contains($page, '&lt;i&gt;2&lt;/i&gt;'), 'escaped');
    truthy(!str_contains($page, '<b>1</b>') && !str_contains($page, '<i>2</i>'), 'not as markup');
});

test('staff: every other value a page shows is escaped too, although the checks let none of these through', function (): void {
    $odd = staff_test_record(STAFF_TEST_NOW - 60, ['reservation' => '<r>&1', 'ref' => '<c>&2']);
    $list = staff_form_page(staff_test_view(['rows' => [$odd]]));
    truthy(str_contains($list, '>&lt;r&gt;&amp;1</td>') && str_contains($list, '>&lt;c&gt;&amp;2</td>'), 'the reservation and code cells of the list');
    $typed = ['amount' => '21802', 'reservation' => '<r>&3', 'ref' => 'TMZK4A7Q2B', 'token' => str_repeat('a', 64)];
    $confirm = staff_confirm_page('recepcija', $typed, [$odd], STAFF_TEST_NOW);
    truthy(str_contains($confirm, 'За резервација &lt;r&gt;&amp;3 веќе има наплата:'), 'the reservation on the confirm page');
    $moved = staff_moved_page('https://www.oazahotel.test/naplata/?a=1&b=<2>');
    truthy(str_contains($moved, '<a href="https://www.oazahotel.test/naplata/?a=1&amp;b=&lt;2&gt;">https://www.oazahotel.test/naplata/?a=1&amp;b=&lt;2&gt;</a>'), 'the address of the moved page');
    truthy(!str_contains($list . $confirm . $moved, '<r>') && !str_contains($list . $confirm . $moved, '<c>'), 'never as markup');
});

test('staff: the confirm page shows the earlier charges and posts the same values again with confirm=yes', function (): void {
    $earlier = [staff_test_record(STAFF_TEST_NOW - 600, ['status' => 'paid', 'cpay_ref' => '123456'], 'PAID')];
    $typed = ['amount' => '21802', 'reservation' => '4567890123', 'ref' => 'TMZK4A7Q2B', 'token' => str_repeat('a', 64)];
    $page = staff_confirm_page('recepcija', $typed, $earlier, STAFF_TEST_NOW);
    foreach (['<h1>Наплата на картички од Booking</h1>', 'Најавени сте како: <strong>recepcija</strong>', 'За резервација 4567890123 веќе има наплата:',
        'Наплатено', '123456', 'Ќе се наплатат: 21.802 ден.', 'Да се наплати уште еднаш?', '<a class="button" href="/naplata/">Не, врати ме</a>',
        '<button type="submit" class="quiet">Да, наплати повторно</button>', '<form method="post" action="/naplata/start.php">'] as $text) {
        truthy(str_contains($page, $text), $text);
    }
    truthy(str_contains($page, 'button.quiet{') || str_contains($page, ',button.quiet'), 'the way back is the strong button, the second charge the quiet one');
    same($typed + ['seen' => $earlier[0]['ref'], 'confirm' => 'yes'], staff_test_hidden($page), 'the same values, the charges it shows, and the yes');
    $two = [staff_test_record(STAFF_TEST_NOW - 60, [], 'WAIT'), ...$earlier];
    same($earlier[0]['ref'] . ',' . $two[0]['ref'], staff_test_hidden(staff_confirm_page('recepcija', $typed, $two, STAFF_TEST_NOW))['seen'], 'the codes of all it shows, sorted');
    same(1, substr_count($page, '<form '), 'one form');
    truthy(strpos($page, 'Не, врати ме') < strpos($page, 'Да, наплати повторно'), 'the way back comes first');
    truthy(!str_contains($page, 'autofocus'), 'neither answer is chosen in advance');
    truthy(str_contains($page, 'addEventListener("submit"') && !str_contains($page, '.submit()'), 'a double click sends once, and nothing is sent by itself');
});

test('staff: the redirect page is a form to cPay with the ten fields and nothing else', function (): void {
    $settings = cpay_settings(staff_test_config('unused', ['payment_url' => 'https://vpos.cpay.com.mk/pay?a=1&b=2']));
    $request = charge_request($settings, 21802, '4567890123', 'TMZK4A7Q2B');
    $page = staff_redirect_page($request, 21802);
    truthy(str_contains($page, '<form method="post" action="https://vpos.cpay.com.mk/pay?a=1&amp;b=2">'), 'method and address, the address escaped');
    truthy(!str_contains($page, 'target=') && !str_contains($page, 'enctype='), 'no target, no enctype');
    same($request['fields'], staff_test_hidden($page));
    same(STAFF_TEST_CPAY_NAMES, array_keys(staff_test_hidden($page)), 'the ten, in order');
    same(10, substr_count($page, '<input'), 'no other field');
    truthy(str_contains($page, '<noscript><button type="submit">Продолжи кон cPay</button></noscript>'), 'a nameless button for a browser without JavaScript');
    same(1, substr_count($page, '<button'), 'one button');
    truthy(str_contains($page, '<script>document.forms[0].submit();</script>'), 'sent by one line of script');
    same(1, substr_count($page, '<script'), 'and by no other script');
    truthy(str_contains($page, 'Ве префрламе на cPay…') && str_contains($page, 'Ќе се наплатат: 21.802 ден.'), 'the text');
    truthy(!str_contains($page, 'TEST_PASS'), 'never the key');
    /* A merchant name may hold "&": unescaped, "&copy" in an attribute would reach cPay as "©" and fail the checksum. */
    $config = staff_test_config('unused', ['merchant_name' => 'Oaza &copy Co']);
    same([], cpay_problems($config), 'a valid merchant name');
    $named = staff_redirect_page(charge_request(cpay_settings($config), 21802, '4567890123', 'TMZK4A7Q2B'), 21802);
    truthy(str_contains($named, '<input type="hidden" name="MerchantName" value="Oaza &amp;copy Co">'), 'the value goes out escaped');
    same('Oaza &copy Co', staff_test_hidden($named)['MerchantName'], 'so the browser posts it as it was signed');
});

test('staff: the message and refusal pages', function (): void {
    $messages = [
        'already_started' => 'Оваа наплата е веќе започната. Не ја праќаме повторно. Погледнете ја листата.',
        'too_many' => 'Премногу наплати во еден час. Почекајте малку или јавете му се на сопственикот.',
        'not_set_up' => 'Наплатата не е поставена до крај. Јавете му се на сопственикот.',
        'site_failed' => 'Наплатата не е започната. Пробајте повторно за една минута. Ако се повтори, јавете му се на сопственикот.',
    ];
    foreach ($messages as $code => $text) {
        $page = staff_message_page($code);
        truthy(str_contains($page, $text) && str_contains($page, '<h1>Наплата на картички од Booking</h1>'), $code);
        truthy(str_contains($page, '<a class="button quiet" href="/naplata/">Назад кон наплата</a>'), 'a way back');
        truthy(!str_contains($page, '<form') && !str_contains($page, '<input') && !str_contains($page, '<script'), 'nothing to send');
    }
    try {
        staff_message_page('no_such_message');
        truthy(false, 'should have thrown');
    } catch (InvalidArgumentException $e) {
        same('staff_message', $e->getMessage());
    }
    $refused = staff_refused_page('staff_no_user');
    truthy(str_contains($refused, '<code>staff_no_user</code>') && !str_contains($refused, '<form'), 'the code');
    truthy(str_contains(staff_refused_page('<x>'), '<code>&lt;x&gt;</code>'), 'escaped');
    $moved = staff_moved_page('https://www.oazahotel.test/naplata/');
    truthy(str_contains($moved, '<a href="https://www.oazahotel.test/naplata/">'), 'a link for a browser that does not follow');
});

test('staff: a refusal says in the design\'s words what happened and what to do, and ends with the code', function (): void {
    $opening = ['Страницата за наплата не може да се отвори вака.', 'Затворете го прозорецот, отворете ја страницата одново и најавете се.'
        . ' Ако пак не се отвори, јавете му ја на сопственикот оваа шифра:'];
    $request = ['Барањето не дојде од страницата за наплата. Не е започната наплата.', 'Вратете се на страницата за наплата и пробајте одново.'
        . ' Ако се повтори, јавете му ја на сопственикот оваа шифра:'];
    $codes = ['staff_no_user' => $opening, 'staff_not_https' => $opening, 'staff_wrong_host' => $opening,
        'staff_other_origin' => $request, 'staff_not_a_form' => $request, 'staff_method' => $request];
    foreach ($codes as $code => [$what, $todo]) {
        $page = staff_refused_page($code);
        truthy(str_contains($page, '<p class="message">' . $what . '</p>'), $code . ': what happened');
        truthy(str_contains($page, '<p>' . $todo . ' <code>' . $code . '</code></p>'), $code . ': what to do, then the code');
        truthy(str_contains($page, '<a class="button quiet" href="/naplata/">Назад кон наплата</a>'), $code . ': the way back');
        truthy(!str_contains($page, '<form') && !str_contains($page, '<script'), $code . ': nothing to send');
    }
});
