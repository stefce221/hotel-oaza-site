<?php
/* Hotel Oaza — the HTML of the staff page (/naplata/): the form with the list of charges, the question before a
   second charge of one reservation, the page that posts itself to cPay, and the short message pages. Macedonian
   only; the wording is section 8 of the design. Every dynamic value goes through esc(). Pure: no request, no
   files, no clock (the time is given). No external files, fonts or images. */
declare(strict_types=1);

require_once __DIR__ . '/pages.php';
require_once __DIR__ . '/charge.php';
require_once __DIR__ . '/payments.php';

const STAFF_TITLE = 'Наплата на картички од Booking';
const STAFF_PATH = '/naplata/';
const STAFF_START_PATH = '/naplata/start.php';
const STAFF_ZONE = 'Europe/Skopje';
const STAFF_MESSAGES = [
    'amount_format' => 'Износот не е добар. Внесете го само со цифри, без точки и запирки (пример: 21802).',
    'amount_limit' => 'Износот е поголем од дозволениот. Проверете дали има цифра повеќе.',
    'reservation' => 'Бројот на резервација не е добар. Внесете го само со цифри, како што стои во Booking.',
    'stale' => 'Страницата беше отворена предолго. Проверете ги износот и резервацијата и притиснете повторно.',
    'already_started' => 'Оваа наплата е веќе започната. Не ја праќаме повторно. Погледнете ја листата.',
    'too_many' => 'Премногу наплати во еден час. Почекајте малку или јавете му се на сопственикот.',
    'not_set_up' => 'Наплатата не е поставена до крај. Јавете му се на сопственикот.',
    'site_failed' => 'Наплатата не е започната. Пробајте повторно за една минута. Ако се повтори, јавете му се на сопственикот.',
];
/* A refusal => [what happened, what to do; the code follows]. */
const STAFF_REFUSALS = [
    'opening' => ['Страницата за наплата не може да се отвори вака.', 'Затворете го прозорецот, отворете ја страницата одново и најавете се.'
        . ' Ако пак не се отвори, јавете му ја на сопственикот оваа шифра:'],
    'request' => ['Барањето не дојде од страницата за наплата. Не е започната наплата.', 'Вратете се на страницата за наплата и пробајте одново.'
        . ' Ако се повтори, јавете му ја на сопственикот оваа шифра:'],
];
/* The codes of a page that could not open; every other refusal is of a request. */
const STAFF_REFUSED_OPENING = ['staff_no_user', 'staff_not_https', 'staff_wrong_host'];
/* What display_status() returns => [the state as staff read it, what to do about it]. */
const STAFF_STATES = [
    'waiting' => ['Чека одговор', 'Не наплаќајте повторно додека чека.'],
    'no_answer' => ['Нема одговор', 'Проверете во cPay порталот по шифрата пред да наплатите повторно.'],
    'paid' => ['Наплатено', ''],
    'failed' => ['Не е наплатено', ''],
];
/* The shown state => the kind of notice that says the same. Only a push of that kind lets a row say "потврдено од cPay". */
const STAFF_CONFIRMING_KIND = ['paid' => 'ok', 'failed' => 'fail'];
const STAFF_COLUMNS = ['Време', 'Резервација', 'Износ', 'Состојба', 'cPay реф.', 'Шифра'];
const STAFF_NOTES = [
    'Картичката ја внесувате на страницата на cPay. Таму не притискајте Назад и не освежувајте.',
    'Ако cPay покаже грешка наместо форма за картичка, застанете и јавете му се на сопственикот.',
];
/* One column, large fields, a focus ring that shows on every control. The state of a charge is always written out;
   its colour only helps the eye. On a phone each row of the list becomes a small card with the column names beside it. */
