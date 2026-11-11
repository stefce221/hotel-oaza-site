<?php
/* Hotel Oaza — the pages /cpay/ok.php and /cpay/fail.php answer with: the neutral page for anyone who opens the
   address, the result of a payment for the staff browser that returns from cPay, and the page that says the
   status could not be confirmed. Wording: section 8 of the design
   (docs/superpowers/specs/2026-11-11-cpay-vcc-charging-design.md). A result is written from the payment's record
   only, never from what was posted, and every value of the record goes through esc(). */
declare(strict_types=1);

require_once __DIR__ . '/pages.php';
require_once __DIR__ . '/charge.php';
require_once __DIR__ . '/payments.php';

const NOTICE_PAGE_TITLE = 'Хотел Оаза — статус на плаќањето';
/* Where staff start the next charge. */
const NOTICE_STAFF_PAGE = '/naplata/';
const NOTICE_HOTEL_CONTACT = '<a href="tel:+38970310300">+389 70 310 300</a>, <a href="mailto:info@oazahotel.com.mk">info@oazahotel.com.mk</a>';
const NOTICE_RESULT_CSS = '.button{display:inline-block;padding:.6rem 1.2rem;border-radius:.4rem;background:#0a6f99;color:#fff;'
    . 'text-decoration:none;font-weight:600}.flag{padding:.6rem .9rem;border-left:.3rem solid #b3261e;background:#fdecea}';

/** The bilingual page for a visit without fields and for fields that are no notice: its text never changed. */
function neutral_page(): string
{
    return page_shell(NOTICE_PAGE_TITLE, implode("\n", [
        '<h1>Го примивме статусот на плаќањето.</h1>',
        '<p>Ви благодариме. За прашања околу плаќањето, контактирајте го хотелот: ' . NOTICE_HOTEL_CONTACT . '.</p>',
        '<p><a href="/">Хотел Оаза — почетна</a></p>',
        '<hr>',
        '<div lang="en">',
        '<h2>Payment status received.</h2>',
        '<p>Thank you. For any question about your payment, contact the hotel: ' . NOTICE_HOTEL_CONTACT . '.</p>',
        '<p><a href="/en/">Hotel Oaza — home</a></p>',
        '</div>',
    ]));
}

/** The link to the staff page, drawn as a button. */
function notice_staff_button(string $label): string
{
    return '<p><a class="button" href="' . esc(NOTICE_STAFF_PAGE) . '">' . esc($label) . '</a></p>';
}

/** What a paid record shows: the amount as staff read it, the reservation, cPay's reference and the payment's code. */
function notice_paid_body(array $record): string
{
    $text = static fn (string $key): string => esc(is_string($record[$key] ?? null) ? $record[$key] : '');
    $amount = is_int($record['amount'] ?? null) ? format_denars($record['amount']) : '';
    return implode("\n", array_merge(
        [
            '<h1>Наплатено: ' . esc($amount) . ' ден.</h1>',
            '<p>Резервација ' . $text('reservation') . ' · cPay реф. ' . $text('cpay_ref') . ' · шифра ' . $text('ref') . '</p>',
        ],
        has_two_charges($record) ? ['<p class="flag">Две наплати: проверете во cPay порталот.</p>'] : [],
        [notice_staff_button('Следна наплата')],
    ));
}

/** What a failed record shows: the usual reasons, the warning that the payment may have passed all the same, the way back. */
function notice_failed_body(): string
{
    return implode("\n", [
        '<h1>Не е наплатено.</h1>',
        '<p>Најчесто: погрешни податоци од картичката или износ поголем од состојбата на картичката.</p>',
        '<p>Ако на cPay притиснавте Назад или ја освеживте страницата, плаќањето можеби сепак поминало. '
            . 'Погледнете ја листата по неколку минути пред да пробате пак.</p>',
        notice_staff_button('Назад кон наплата'),
    ]);
}

/**
 * The page for the staff browser, from the record's current status: paid or failed. A record that is neither
 * (still waiting, or not a record) gets the page that says the status could not be confirmed.
 */
function result_page(array $record): string
{
    $status = $record['status'] ?? '';
    if ($status === 'paid') {
        return page_shell(NOTICE_PAGE_TITLE, notice_paid_body($record), 'mk', NOTICE_RESULT_CSS);
    }
    if ($status === 'failed') {
        return page_shell(NOTICE_PAGE_TITLE, notice_failed_body(), 'mk', NOTICE_RESULT_CSS);
    }
    return unconfirmed_page();
}

/** The page for a notice that could not be verified or matched, and for a failure of the site itself. Anyone can be
    shown it, so it says what staff must do and does not lead to the staff page. */
function unconfirmed_page(): string
{
    return page_shell(NOTICE_PAGE_TITLE, implode("\n", [
        '<h1>Статусот на плаќањето не можеше да се потврди.</h1>',
        '<p>Ако сте од хотелот: не наплаќајте повторно, прво проверете во cPay порталот.</p>',
        '<hr>',
        '<div lang="en">',
        '<p>The payment status could not be confirmed. Please contact the hotel.</p>',
        '</div>',
        '<p>' . NOTICE_HOTEL_CONTACT . '</p>',
    ]));
}
