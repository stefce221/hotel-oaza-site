# Charging Booking.com virtual cards through cPay: design

Date: 2026-11-11. Approved by the owner on 2026-11-11 (reservation number required, digits only).

This repository is public. This document, the code and the tests never contain the hotel's merchant number,
merchant name, checksum key, or any real CheckSum / ReturnCheckSum value. Tests and examples use the sample
values printed in cPay's specification and its public test key `TEST_PASS`.

## 1. Purpose

Hotel staff receive virtual credit cards (VCCs) from Booking.com and charge each one through the hotel's cPay
merchant account (CaSys, Komercijalna banka). They charge in batches: about two dozen cards in half an hour.
The page that did this on the old Wix site is gone. This project adds a staff-only page to the new site that
starts a cPay payment, and makes the two addresses registered with CaSys (`/cpay/ok.php`, `/cpay/fail.php`)
verify and record what cPay reports back.

Out of scope: a payment page for guests, the hotel's own pay-by-link, refunds, card logos on public pages, an
EUR-to-MKD calculator, and cPay's newer `vpos.cpay.com.mk` addresses with a SHA-256 checksum (see 3.4).

## 2. The flow

1. Staff open `https://www.oazahotel.com.mk/naplata/` (linked from nowhere, `noindex`). Apache asks for a user
   name and password (Basic auth). The owner creates the users in cPanel (Directory Privacy).
2. The page (Macedonian only) shows a form (amount in denars, Booking reservation number) and the latest 50
   charges with their state.
3. The form posts to `/naplata/start.php`. It checks the input, writes a "waiting" record and answers with a
   page that posts itself to cPay.
4. Staff type the virtual card on cPay's page. The site never sees card data.
5. cPay posts a notice to `/cpay/ok.php` or `/cpay/fail.php` (server to server, "push"), then sends the staff
   browser to the same address with the same fields.
6. `ok.php` / `fail.php` verify the notice, update the record, keep a raw copy, answer cPay with 200, and show
   the staff browser the result with a link back to `/naplata/`.

## 3. The cPay protocol as this site uses it

Source: cPay Merchant integration specification v3.3 and Testing Procedures v2.7 (CaSys, public PDFs).

### 3.1 The request

A form `method="post"` (no `enctype`, no `target`, UTF-8) to the PaymentURL, with exactly these fields and
nothing else (cPay treats a posted parameter that is missing from the header as fraud):

| Field | Value |
|---|---|
| AmountToPay | whole denars followed by `00` |
| PayToMerchant | config `cpay.merchant_id` |
| MerchantName | config `cpay.merchant_name` |
| AmountCurrency | `MKD` |
| Details1 | `booking <reservation number>` |
| Details2 | the payment's reference: 10 characters `[0-9A-Z]`, unique |
| PaymentOKURL | config `cpay.ok_url` |
| PaymentFailURL | config `cpay.fail_url` |
| CheckSumHeader | see 3.2 |
| CheckSum | see 3.2 |

The eight parameters are listed in this order in the header. This is the order of the merchant's redirect
template and of the requests that were accepted from the old site.

The submit button has no `name`. Characters that cPay treats as an attack (`'`, `"`, `@@`) can never be sent:
the amount and the reservation number are digits only, and everything else comes from config or is generated.

### 3.2 The checksum

- Header: two-digit count, then each parameter name followed by a comma (also after the last), then one
  three-digit length per value. Lengths count characters, not bytes.
- Input string: header, then the values in header order, then the checksum key.
- CheckSum: MD5 of the UTF-8 input string, 32 hex characters, upper case.
- A value that is empty, is not valid UTF-8 or is longer than 999 characters cannot be signed.

The specification's worked examples are the first unit tests:
`34F2872495067872C7D11C4D0F6A3DE2` (8 parameters), `1AEB4E68DCF02D51C54A269EC26D94DB` (18 parameters) and
`97F4E18E88A48D4BAA1742164A3AFD8B` (return checksum), all with the key `TEST_PASS`.

### 3.3 What comes back