const STAFF_CSS = 'body{max-width:60rem;margin:2rem auto 4rem}'
    . 'h1{font-size:1.6rem;line-height:1.25;margin:0 0 .25rem}h2{font-size:1.2rem;margin:2.5rem 0 .75rem}'
    . '.user{margin:0 0 1.5rem;color:#44545c}'
    . 'form.charge,.notes,.errors,.message{max-width:30rem}'
    . 'label{display:block;margin:1.25rem 0 0;font-weight:600}.hint,.mark{display:block;font-size:.9rem;color:#44545c}'
    . 'input[type=text]{display:block;box-sizing:border-box;width:100%;margin:.35rem 0 0;padding:.6rem .75rem;font:inherit;font-size:1.5rem;'
    . 'color:inherit;background:#fff;border:2px solid #5b6b73;border-radius:.4rem}input[aria-invalid=true]{border-color:#b3261e}'
    . 'button,.button{display:inline-block;box-sizing:border-box;margin:1.25rem .75rem 0 0;padding:.8rem 1.4rem;font:inherit;font-size:1.1rem;'
    . 'font-weight:600;line-height:1.3;color:#fff;background:#0a5a7d;border:2px solid #0a5a7d;border-radius:.4rem;text-decoration:none;cursor:pointer}'
    . '.button.quiet,button.quiet{color:#0a5a7d;background:#fff}'
    . ':focus-visible{outline:3px solid #b45309;outline-offset:2px}'
    . '.will{min-height:1.6em;margin:.5rem 0 0;font-size:1.25rem;font-weight:600}.will+label{margin-top:.75rem}'
    . '.errors{box-sizing:border-box;margin:1.25rem 0 0;padding:.5rem 1rem;border-left:.3rem solid #b3261e;background:#fdecea}.errors p{margin:.25rem 0}'
    . '.message,.question{font-size:1.15rem;font-weight:600}.question{margin:1rem 0 0}.notes{margin-top:1.5rem;color:#44545c}'
    . 'table{width:100%;border-collapse:collapse}th,td{padding:.5rem .6rem;text-align:left;vertical-align:top;border-bottom:1px solid #c9d2d6}'
    . 'th{font-size:.9rem;color:#44545c}td:first-child{white-space:nowrap}th:nth-child(3),td.amount{text-align:right;white-space:nowrap}'
    . 'td.code,code{font-family:ui-monospace,Consolas,monospace}td strong{display:block}'
    . 'tr.paid strong{color:#1b6e3c}tr.failed strong{color:#8a1c14}tr.no_answer strong{color:#8a5a00}'
    . '.flag{display:block;font-weight:600;color:#8a1c14}.total{font-weight:600}'
    . '@media (max-width:44rem){thead{position:absolute;left:-999rem}'
    . 'tr{display:block;margin:0 0 .75rem;padding:.5rem .75rem;border:1px solid #c9d2d6;border-radius:.4rem}'
    . 'td{display:grid;grid-template-columns:6.5rem 1fr;column-gap:.75rem;padding:.15rem 0;border:0}'
    . 'td::before{content:attr(data-label);color:#44545c}td>*{grid-column:2}td.amount{text-align:left}}';
/* 'Ќе се наплатат: 21.802 ден.' is said by the server (staff_will_charge) and, while typing, by the script below. */
const STAFF_WILL_BEFORE = 'Ќе се наплатат: ';
const STAFF_WILL_AFTER = ' ден.';
/* Says what will be charged while the amount is typed. A help for the eye only: the server checks the amount itself. */
const STAFF_ECHO_SCRIPT = '(function(){var a=document.getElementById("amount"),w=document.getElementById("will");'
    . 'function show(){var v=a.value,ok=/^[1-9][0-9]{0,6}$/.test(v)&&+v<=+a.getAttribute("data-max");'
    . 'w.textContent=ok?"' . STAFF_WILL_BEFORE . '"+v.replace(/\B(?=(\d{3})+$)/g,".")+"' . STAFF_WILL_AFTER . '":"";}'
    . 'a.addEventListener("input",show);show();})();';
/* A second press within a few seconds (a double click) sends nothing: its answer, "already started", would take the
   place of the page that goes on to cPay. A help only: the server starts a reference once, whatever the browser does. */
const STAFF_ONCE_SCRIPT = '(function(){var sent=false;document.forms[0].addEventListener("submit",function(e){'
    . 'if(sent){e.preventDefault();return;}sent=true;setTimeout(function(){sent=false;},4000);});})();';
const STAFF_SUBMIT_SCRIPT = 'document.forms[0].submit();';

/** A whole page of the staff address: $body inside <main>, then one inline script when given. Both are this file's own markup. */
function staff_page(string $body, string $script = ''): string
{
    $after = $script === '' ? '' : "\n<script>" . $script . '</script>';
    return page_shell(STAFF_TITLE, "<main>\n" . $body . "\n</main>" . $after, 'mk', STAFF_CSS);
}

