# Charging Booking.com virtual cards through cPay: implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. One fresh agent per task,
> test first, then a review of that task, then fixes. Tasks in the same phase touch different files and run side
> by side; a later phase starts only when the earlier one is green.

**Goal:** a staff-only page at `/naplata/` that starts a cPay payment for a Booking.com virtual card, and
`/cpay/ok.php` / `/cpay/fail.php` that verify and record what cPay reports.

**Design:** `docs/superpowers/specs/2026-11-11-cpay-vcc-charging-design.md`. Where this plan and the design
differ, the design wins; say so in your report.

**Stack:** plain PHP (8.0 syntax, default extensions only), Apache `.htaccess`, node:test for HTTP tests, the
repository's own PHP test runner. No new dependency.

---

## Rules for every task

- **Public repository.** No merchant number, merchant name, key, real checksum, inbox file or server path other
  than those already in the repository. Test data: the specification's samples below and the key `TEST_PASS`.
- **PHP 8.0 syntax only** (CI runs 8.0, the server 8.3, this PC 8.5): no `readonly`, enums, `never`,
  first-class callable syntax, `new` in initialisers, `array_is_list()`. No `mb_*` (not installed here): count
  characters with `char_count()` from `src/api/lib/validate.php`. `declare(strict_types=1);` in every file.
- Functions under 50 lines, files under 400, no mutation of arguments, early returns instead of deep nesting.
- Regular expressions that validate input use `\A … \z` (a `$` accepts a trailing newline).
- Every dynamic value in HTML goes through `esc()`. Nothing typed by a visitor is ever logged; log codes only.
- The key travels only in parameters marked `#[\SensitiveParameter]` and is never echoed, logged or put in an
  exception message.
- Write files with the editor tools, never with shell heredocs (they halve backslashes on this PC).
- Tests first: write the test, run it and see it fail for the right reason, then write the code.
- Commands (Git Bash):
  `export PHP_BIN="$LOCALAPPDATA/Microsoft/WinGet/Packages/PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe/php.exe"`
  - one PHP test file: `"$PHP_BIN" test/php/run.php <part of the file name>` (for example `checksum`)
  - all PHP tests: `"$PHP_BIN" test/php/run.php`
  - one HTTP test file: `node --test test/<name>.test.mjs`
  - While tasks of one phase run side by side, run only your own test files (the HTTP tests bind fixed ports).
- Do not commit, push, touch the network, or read anything outside the repository.

## The specification's samples (key `TEST_PASS`)

1. Request, 8 parameters in this order: PaymentOKURL `https://bookstore/ok.html`, PaymentFailURL
   `https://bookstore/fail.html`, AmountToPay `12300`, AmountCurrency `MKD`, PayToMerchant `1000000003`,
   Details1 `purchase of books`, Details2 `Order 25467`, MerchantName `Bookstore`.
   Header `08PaymentOKURL,PaymentFailURL,AmountToPay,AmountCurrency,PayToMerchant,Details1,Details2,MerchantName,025027005003010017011009`,
   CheckSum `34F2872495067872C7D11C4D0F6A3DE2`.
2. Request, 18 parameters in this order: PaymentOKURL `www.OKUrl.com.mk`, PaymentFailURL `www.FailUrl.com.mk`,
   AmountToPay `100`, AmountCurrency `MKD`, PayToMerchant `1234567890`, Details1 `Detali 1`, Details2 `123`,
   MerchantName `ImeNaTrgovecot`, FirstName `Petar`, LastName `Petrevski`, Telephone `38977777777`, Email
   `petarp@gmail.com`, Zip `1000`, Address `KJP 1/2`, City `Skopje`, Country `807`, OriginalAmount `10`,
   OriginalCurrency `EUR`. Lengths `016018003003010008003014005009011016004007006003002003`,
   CheckSum `1AEB4E68DCF02D51C54A269EC26D94DB`.