cPay echoes the request fields and adds `cPayPaymentRef` (only when card details were submitted),
`ReturnCheckSumHeader` and `ReturnCheckSum`, plus fields that are not signed and are ignored (`ServiceID`,
`Details3`, `iseCollect`, `isPreauthorization`, the empty cardholder fields, the request's own `CheckSum`).
In the return header the first two names are swapped and `cPayPaymentRef` is appended when present. The handler
does not assume an order: it reads the header.

The same fields arrive by push (POST) and through the staff browser (POST or GET: the specification allows
both, so the handler reads POST and, when POST is empty, the query string). A POST body that PHP does not parse
into text fields itself (no Content-Type, `text/plain`, `application/octet-stream`, a form of lists only, a
multipart type PHP does not parse, such as one without a boundary) is read by the handler, at most 16 KB and one
byte, and parsed as a form body (string values only); the query string then counts only for a request without a
body. No Content-Type rule decides this: a multipart body that PHP did parse leaves `php://input` empty, so reading
it finds nothing and changes nothing.

Timing: push first, then the browser, then a second push 15 seconds later whatever the answer was; further
pushes after 5 minutes and 1 hour only when the answer was not 200; then an e-mail to the merchant. When the
browser is closed early the first notice comes 15 minutes later. A payment abandoned after the card data were
entered is reported to the fail address after 30 minutes; one abandoned on cPay's first screen may never be
reported (it stays `Нема одговор` and is looked up in the portal). cPay documents a case where the browser lands on the fail address although the card
was debited, and the push to the OK address follows.

The outcome is not signed: the same signed fields are valid at either address. Which address was called is the
only statement of success. Someone who holds the signed notice of a declined attempt could post it to `ok.php`;
the record list is therefore a help for staff, and the cPay merchant portal and the bank decide about money.

### 3.4 Old address and MD5

In March 2025 cPay announced new PaymentURLs on `vpos.cpay.com.mk` and a redirect template that uses SHA-256;
required for new merchants, recommended for existing ones "in coordination with CaSys". This merchant is an
existing one and its charges were accepted with the old address and MD5 in October 2026. Specification v3.3
documents only the old address and MD5. So: `cpay.hash` accepts only `md5`, and `cpay.payment_url` must be an
`https` address on `www.cpay.com.mk` or `vpos.cpay.com.mk`. Supporting SHA-256 needs the new template's worked
example in the tests first; each record stores the hash name and PaymentURL it was started with.

## 4. Rules

### 4.1 Input

- Amount: digits only, 1 to 7 of them, no leading zero, from 1 to `cpay.max_amount`. Anything else is refused
  with the typed value kept. Nothing is cleaned, cast or rounded (`21.802` and `21802,00` are refused).
  Digits with a digit too many count as "too large" (the limit message), not as mistyped.
- Reservation number: required, 6 to 20 digits.
- Reference (Details2): six base-36 characters of the time in seconds, then four random ones, upper case. It is
  issued by the form page and bound into the form's token.

### 4.2 One form, one payment

The form carries the reference and a token: HMAC-SHA256 over the staff user, the reference and the hour, with a
secret the site creates itself on first use (`private/cpay-staff-secret`, 32 random bytes). The token is
accepted for the current and the previous hour. `start.php` creates the record only if no record with that
reference exists; a repeated POST (Back, reload, double click) creates nothing, sends nothing to cPay and shows
"already started". A stale token shows the form again with the typed values and a fresh reference.

### 4.3 The same reservation twice

Before a record is created, `start.php` looks for records with the same reservation number. If one is paid,
waiting, without an answer, or failed less than 45 minutes ago with a cPay reference, staff see that record and
must confirm ("Да, наплати повторно") before going on. The confirm page posts back the codes of the records it
showed (`seen`); a confirmation goes on only when every record that stands in the way at that moment is among
them, otherwise the page asks again with all of them (another person's charge started meanwhile is shown first).

The look at the earlier records and the writing of the new one are one step: `start.php` holds a lock of its own
(`private/cpay-staff-start.lock`) from the first to the second, so two forms for one reservation sent at the same
moment cannot both pass. A lock that does not come free within about ten seconds answers 500 and starts nothing.

### 4.4 States

| Stored status | Shown as | Meaning |
|---|---|---|
| `waiting`, younger than 45 minutes | Чека одговор | cPay has not reported. Do not charge again. |
| `waiting`, older | Нема одговор | Look it up in the cPay portal by its code before charging again. |
| `paid` | Наплатено | A verified notice arrived at the OK address. |
| `failed` | Не е наплатено | A verified notice arrived at the fail address, and none at the OK address. |

Transitions, applied under one lock:

- OK notice with a `cPayPaymentRef`: `waiting` or `failed` becomes `paid`. `paid` is the only final state.
- OK notice for a `paid` record: the same `cPayPaymentRef` is a repeat; a different one is kept as a second
  reference and the row is flagged "две наплати: проверете во cPay порталот".
- Fail notice: `waiting` becomes `failed`; `paid` stays `paid`.
- Time never changes a status.
- Every notice is added to the record with its time, kind (`ok` / `fail`) and channel (`push` / `browser` /
  `unlisted`), up to 20 per record; a later one still applies its status rule. The record's `updated` time
  moves with every change.

### 4.5 What counts as a verified notice

1. `ReturnCheckSumHeader` parses as count, names, lengths; no name twice; every named field is a posted string
   of exactly that many characters.
2. MD5(header + values + key) equals `ReturnCheckSum` (compared case-insensitively with `hash_equals`).
3. Only the values named in the header are used afterwards. The header must name `PayToMerchant`, `AmountToPay`
   and `Details2`. `PayToMerchant` equals the configured merchant number; `MerchantName` and `AmountCurrency`,
   when named, equal the configured name and `MKD`.
4. `Details2` matches `^[0-9A-Z]{10}$` (checked before any file name is built) and names an existing record.
5. `AmountToPay` equals the record's amount times 100.
6. For `paid`: `cPayPaymentRef` is named in the header and is 1 to 20 digits (the specification says numeric,
   up to 10). An OK notice without it marks nothing.

The sender's address is never used for trust. `cpay.push_ips` decides only the channel label, the answer's
body (a short `OK` for a push, a page for a browser) and the existing exemption from the hourly cap for
unverified notices. The channel is `push` for an address in `cpay.push_ips`; otherwise `browser` when the
request carries an `Origin` or `Sec-Fetch-Site` header, else `unlisted`.

### 4.6 Answers of `ok.php` and `fail.php`

| Request | Status | Body |
|---|---|---|
| No fields and no body (someone opens the address, an empty POST) | 200 | the neutral page, as before; nothing logged |
| More than 16 KB | 200 | the neutral page; nothing kept, nothing marked; logged `cpay_too_large` |
| A body that holds no field, even parsed as a form (a form of lists only) | 200 | the neutral page; logged `cpay_unparsed_body` (the code only); its first 1024 characters kept under the old limits |
| Fields without `ReturnCheckSum` and `ReturnCheckSumHeader` | 200 | the neutral page; kept under the old limits |
| Notice-shaped, cPay settings incomplete (no key yet) | 200 | the neutral page; kept under the old limits |
| Notice-shaped, config cannot be loaded | 500 | "could not be confirmed" page |
| Notice-shaped, checksum wrong or no matching record or amount | 200 | "could not be confirmed" page (browser) / `OK` (push) |
| Verified, record updated or a repeat | 200 | result page from the record's current status (browser) / `OK` (push) |
| Verified, record cannot be written | 500 | "could not be confirmed" page |

500 makes cPay retry after 5 minutes and 1 hour and then e-mail the hotel. Verifying and marking never depend
on keeping the raw copy.

### 4.7 Raw copies (`private/cpay-inbox`)

As before, one JSON file per notice, now with `via` (`push` / `browser` / `unlisted`) and `verified`. The copy
of a verified notice leads with the two checksum fields and the signed values, so fields posted in front of
them cannot push them out of the copy (a copy holds 50 fields).

A verified notice that is added to its payment's record is kept whatever its sender, without the hourly cap
and the file ceilings; so is one whose record could not be written (the copy is then its only trace). A record
takes 20 notices, so this exemption ends there. Every other notice keeps the existing limits (20 per hour and
500 files for strangers; 5000 files for `cpay.push_ips`): the unverified ones, the verified ones that mark
nothing (no such payment, another amount, an OK without a reference), and the verified ones for a payment whose
record already holds 20 notices. Such a copy still says `verified: true`. One captured notice sent in a loop
therefore cannot fill the disk. A body that holds no field (4.6) is kept as `{…, verified: false, fields: [], body}`
with its first 1024 characters, under the same limits as everything unverified.

The 16 KB size limit applies to all, measured on the stated length and on what was actually read: the fields PHP
read, a body the handler read itself, and a form sent without a Content-Length (in chunks), which the handler reads
again, at most 16 KB and one byte, only to measure it. A multipart body sent in chunks is measured by its fields
alone, as PHP leaves nothing of it to read. A verified
notice on the `unlisted` channel that is added to a record is logged as `cpay_push_from_unlisted` with the
sender's address: cPay's sender has probably changed, and `cpay.push_ips` needs the new address.

### 4.8 Retention

Records are deleted after `cpay.retention_days` (default 548, 18 months), raw copies after 90 days. Pruning
runs at most once a day, from the staff page. The rate-limit files keep salted hashes for one hour.

## 5. Protection of the staff page

`src/naplata/.htaccess`:

- `DirectoryIndex index.php`, `Options -Indexes`, `ErrorDocument 401 default`.
- `AuthType Basic`, `AuthUserFile "/home/oazahote/.htpasswds/public_html/naplata/passwd"` (the file cPanel's
  Directory Privacy writes), `Require valid-user` at folder level.
- `<If "%{HTTPS} != 'on'">Require all denied</If>`: the password is never asked for over plain HTTP.
- Every file other than `index.php` and `start.php` is denied with one `FilesMatch` whose pattern does not
  match those two names, the folder's own name `naplata` (a request for `/naplata` is matched against it and
  must reach the redirect to `/naplata/`) or the empty name. `Require all granted` never appears in this file: inside a
  `FilesMatch` it would replace `Require valid-user`.
- Because these lines are in the repository, an upload that replaces the file keeps the protection. Without a
  password file Apache refuses everyone.

PHP checks again, so a server that ignores the file still exposes nothing:

- The staff user is `REMOTE_USER`, else `REDIRECT_REMOTE_USER`. `PHP_AUTH_USER` counts only under PHP's
  built-in server (tests). No user: 403 with the code `staff_no_user` on the page.
- Outside the built-in server the request must be HTTPS and on the host of `cpay.ok_url`. `index.php` on another
  host answers 302 to the right address; `start.php` answers 403.
- `start.php` accepts only a form-encoded POST from the same origin with a valid token, and at most 80 started
  payments per user per hour. A counter that cannot be read or written stops nobody and is logged as
  `staff_rate_unavailable`: the cap guards the disk, not the cards.
- All libraries live in `src/cpay/lib`, which `src/cpay/.htaccess` already closes.
- Pages send `Cache-Control: no-store`, `X-Robots-Tag: noindex` and
  `Content-Security-Policy: frame-ancestors 'none'`. Every dynamic value goes through one escape helper.

The key: read from config in one place, passed only in parameters marked `#[\SensitiveParameter]`, never logged
or echoed. Each entry point installs the exception handler used by `api/enquiry.php` (class name only) and loads
the config inside the same try/catch. Log lines carry codes. PHP's own error log is pointed at
`private/logs/php-errors.log` (the folder is made when it is missing).

## 6. Config (`private/config.php`, section `cpay`)

| Key | Rule |
|---|---|
| `push_ips` | as before |
| `merchant_id` | digits |
| `merchant_name` | 1 to 200 characters of `0-9 A-Z a-z space @ - / ? = &`, no `@@`, no space at either end |
| `key` | not empty, no white space at either end, no tab, line break or other control character |
| `payment_url` | optional; `https` on `www.cpay.com.mk` or `vpos.cpay.com.mk`; default the Macedonian page of the old address |
| `hash` | optional; only `md5` |
| `ok_url`, `fail_url` | `https://<host>/cpay/ok.php` and `https://<same host>/cpay/fail.php` |
| `max_amount` | whole number from 1 to 9999999 |
| `retention_days` | optional; whole number from 30 to 3650; default 548 |

`config_problems()` and `load_config()` never report these: a missing key must not stop the enquiry form or the
keeping of notices. `cpay_problems()` reports them by name, for the staff page and the notice handler.

A `config.php` that raises a warning, notice or deprecation while it loads is still loaded. The likeliest cause is
the key typed between double quotes with a `$` in it: PHP cuts the key short at the `$` and reports "Undefined
variable $<rest of the key>", while its log is still the server's. `load_config()` therefore runs the file under an
error handler of its own that keeps nothing of the message (it only counts) and marks such a config;
`cpay_problems()` then reports `config_warning` first, so the staff page says "not set up" and notices are kept
unverified as with incomplete settings, and `config_problems()` ignores the mark, so the enquiry form goes on. The
cPay entry points and the enquiry endpoint log the code `config_warning` in the private log. A warning PHP raises
while it compiles the file (an octal escape past `\377` between double quotes, whose message quotes the escape)
reaches no error handler: `log_errors` is therefore off while the file loads and restored afterwards, and such a
warning marks the config too, found as PHP's last error, which is then cleared. Under opcache it may be raised, and
the config marked, only on the request that compiles the file; accepted, because a key mangled that way only makes
cPay refuse the checksum, and the owner's checklist and `config.example.php` say to keep the single quotes. A fatal
error while the file loads (not a ParseError, which is thrown) ends the request with nothing logged, the price of
keeping every message about `config.php` out of the server's log.

## 7. Files

```
src/cpay/ok.php, fail.php        entry points: cpay_notice('ok' | 'fail')
src/cpay/lib/inbox.php           raw copies (the code of the old placeholder.php, moved)
src/cpay/lib/checksum.php        header, checksum, return check (pure)
src/cpay/lib/settings.php        cpay_problems(), cpay_settings()
src/cpay/lib/charge.php          amount, reservation number, the eight request fields (pure)
src/cpay/lib/payments.php        the record store
src/cpay/lib/prune.php           retention: deletes old records, old raw copies and left-over temporary files
src/cpay/lib/entry.php           what every entry point does first: exception handler, safe config loading
src/cpay/lib/notice.php          the handler behind ok.php and fail.php
src/cpay/lib/pages.php           escape helper, page shell, response headers
src/cpay/lib/notice-pages.php    neutral / result / "could not be confirmed" pages
src/cpay/lib/staff.php           staff user, request checks, secret, token
src/cpay/lib/staff-pages.php     the staff page's HTML
src/cpay/lib/staff-app.php       what index.php and start.php do
src/naplata/index.php, start.php, .htaccess, .user.ini
```

Records: `private/cpay-payments/<reference>-<reservation>.json` with `ref`, `created`, `updated`, `user`, `amount`,
`reservation`, `details1`, `payment_url`, `hash`, `status`, `cpay_ref`, `extra_refs`, `notices`. New content is
written to a temporary file and renamed, under one lock file, with umask 0077.

The staff page's own files in `private/`: `cpay-staff-secret` (the token secret), `cpay-staff-start.lock` (empty;
the lock of section 4.3) and `cpay-staff-ratelimit.json` (salted hashes of user names, one hour).