/** The heading, and under it who is signed in (when known). */
function staff_heading(string $user = ''): string
{
    $signedIn = $user === '' ? '' : "\n" . '<p class="user">Најавени сте како: <strong>' . esc($user) . '</strong></p>';
    return '<h1>' . esc(STAFF_TITLE) . '</h1>' . $signedIn;
}

/** 'Ќе се наплатат: 21.802 ден.' */
function staff_will_charge(int $denars): string
{
    return STAFF_WILL_BEFORE . format_denars($denars) . STAFF_WILL_AFTER;
}

/** One hidden input per field, in the order given. */
function staff_hidden(array $fields): string
{
    $inputs = [];
    foreach ($fields as $name => $value) {
        $inputs[] = '<input type="hidden" name="' . esc((string) $name) . '" value="' . esc((string) $value) . '">';
    }
    return implode("\n", $inputs);
}

function staff_back_link(): string
{
    return '<p><a class="button quiet" href="' . STAFF_PATH . '">Назад кон наплата</a></p>';
}

/** One field of the form: label, hint, then a large input for digits holding what was typed. $more is further attributes. */
function staff_field(string $name, string $label, string $hint, string $typed, string $more): string
{
    return implode("\n", [
        '<label for="' . $name . '">' . esc($label) . '</label>',
        '<span class="hint" id="' . $name . '-hint">' . esc($hint) . '</span>',
        '<input type="text" id="' . $name . '" name="' . $name . '" value="' . esc($typed) . '" inputmode="numeric" autocomplete="off"'
            . ' aria-describedby="' . $name . '-hint"' . $more . '>',
    ]);
}

/** The messages for those codes (keys of STAFF_MESSAGES), announced to a screen reader; '' when there are none. */
function staff_errors(array $codes): string
{
    $lines = [];
    foreach ($codes as $code) {
        if (isset(STAFF_MESSAGES[$code])) {
            $lines[] = '<p>' . esc(STAFF_MESSAGES[$code]) . '</p>';
        }
    }
    return $lines === [] ? '' : '<div class="errors" role="alert">' . "\n" . implode("\n", $lines) . "\n</div>";
}

/**
 * The form that starts a charge. The amount is focused, or the reservation number when only that one was wrong.
 * A field that can no longer take a valid value is cut short by the browser (maxlength), never one that could.
 * @param array{ref: string, token: string, amount: string, reservation: string, errors: string[], max: int} $view
 */
function staff_form(array $view): string
{
    $amountWrong = array_intersect(['amount_format', 'amount_limit'], $view['errors']) !== [];
    $reservationWrong = in_array('reservation', $view['errors'], true);
    $toReservation = $reservationWrong && !$amountWrong;
    $wrong = ' aria-invalid="true"';
    $echo = amount_problem($view['amount'], $view['max']) === '' ? staff_will_charge((int) $view['amount']) : '';
    return implode("\n", [
        '<form class="charge" method="post" action="' . STAFF_START_PATH . '" autocomplete="off">',
        staff_hidden(['ref' => $view['ref'], 'token' => $view['token']]),
        staff_field('amount', 'Износ во денари', 'Само цифри, без точки и запирки. Пример: 21802', $view['amount'],
            ' maxlength="20" data-max="' . $view['max'] . '"' . ($amountWrong ? $wrong : '') . ($toReservation ? '' : ' autofocus')),
        '<p class="will" id="will" aria-live="polite">' . esc($echo) . '</p>',
        staff_field('reservation', 'Број на резервација (Booking)', 'Само цифри.', $view['reservation'],
            ' maxlength="30"' . ($reservationWrong ? $wrong : '') . ($toReservation ? ' autofocus' : '')),
        '<button type="submit">Продолжи кон cPay</button>',
        '</form>',
        '<div class="notes">',
        ...array_map(static fn (string $note): string => '<p>' . esc($note) . '</p>', STAFF_NOTES),
        '</div>',
    ]);
}

/** dd.mm.yyyy hh:mm in Skopje. */
function staff_time(int $time): string
{
    return (new DateTimeImmutable('@' . $time))->setTimezone(new DateTimeZone(STAFF_ZONE))->format('d.m.Y H:i');
}

/**
 * True when cPay's own push said what the row says: a push to the OK address for a paid row, a push to the fail
 * address for a failed one. A push of the other kind confirms nothing. The fields of a declined attempt are valid
 * at ok.php too (design 3.3): posted there by someone who holds them they mark the record paid, and cPay's push
 * about the failure must not vouch for that.
 * @param string $shown what display_status() returns for the record
 */