3. Return for sample 2 with cPayPaymentRef `123456`: the header starts `19PaymentFailURL,PaymentOKURL,` (the
   first two names swapped), ends `…OriginalCurrency,cPayPaymentRef,` + lengths
   `018016003003010008003014005009011016004007006003002003006`, ReturnCheckSum
   `97F4E18E88A48D4BAA1742164A3AFD8B`.

## The payment record

`private/cpay-payments/<ref>-<reservation>.json`:

```json
{
  "ref": "TMZK4A7Q2B", "created": "2026-11-11T10:15:00+01:00", "updated": "2026-11-11T10:16:02+01:00",
  "user": "recepcija", "amount": 21802, "reservation": "4567890123", "details1": "booking 4567890123",
  "payment_url": "https://www.cpay.com.mk/…", "hash": "md5",
  "status": "waiting", "cpay_ref": "", "extra_refs": [],
  "notices": [{"at": "2026-11-11T10:16:02+01:00", "kind": "ok", "via": "push"}]
}
```

`amount` is whole denars (int). `status` is `waiting`, `paid` or `failed`. `via` is `push`, `browser` or
`unlisted`. At most 20 notices are kept per record.

---

## Phase 1 (side by side)

### Task 1: checksum and charge fields

**Files:** create `src/cpay/lib/checksum.php`, `src/cpay/lib/charge.php`, `test/php/checksum.test.php`,
`test/php/charge.test.php`, `test/helpers/cpay.mjs`, `test/cpay-helper.test.mjs`.

`checksum.php` (requires `src/api/lib/validate.php` for `char_count()`); `$pairs` is an ordered map name => value:

```php
const CPAY_REQUEST_ORDER = ['AmountToPay', 'PayToMerchant', 'MerchantName', 'AmountCurrency', 'Details1', 'Details2', 'PaymentOKURL', 'PaymentFailURL'];
/** NN + "name," for each + one 3-digit character count per value. Throws InvalidArgumentException('checksum_input')
    for no pairs, more than 99, a name outside [A-Za-z0-9_]+, a value that is not a string, is empty, is not valid UTF-8 or has over 999 characters. */
function checksum_header(array $pairs): string
/** ['header' => string, 'checksum' => upper-case MD5 of header . values . key]. Throws InvalidArgumentException('checksum_key') for an empty key. */
function cpay_checksum(array $pairs, #[\SensitiveParameter] string $key): array
/** ['names' => string[], 'lengths' => int[]] or null when the header is not NN, NN names each followed by a comma, NN*3 digits, or names a parameter twice. */
function parse_checksum_header(string $header): ?array
/** The signed name => value map when ReturnCheckSum matches ReturnCheckSumHeader, its named values and the key; else null.
    A named field that is absent is refused (the design's rule; cPay sends every field it names). Compared with hash_equals, either letter case. An empty key verifies nothing. */
function verified_return(array $fields, #[\SensitiveParameter] string $key): ?array
```

`charge.php` (requires `checksum.php`):

```php
/** '' | 'amount_format' | 'amount_limit'. Digits only, 1 to 7 of them, no leading zero; never trimmed or cast before the check. */
function amount_problem(string $typed, int $max): string
/** 6 to 20 digits and nothing else. */
function valid_reservation(string $typed): bool
function details1_for(string $reservation): string   // 'booking ' . $reservation
function amount_to_pay(int $denars): string          // 21802 -> '2180200'
function format_denars(int $denars): string          // 21802 -> '21.802'
/** ['action' => payment_url, 'fields' => the eight parameters in CPAY_REQUEST_ORDER, then CheckSumHeader, CheckSum]. */
function charge_request(array $settings, int $denars, string $reservation, string $ref): array
```

`$settings` has `merchant_id`, `merchant_name`, `key`, `payment_url`, `ok_url`, `fail_url` (Task 3's
`cpay_settings()`).