PHP stays within 8.0 syntax and the default extensions (`char_count()` instead of `mb_strlen`).

## 8. Wording (Macedonian; the staff page has no English)

Staff page (`/naplata/`):

- Title and heading: `Наплата на картички од Booking`. Under it: `Најавени сте како: <user>`.
- Amount: label `Износ во денари`, hint `Само цифри, без точки и запирки. Пример: 21802`.
  The page echoes what will be charged while typing and on every later page: `Ќе се наплатат: 21.802 ден.`
- Reservation: label `Број на резервација (Booking)`, hint `Само цифри.`
- Button: `Продолжи кон cPay`.
- Notes under the form: `Картичката ја внесувате на страницата на cPay. Таму не притискајте Назад и не освежувајте.`
  and `Ако cPay покаже грешка наместо форма за картичка, застанете и јавете му се на сопственикот.`
- List: heading `Последни наплати`; columns `Време`, `Резервација`, `Износ`, `Состојба`, `cPay реф.`, `Шифра`;
  under it `Денес наплатено: <count> наплати, <sum> ден.`, with `наплата` instead of `наплати` for a count
  that ends in 1 but not in 11 (`1 наплата`, `21 наплата`, `11 наплати`); empty list `Сè уште нема наплати.`
- States: `Чека одговор` with `Не наплаќајте повторно додека чека.`; `Нема одговор` with
  `Проверете во cPay порталот по шифрата пред да наплатите повторно.`; `Наплатено`; `Не е наплатено`;
  flag `Две наплати: проверете во cPay порталот.`; a paid row whose notices include an OK notice on the push
  channel, and a failed row whose notices include a fail notice on the push channel, show `потврдено од cPay`
  (a push of the other kind confirms nothing).