function staff_confirmed_by_cpay(array $record, string $shown): bool
{
    $kind = STAFF_CONFIRMING_KIND[$shown] ?? '';
    foreach (payment_notices($record) as $notice) {
        if ($kind !== '' && ($notice['via'] ?? '') === 'push' && ($notice['kind'] ?? '') === $kind) {
            return true;
        }
    }
    return false;
}

/** The state of a charge as text: its name, what to do about it, the warning for two charges, and whether cPay itself confirmed it. */
function staff_state(array $record, int $now): string
{
    $shown = display_status($record, $now);
    [$name, $advice] = STAFF_STATES[$shown];
    return implode(' ', array_filter([
        '<strong>' . esc($name) . '</strong>',
        $advice === '' ? '' : '<span class="hint">' . esc($advice) . '</span>',
        has_two_charges($record) ? '<span class="flag">Две наплати: проверете во cPay порталот.</span>' : '',
        staff_confirmed_by_cpay($record, $shown) ? '<span class="mark">потврдено од cPay</span>' : '',
    ], static fn (string $part): bool => $part !== ''));
}

/** One row of the list for a record of the store (payments.php). Every cell names its column, for the narrow layout. */
function staff_row(array $record, int $now): string
{
    $refs = array_filter(
        [$record['cpay_ref'] ?? '', ...array_values($record['extra_refs'] ?? [])],
        static fn (mixed $ref): bool => is_string($ref) && $ref !== '',
    );
    $cells = [
        ['', esc(staff_time(payment_started($record)))],
        ['', esc($record['reservation'])],
        [' class="amount"', esc(format_denars($record['amount']))],
        [' class="state"', staff_state($record, $now)],
        ['', esc(implode(', ', $refs))],
        [' class="code"', esc($record['ref'])],
    ];
    $row = '';
    foreach ($cells as $index => [$class, $content]) {
        $row .= '<td data-label="' . esc(STAFF_COLUMNS[$index]) . '"' . $class . '>' . $content . '</td>';
    }
    return '<tr class="' . display_status($record, $now) . '">' . $row . '</tr>';
}

/** The table of charges, newest first as given. */
function staff_table(array $records, int $now): string
{
    $head = implode('', array_map(static fn (string $column): string => '<th scope="col">' . esc($column) . '</th>', STAFF_COLUMNS));
    $rows = array_map(static fn (array $record): string => staff_row($record, $now), $records);
    return "<table>\n<thead><tr>" . $head . "</tr></thead>\n<tbody>\n" . implode("\n", $rows) . "\n</tbody>\n</table>";
}

/** The word after a count of charges: наплата for a count that ends in 1 but not in 11 (1, 21, 101), otherwise наплати. */
function staff_count_word(int $count): string
{
    return $count % 10 === 1 && $count % 100 !== 11 ? 'наплата' : 'наплати';
}

/**
 * The latest charges and, under them, what was paid today.
 * @param array{rows: array[], today: array{count: int, sum: int}, now: int} $view
 */
function staff_list(array $view): string
{
    if ($view['rows'] === []) {
        return "<h2>Последни наплати</h2>\n<p>Сè уште нема наплати.</p>";
    }
    $count = $view['today']['count'];
    $today = 'Денес наплатено: ' . $count . ' ' . staff_count_word($count) . ', ' . format_denars($view['today']['sum']) . ' ден.';
    return implode("\n", ['<h2>Последни наплати</h2>', staff_table($view['rows'], $view['now']), '<p class="total">' . esc($today) . '</p>']);
}

/**
 * The staff page: who is signed in, the form (or, while the settings are incomplete, the "not set up" message), the list.
 * @param array{user: string, ready: bool, ref: string, token: string, amount: string, reservation: string, errors: string[],
 *              max: int, rows: array[], today: array{count: int, sum: int}, now: int} $view amount and reservation as
 *              typed; errors are keys of STAFF_MESSAGES; rows are records of the store, newest first; now is the time
 */