`test/helpers/cpay.mjs` (an independent implementation in JavaScript, so the two check each other):

```js
export const TEST_KEY = "TEST_PASS";
export function checksumHeader(pairs)        // pairs: [[name, value], …]; lengths count code points
export function cpayChecksum(pairs, key)     // { header, checksum }
/** The fields cPay sends back for a request: the request's fields, cPayPaymentRef when given, a few empty unsigned
    fields (ServiceID, Details3, FirstName), ReturnCheckSumHeader (first two names swapped, cPayPaymentRef appended)
    and ReturnCheckSum. */
export function returnNotice(requestPairs, key, { cPayPaymentRef = null } = {})
export function formBody(fields)             // application/x-www-form-urlencoded
```

**Tests:** the three samples in PHP and in JavaScript; `Тёст Strîñğ` counts `011`; header for the eight request
parameters in `CPAY_REQUEST_ORDER`; `checksum_header` refuses each bad input; `parse_checksum_header` refuses a
wrong count, a missing trailing comma, a duplicate name, letters among the lengths; `verified_return` accepts
sample 3 in either letter case, returns only the named fields (an extra posted field is not in the result),
refuses a changed value, a changed length, a missing named field, an empty key, a checksum of the wrong length;
`amount_problem` for `21802` (ok), `0`, `021802`, `21.802`, `21802,00`, ` 21802`, `21802\n`, `12345678`, `300001`
with max 300000, empty; `valid_reservation`; `charge_request` produces exactly ten fields in order and a checksum
that `verified`-style recomputation in the test confirms; `format_denars` for 5, 1000, 21802, 1234567.

### Task 2: the record store

**Files:** create `src/cpay/lib/payments.php`, `test/php/payments.test.php`.

```php
const CPAY_NO_ANSWER_AFTER = 2700;   // 45 minutes
const CPAY_MAX_NOTICES = 20;
function payments_dir(string $privateDir): string            // $privateDir . '/cpay-payments'
/** Six base-36 characters of $now, then four random ones, upper case; sorts by time. */
function new_reference(int $now): string
function valid_reference(string $ref): bool                  // \A[0-9A-Z]{10}\z
function reference_time(string $ref): int                    // the time in the first six characters
/** The complete record of a payment about to be sent: status waiting, no cPay reference, no notices. */
function new_payment(string $ref, string $user, int $amount, string $reservation, string $details1, string $paymentUrl, string $hash, int $now): array
/** Writes the record unless one with that reference exists (checked under the lock). False when it exists.
    Throws InvalidArgumentException for a reference or reservation of the wrong shape, RuntimeException('payments_write') when it cannot write. */
function create_payment(string $dir, array $record): bool
function find_payment(string $dir, string $ref): ?array      // null for a bad reference too
function payments_for_reservation(string $dir, string $reservation): array   // newest first; [] for a bad number
function latest_payments(string $dir, int $limit = 50): array               // newest first
function payments_since(string $dir, int $since): array                     // by reference time, newest first
function paid_total(array $records): array                                  // ['count' => int, 'sum' => int]
/** Applies one verified notice under the lock and returns the record as it now stands; null when there is no such record.
    $kind 'ok' | 'fail'; $via 'push' | 'browser' | 'unlisted'; $cpayRef '' when cPay sent none; $at ISO 8601. */
function apply_notice(string $dir, string $ref, string $kind, string $via, string $cpayRef, string $at): ?array
/** The same, as ['record' => array, 'added' => bool]: whether the notice joined the record's list (design 4.7). */
function apply_notice_result(string $dir, string $ref, string $kind, string $via, string $cpayRef, string $at): ?array
function display_status(array $record, int $now): string     // paid | failed | waiting | no_answer
/** True when staff must confirm before charging this reservation again: paid, waiting (any age), or failed with a cPay reference less than 45 minutes ago. */
function blocks_repeat(array $record, int $now): bool
function has_two_charges(array $record): bool                // extra_refs is not empty
/** At most once a day (marker file): deletes records older than $recordDays (by reference time) and inbox files older than $noticeDays (by the date in their name). */
function prune_old(string $privateDir, int $recordDays, int $noticeDays, int $now): void
```