- Errors: amount format `Износот не е добар. Внесете го само со цифри, без точки и запирки (пример: 21802).`;
  amount limit `Износот е поголем од дозволениот. Проверете дали има цифра повеќе.`;
  reservation `Бројот на резервација не е добар. Внесете го само со цифри, како што стои во Booking.`;
  stale form `Страницата беше отворена предолго. Проверете ги износот и резервацијата и притиснете повторно.`;
  already started `Оваа наплата е веќе започната. Не ја праќаме повторно. Погледнете ја листата.`;
  too many `Премногу наплати во еден час. Почекајте малку или јавете му се на сопственикот.`;
  not set up `Наплатата не е поставена до крај. Јавете му се на сопственикот.` (incomplete cPay settings, or a
  config that cannot be loaded); a failure of the site (500: a record or the secret cannot be written, the lock
  of section 4.3 does not come free, any uncaught error) `Наплатата не е започната. Пробајте повторно за една
  минута. Ако се повтори, јавете му се на сопственикот.`
- Same reservation: `За резервација <number> веќе има наплата:` then the rows, then
  `Да се наплати уште еднаш?` with `Не, врати ме` and `Да, наплати повторно`.
- Redirect page: `Ве префрламе на cPay…` and, without JavaScript, the button `Продолжи кон cPay`.
- Refused before the page opens (403 with `staff_no_user`, `staff_not_https` or `staff_wrong_host`):
  `Страницата за наплата не може да се отвори вака.` then `Затворете го прозорецот, отворете ја страницата
  одново и најавете се. Ако пак не се отвори, јавете му ја на сопственикот оваа шифра: <code>`; button
  `Назад кон наплата`.