function staff_form_page(array $view): string
{
    $top = $view['ready']
        ? [staff_errors($view['errors']), staff_form($view)]
        : ['<p class="message">' . esc(STAFF_MESSAGES['not_set_up']) . '</p>'];
    $parts = array_filter([staff_heading($view['user']), ...$top, staff_list($view)], static fn (string $part): bool => $part !== '');
    return staff_page(implode("\n", $parts), $view['ready'] ? STAFF_ECHO_SCRIPT . STAFF_ONCE_SCRIPT : '');
}

/** The codes of the records a confirm page shows, sorted and joined by commas: what its field "seen" carries. */
function staff_seen(array $records): string
{
    $refs = array_map(static fn (array $record): string => (string) ($record['ref'] ?? ''), $records);
    sort($refs, SORT_STRING);
    return implode(',', $refs);
}

/**
 * The question before a reservation is charged again: the earlier charges, what would be charged now, and a form that
 * posts the same values with the codes of the charges shown (seen) and confirm=yes. The way back comes first and is the
 * strong button (the second charge is the quiet one), nothing is focused, and a double click sends once.
 * @param array{amount: string, reservation: string, ref: string, token: string} $typed values that passed the checks
 * @param array[] $earlier the records that stand in the way, newest first
 */
function staff_confirm_page(string $user, array $typed, array $earlier, int $now): string
{
    $again = ['amount' => $typed['amount'], 'reservation' => $typed['reservation'], 'ref' => $typed['ref'], 'token' => $typed['token'],
        'seen' => staff_seen($earlier), 'confirm' => 'yes'];
    return staff_page(implode("\n", [
        staff_heading($user),
        '<p>За резервација ' . esc($typed['reservation']) . ' веќе има наплата:</p>',
        staff_table($earlier, $now),
        '<p class="will">' . esc(staff_will_charge((int) $typed['amount'])) . '</p>',
        '<form method="post" action="' . STAFF_START_PATH . '">',
        staff_hidden($again),
        '<p class="question">Да се наплати уште еднаш?</p>',
        '<a class="button" href="' . STAFF_PATH . '">Не, врати ме</a>',
        '<button type="submit" class="quiet">Да, наплати повторно</button>',
        '</form>',
    ]), STAFF_ONCE_SCRIPT);
}

/**
 * The page that sends the staff browser to cPay: a form (method post, no target, no enctype) to the payment address
 * with the request's fields as hidden inputs and nothing else, submitted by one line of script. Without JavaScript a
 * button shows; it has no name, so it adds no field (cPay refuses a field its header does not name).
 * @param array{action: string, fields: array<string, string>} $request what charge_request() returns
 */
function staff_redirect_page(array $request, int $denars): string
{
    return staff_page(implode("\n", [
        staff_heading(),
        '<p>Ве префрламе на cPay…</p>',
        '<p class="will">' . esc(staff_will_charge($denars)) . '</p>',
        '<form method="post" action="' . esc($request['action']) . '">',
        staff_hidden($request['fields']),
        '<noscript><button type="submit">Продолжи кон cPay</button></noscript>',
        '</form>',
    ]), STAFF_SUBMIT_SCRIPT);
}

/** A page with one message of STAFF_MESSAGES and the way back to the form. Throws InvalidArgumentException('staff_message') for another code. */
function staff_message_page(string $code): string
{
    if (!isset(STAFF_MESSAGES[$code])) {
        throw new InvalidArgumentException('staff_message');
    }
    return staff_page(implode("\n", [staff_heading(), '<p class="message">' . esc(STAFF_MESSAGES[$code]) . '</p>', staff_back_link()]));
}

/**
 * A request that was refused: what happened, what to do, the code of the reason, and the way back to the form. The page
 * could not open (staff_no_user, staff_not_https, staff_wrong_host) or the request did not come from it (any other code).
 */
function staff_refused_page(string $code): string
{
    [$what, $todo] = in_array($code, STAFF_REFUSED_OPENING, true) ? STAFF_REFUSALS['opening'] : STAFF_REFUSALS['request'];
    return staff_page(implode("\n", [
        staff_heading(),
        '<p class="message">' . esc($what) . '</p>',
        '<p>' . esc($todo) . ' <code>' . esc($code) . '</code></p>',
        staff_back_link(),
    ]));
}

/** What a browser shows when it does not follow the redirect to the site's own host: a link to that address. */
function staff_moved_page(string $address): string
{
    return staff_page(staff_heading() . "\n" . '<p><a href="' . esc($address) . '">' . esc($address) . '</a></p>');
}