Rules: design section 4.4. `apply_notice` with `ok` needs a non-empty `$cpayRef`; without one it only adds the
notice. A fail notice stores its `$cpayRef` when the record has none. One lock file (`.lock`) in the folder,
`flock(LOCK_EX)`; new content goes to a temporary file in the same folder and is renamed over the record; folder
0700, files 0600, umask 0077 while writing (restored afterwards). A record file that does not parse is skipped
by the list functions and is `null` for `find_payment`.

**Tests:** reference shape and ordering by time; `create_payment` twice with one reference returns true then
false and leaves the first record; bad reference or reservation is refused before any file name is built
(`../x`, `12345`); waiting → paid; waiting → failed; failed → paid (cPay's documented case); paid then fail
stays paid; paid then ok with the same reference changes nothing but adds the notice; paid then ok with another
reference fills `extra_refs` once; ok without a cPay reference leaves waiting; unknown reference returns null;
the 21st notice is not added; `display_status` at 44 and 46 minutes; `blocks_repeat` for each case;
`payments_for_reservation` finds only that number (a number that is a suffix of another must not match);
`latest_payments` order and limit; `paid_total`; `prune_old` deletes an old record and an old inbox file, keeps
recent ones and files with other names, and does nothing on a second call the same day; an unreadable record
file is skipped.

### Task 3: settings, entry helpers, page shell, test config

**Files:** create `src/cpay/lib/settings.php`, `src/cpay/lib/entry.php`, `src/cpay/lib/pages.php`,
`test/php/settings.test.php`, `test/php/entry.test.php`, `test/php/pages.test.php`; modify
`deploy/private/config.example.php`, `test/php/config.test.php`, `test/helpers/php.mjs`.

```php
// settings.php (pure; no require needed beyond what it uses)
const CPAY_HOSTS = ['www.cpay.com.mk', 'vpos.cpay.com.mk'];
const CPAY_DEFAULT_URL = 'https://www.cpay.com.mk/client/Page/default.aspx?xml_id=/mk-MK/.loginToPay/.simple/';
const CPAY_DEFAULT_RETENTION_DAYS = 548;
/** The cPay settings that are missing or wrong, as dotted names (never values): cpay.merchant_id, cpay.merchant_name, cpay.key,
    cpay.payment_url, cpay.hash, cpay.ok_url, cpay.fail_url, cpay.max_amount, cpay.retention_days. Rules: design section 6. */
function cpay_problems(array $config): array
/** merchant_id, merchant_name, key, payment_url, hash, ok_url, fail_url, host, max_amount, retention_days (defaults filled in).
    Throws RuntimeException('cpay_incomplete') when cpay_problems() is not empty. */
function cpay_settings(array $config): array

// entry.php (requires src/api/lib/bootstrap.php)
/** Uncaught errors: logs "Hotel Oaza <who>: uncaught <class>" and nothing else, then calls $answer unless headers were sent. */
function guard_uncaught(string $who, callable $answer): void
/** The config, or null after logging why in the way api/enquiry.php does (a RuntimeException's message, else class and line). */
function try_load_config(string $who): ?array
/** Points PHP's own error log at <private>/logs/php-errors.log. Does nothing (false) under cli and cli-server or when the folder cannot be made. */
function private_php_log(string $privateDir, string $sapi = PHP_SAPI): bool

// pages.php
function esc(string $value): string                    // htmlspecialchars, ENT_QUOTES | ENT_SUBSTITUTE, UTF-8
/** A complete HTML page: lang, charset, viewport, robots noindex, the title, a small inline style plus $css, then $body. */
function page_shell(string $title, string $body, string $lang = 'mk', string $css = ''): string
/** Status, text/html in UTF-8, Cache-Control: no-store, X-Robots-Tag: noindex, Content-Security-Policy: frame-ancestors 'none', X-Content-Type-Options: nosniff, then the page. Does not exit. */
function send_page(int $status, string $html): void
function send_text(int $status, string $text): void    // the same headers with text/plain
```

`try_load_config()` calls `private_php_log()` after a successful load. `config_problems()` and `load_config()`
must keep ignoring the new cPay settings. Added after review: `load_config()` runs `config.php` under a temporary
error handler that swallows and counts warnings (a key between double quotes with a `$` in it raises "Undefined
variable" with a piece of the key, while PHP's log is still the server's), with `log_errors` off meanwhile, and also
counts a warning raised while PHP compiles the file (an octal escape past `\377`; no handler sees it, so it is
found with `error_get_last()` and then cleared); such a config is returned marked
(`config_warned()`), `cpay_problems()` then reports `config_warning` first, and `try_load_config()` and
`api/enquiry.php` log the code `config_warning` (design 6). The example config gains the new keys with comments in plain words:
`merchant_id`, `merchant_name` and `key` empty (the owner fills them in on the server), `ok_url` and `fail_url`
for `https://www.oazahotel.com.mk`, `max_amount` 300000.

`test/helpers/php.mjs`: `makePrivateDir({ cpayPushIps = null, cpay = null } = {})`; `cpay` is an object of
further cPay settings written into the config (strings quoted and escaped for PHP, numbers bare); export
`TEST_CPAY = { merchant_id: "1000000003", merchant_name: "Bookstore", key: "TEST_PASS", ok_url:
"https://www.oazahotel.test/cpay/ok.php", fail_url: "https://www.oazahotel.test/cpay/fail.php", max_amount: 300000 }`.
Existing callers keep working unchanged.

**Tests:** `cpay_problems` names every missing setting for an empty section and for no section; accepts the
test settings; each rule broken one at a time gives exactly that name (id with a letter; name with `'` or `@@`;
payment_url on http, on another host, with a port, with user info, with a quote; hash `sha256`; ok_url on
http, with another path, fail_url on another host; max_amount `'300000'` as text, 0, 10000000; retention 29);
problems never contain a value; `config_problems` is unchanged by any cPay setting; the example config's
`cpay_problems` are exactly the three empty ones; `cpay_settings` fills the defaults and `host`; `esc` for
`<>&"'` and invalid UTF-8; `page_shell` escapes the title; `private_php_log` under `cli` returns false and
leaves `error_log` alone, and with another sapi name sets it (restore the ini value in the test);
`try_load_config` returns null for a missing file (set `OAZA_CONFIG` with `putenv` in the test and restore it).

---

## Phase 2 (side by side, after phase 1 is green)

### Task 4: the notice handler

**Files:** create `src/cpay/lib/inbox.php` (the keeping code of `placeholder.php`, moved), `src/cpay/lib/notice.php`,
`src/cpay/lib/notice-pages.php`, `test/php/notice.test.php`, `test/cpay-notice.test.mjs` (ports 8774 to 8776);
modify `src/cpay/ok.php`, `src/cpay/fail.php`, `src/cpay/.htaccess` (comment only), `src/api/lib/bootstrap.php`
(comment only), `test/php/cpay.test.php`, `test/cpay.test.mjs` and `test/endpoint-errors.test.mjs` (wording
only: the word "placeholder" goes); delete `src/cpay/lib/placeholder.php`.

```php
// inbox.php: constants and from_cpay() as today, plus
/** Keeps one raw copy: {received, kind, via, verified, fields}. An exempt copy (design 4.7) skips the hourly cap and the file ceilings; others keep today's limits. Never throws. */
function save_cpay_notice(array $config, string $kind, array $fields, string $via, bool $verified, bool $exempt = false): void
/** Added after review: keeps a body that holds no field: {received, kind, via, verified: false, fields: [], body}, its first 1024 characters. Never exempt, never throws. */
function save_cpay_body(array $config, string $kind, string $body, string $via): void
/** Writes a copy of either of the two above unless the limits refuse it (an exempt copy skips them); never throws. */
function keep_cpay_copy(array $config, string $kind, array $copy, bool $exempt): void

// notice.php
function cpay_notice(string $kind): void               // everything ok.php / fail.php do
function notice_fields(array $post, array $get): array // POST, or the query string when POST is empty; string values only
/** Added after review: notice_fields(), except that a POST body PHP did not parse into text fields (not a form, a
    form of lists only, or a multipart type PHP did not parse; a multipart body PHP did parse leaves php://input
    empty, so no Content-Type rule is needed) is read through $read (at most CPAY_MAX_BYTES + 1 bytes) and parsed with parse_str(); a form with text
    fields and no Content-Length is read too, only to be measured; ['fields' => ..., 'body' => ...]. */
function notice_input(array $post, array $get, array $server, callable $read): array
/** A body that holds no field: cpay_unparsed_body (code only), raw copy of its start under the old limits, neutral page. */
function notice_unparsed_outcome(string $kind, string $body, array $config, array $server): array
function notice_shaped(array $fields): bool            // carries ReturnCheckSum and ReturnCheckSumHeader
function notice_channel(bool $fromCpay, array $server): string   // push | browser | unlisted
/** ['code' => '' | 'cpay_bad_checksum' | 'cpay_wrong_merchant' | 'cpay_bad_reference', 'signed' => array]. */
function checked_notice(array $fields, array $settings): array

// notice-pages.php
function neutral_page(): string                        // today's bilingual page, unchanged text
function result_page(array $record): string            // from the record's current status
function unconfirmed_page(): string
```

Order inside `cpay_notice()` (design sections 4.5 to 4.7):

1. `guard_uncaught()` answering 500 with the unconfirmed page.
2. No fields and no body: neutral page, 200. (`notice_input()` reads a POST body PHP did not parse itself.)
3. Config cannot be loaded: 500 + unconfirmed page when notice-shaped, else 200 + neutral page.
4. Body over `CPAY_MAX_BYTES` (the stated length, the fields, or the body as read): log `cpay_too_large`, neutral
   page, 200. A body that was read and holds no field: log `cpay_unparsed_body`, keep its first 1024 characters
   unverified under the old limits, neutral page, 200.
5. Not notice-shaped, or `cpay_problems()` not empty: keep unverified, neutral page, 200.
6. `checked_notice()`. A code: log it, keep unverified, 200 (`OK` for a push, the unconfirmed page otherwise).
7. No record: log `cpay_unknown_payment`, keep as verified, 200 as in step 6.
8. `AmountToPay` differs from the record: log `cpay_amount_mismatch`, keep as verified, 200 as in step 6.
9. `ok` without a `cPayPaymentRef` of 1 to 20 digits: log `cpay_ok_without_ref`, keep as verified, 200 as in step 6.
10. `apply_notice()`. If it throws: log `cpay_record_write`, 500 + unconfirmed page.
11. Keep the raw copy as verified, exempt from the limits only when the notice joined the record (or in step 10's
    case). Channel `unlisted` and the notice joined the record: log `cpay_push_from_unlisted <address>`.
12. 200: `OK` (text) for a push, else the result page of the record as it now stands.

**Tests (HTTP, config with `TEST_CPAY`; records are written as JSON files by the test):** a verified push to
`ok.php` marks the record paid with the cPay reference and answers `OK`; a verified browser return (header
`Sec-Fetch-Site: cross-site`) shows `Наплатено: 21.802 ден.` with reservation, reference and code; the same by
GET; fail then ok ends paid; ok then fail stays paid; a second ok with another reference flags two charges; a
wrong checksum, an unknown reference, a wrong amount, a wrong merchant and an ok without a reference each
change nothing, answer 200 and log their code; the browser version of those shows the unconfirmed text; a
verified notice is kept past the hourly cap (25 in a row) and in a full inbox, and the record is still marked;
a notice-shaped POST with a config that does not parse answers 500 and logs no fragment of the config; a
record folder that cannot be written answers 500 (make the record file's folder a plain file, or skip where
that cannot be simulated, saying so); without a key in the config a notice-shaped POST is kept unverified and
answered with the neutral page; nothing from the key appears in any body or log; all ten existing tests in
`test/cpay.test.mjs` and both in `test/endpoint-errors.test.mjs` still pass. Added after review: a verified push
without a Content-Type or as `text/plain` marks the record; a body that holds no field (also a form of lists only)
is logged `cpay_unparsed_body` once, kept once with nothing of it in either log, and answered with the neutral page;
a valid notice sent in chunks whose body is over 16 KB while its fields are under it is refused as `cpay_too_large`,
as `text/plain` and as a form.

### Task 5: the staff page

**Files:** create `src/cpay/lib/staff.php`, `src/cpay/lib/staff-pages.php`, `src/cpay/lib/staff-app.php`,
`src/naplata/index.php`, `src/naplata/start.php`, `src/naplata/.htaccess`, `src/naplata/.user.ini`,
`test/php/staff.test.php`, `test/naplata.test.mjs` (ports 8771 to 8773), `test/naplata-htaccess.test.mjs`.

```php
// staff.php
/** REMOTE_USER, else REDIRECT_REMOTE_USER; PHP_AUTH_USER only when $sapi is 'cli-server'. '' when none or when it is not 1 to 64 of [A-Za-z0-9._@-]. */
function staff_user(array $server, string $sapi): string
/** '' | 'staff_no_user' | 'staff_not_https' | 'staff_wrong_host'. The HTTPS and host checks are skipped under 'cli-server'; the host check also when $host is ''. */
function staff_problem(array $server, string $sapi, string $host): string
function staff_same_origin(array $server): bool   // same_origin() plus the scheme: https for an https request
/** 64 hex characters from <private>/cpay-staff-secret, created (0600) on first use. Throws RuntimeException('staff_secret'). */
function staff_secret(string $privateDir): string
function staff_token(#[\SensitiveParameter] string $secret, string $user, string $ref, int $hour): string   // HMAC-SHA256 of "user|ref|hour"
function staff_token_ok(#[\SensitiveParameter] string $secret, string $user, string $ref, string $token, int $now): bool  // this hour or the one before

// staff-app.php
function staff_index(): void    // GET /naplata/
function staff_start(): void    // POST /naplata/start.php

// staff-pages.php: the HTML (form with list, confirm page, redirect page, message page); wording in design section 8
```

`staff_index()`: GET or HEAD only (405 with `Allow`); config (500 + "not set up" page); `staff_problem()`
(wrong host on GET: 302 to `https://<host>/naplata/`; otherwise 403 with the code on the page); prune (errors
ignored); when `cpay_problems()` is not empty the page shows "not set up" instead of the form, and still the
list; otherwise a fresh reference (not yet used), its token, the form, the list (latest 50), today's paid count
and sum (Europe/Skopje day), the signed-in user.

`staff_start()`, in this order: POST only (405); config; `staff_problem()` (403); `staff_same_origin()` (403:
`same_origin()`, and the Origin or Referer has the request's own scheme);
`is_form_post()` (400); `cpay_problems()` (page "not set up", 503); reference shape and token (409, form again
with the typed values, a fresh reference and the "stale" message); amount and reservation (422, form again
with the typed values and the messages); a record with this reference exists (409, "already started", nothing
for cPay); same-reservation records for which `blocks_repeat()` is true and no `confirm=yes`, or a `confirm=yes`
whose `seen` leaves one of them out (200, confirm page that re-posts the same values with `seen`, the sorted codes
of the records it shows, and `confirm=yes`); hourly cap of 80 per user (`rate_limited()` on
`<private>/cpay-staff-ratelimit.json`, key a salted hash of the user; 429); `create_payment()` (false: 409
"already started"); 200 with the redirect page: a form `method="post"` to the payment address with the ten
hidden fields and nothing else, submitted by script, with a nameless button inside `<noscript>`.

`src/naplata/.htaccess`: design section 5, exactly:

```apache
Options -Indexes
DirectoryIndex index.php
ErrorDocument 401 default
AuthType Basic
AuthName "Hotel Oaza"
AuthUserFile "/home/oazahote/.htpasswds/public_html/naplata/passwd"
Require valid-user
<If "%{HTTPS} != 'on'">
  Require all denied
</If>
<FilesMatch "^(?!(?:index|start)\.php$|naplata$).+">
  Require all denied
</FilesMatch>
```

**Tests (HTTP; the user comes from an `Authorization: Basic` header, which PHP's test server exposes):** without
a user 403 and the code `staff_no_user`; with one: the form, the notes, the user name, `Cache-Control:
no-store`, `X-Robots-Tag: noindex`, the frame header; the list shows a waiting, a paid, a failed, a no-answer
and a two-charges record with their texts, amounts as `21.802`, and today's sum; POST to `index.php` 405; GET to
`start.php` 405; POST without `Origin` 403; wrong content type 400; a good POST creates one waiting record (user,
amount, reservation, details1 `booking <number>`) and answers a form whose action is the configured payment
address and whose hidden fields are exactly the ten, with a checksum the JavaScript helper confirms; repeating
that POST answers 409, creates nothing and contains no cPay form; a changed token or a token for another user
409 with the typed values kept; amounts `21.802`, `0`, `300001`, empty and a reservation with a letter 422
with the message and no record; a second charge for a paid reservation shows the confirm page and creates
nothing, and with `confirm=yes` goes on; without the cPay key the page says "not set up" and `start.php`
answers 503; a config that does not parse answers 500 and logs no fragment of it; a value such as
`"><script>` typed as the reservation comes back escaped. Static: the `.htaccess` contains each line above, no
`Require all granted`, and its `FilesMatch` pattern (as a JavaScript RegExp) matches `.user.ini`, `x.php`,
`index.php.bak`, `start.phps`, `naplata.php` and does not match `index.php`, `start.php`, `naplata` or the empty string.

### Task 6: build wiring

**Files:** modify `eleventy.config.js` (live passthrough: `src/naplata`, its `.htaccess` and `.user.ini`),
`scripts/lib/site-checks.mjs` (`LIVE_SERVER_FILES` gains `naplata/.htaccess`, `naplata/.user.ini`,
`naplata/index.php`, `naplata/start.php`), `test/site-checks.test.mjs`, `test/redirects.test.mjs` (the old-site
redirects leave `/naplata/`, `/naplata/index.php` and `/naplata/start.php` alone).

### Task 7: owner checklist and privacy text

**Files:** modify `docs/owner-checklist.md`, the privacy page text in Macedonian and English, `legal.updated`.

Checklist: the steps of design section 9 in plain words, each one thing to do or to look at; correct the line
that says the old page sent a GET; tick nothing that has not happened. Privacy: one short paragraph on what the
site keeps about card charges (payment records without card data for 18 months, cPay's raw notices for 90
days, where: on the hotel's hosting account, not public). No merchant values anywhere.

---

## Phase 3

Review of the whole change from several angles (protocol, security, hosting, tests, wording), fixes, then
`"$PHP_BIN" test/php/run.php`, `npm test`, `npm run build`, `npm run check:live`, and a look at the page in a
browser. Upload and push are asked for separately.