- Refused request (403 `staff_other_origin`, 400 `staff_not_a_form`, 405 `staff_method`):
  `Барањето не дојде од страницата за наплата. Не е започната наплата.` then `Вратете се на страницата за
  наплата и пробајте одново. Ако се повтори, јавете му ја на сопственикот оваа шифра: <code>`; button
  `Назад кон наплата`.

Result pages (`/cpay/ok.php`, `/cpay/fail.php`, shown to the staff browser):

- Paid: `Наплатено: 21.802 ден.` then `Резервација <number> · cPay реф. <ref> · шифра <code>`; the flag
  `Две наплати: проверете во cPay порталот.` when the record holds a second cPay reference; button
  `Следна наплата` (to `/naplata/`).
- Not paid: `Не е наплатено.` then `Најчесто: погрешни податоци од картичката или износ поголем од состојбата
  на картичката.` and `Ако на cPay притиснавте Назад или ја освеживте страницата, плаќањето можеби сепак
  поминало. Погледнете ја листата по неколку минути пред да пробате пак.`; button `Назад кон наплата`.
- Could not be confirmed: `Статусот на плаќањето не можеше да се потврди.` then `Ако сте од хотелот: не
  наплаќајте повторно, прво проверете во cPay порталот.` and, in English, `The payment status could not be
  confirmed. Please contact the hotel.` with the hotel's phone and e-mail as on the neutral page.
- The neutral page keeps today's text in both languages.

## 9. Deployment and first charge (owner checklist)

1. Upload. `/naplata/` then asks for a password (401); until a user exists, whatever is typed ends in 500.
2. cPanel → Directory Privacy → `public_html/naplata` → create the users with generated passwords (only Create
   User: the folder's protection is the `.htaccess` of section 5).
3. Checks: `https://www.oazahotel.com.mk/naplata/`, `/naplata/index.php` and `/naplata/start.php` answer 401
   without credentials; `/naplata` (no slash) leads to `/naplata/`; `curl -sI http://…/naplata/` answers 403 with
   no `WWW-Authenticate` line (a browser may switch to https by itself); `/cpay/lib/…` and `/naplata/.user.ini`
   answer 403; the page shows the signed-in user name.
4. The owner types merchant number, merchant name and key into `config.php`; one test enquiry afterwards.
5. A notice-shaped POST with a made-up checksum to `/cpay/ok.php` and `/cpay/fail.php` answers 200 and leaves a
   file in `cpay-inbox` (shows that the host's filters let such a body through).
6. First real charge = the test transaction cPay asks for after a domain change. It passes with the browser
   result page, the "Order Status" e-mail and two notices marked `push`. The report to the bank and CaSys names
   the `cPayPaymentRef`. Until this has been seen, every failed or waiting row is checked in the portal before
   the next card.
7. CaSys delivers pushes only to the merchant's registered domain and IP address (spec v3.3 p.14): whenever the
   site's IP changes (another server or host, a proxy or CDN in front), the bank is told beforehand so CaSys
   updates the merchant definition.
