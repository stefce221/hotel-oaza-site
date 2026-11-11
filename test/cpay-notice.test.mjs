import { after, before, test } from "node:test";
import assert from "node:assert/strict";
import { existsSync, mkdirSync, readdirSync, readFileSync, rmdirSync, rmSync, statSync, writeFileSync } from "node:fs";
import net from "node:net";
import { cpayChecksum, formBody, returnNotice, TEST_KEY } from "./helpers/cpay.mjs";
import { makePrivateDir, request, startPhp, TEST_CPAY } from "./helpers/php.mjs";

/* /cpay/ok.php and /cpay/fail.php verify what cPay reports, mark the payment's record, keep a raw copy and answer.
   PHP's test server sees every request as 127.0.0.1. Where the config lists that address under cpay.push_ips a
   request counts as cPay's own push; elsewhere it is a browser (with Sec-Fetch-Site) or an unlisted sender. */
const PUSH_PORT = 8774;
const STRANGER_PORT = 8775;
const SPARE_PORT = 8776; /* started inside single tests, each time with a config of its own */
const FORM = { "Content-Type": "application/x-www-form-urlencoded" };
const BROWSER = { ...FORM, "Sec-Fetch-Site": "cross-site" };
const COPY_NAME = /^\d{8}-\d{6}-(ok|fail)-[0-9a-f]{8}\.json$/;
const UNCONFIRMED = /Статусот на плаќањето не можеше да се потврди\./;
const NEUTRAL = /Го примивме статусот на плаќањето\.[^]*Payment status received\./;
const SECRET = "SECRETFRAGMENT";
const push = makePrivateDir({ cpayPushIps: ["127.0.0.1"], cpay: TEST_CPAY });
const stranger = makePrivateDir({ cpay: TEST_CPAY });
const phpLog = (folder) => `${folder}/php-errors.log`;
const stops = [];
/* Everything the servers answered and logged, for the last test: nothing of the key may be in it. */
const answers = [];
const spareLogs = [];

before(async () => {
  stops.push(
    await startPhp(PUSH_PORT, push.config, { errorLog: phpLog(push.dir) }),
    await startPhp(STRANGER_PORT, stranger.config, { errorLog: phpLog(stranger.dir) }),
  );
});
after(async () => {
  await Promise.all(stops.map((stop) => stop()));
  rmSync(push.dir, { recursive: true, force: true });
  rmSync(stranger.dir, { recursive: true, force: true });
});

const text = (file) => (existsSync(file) ? readFileSync(file, "utf8") : "");
const log = (folder) => text(`${folder}/logs/errors.log`);
/** How many lines of the folder's error log end with this code. */
const logged = (folder, code) => log(folder).split("\n").filter((line) => line.endsWith(` ${code}`)).length;
const recordPath = (folder, record) => `${folder}/cpay-payments/${record.ref}-${record.reservation}.json`;
const stored = (folder, record) => JSON.parse(readFileSync(recordPath(folder, record), "utf8"));

let issued = 0;
/** A reference of the shape the staff page issues: six base-36 characters of the time, then four more. */
function newRef() {
  issued += 1;
  const time = Math.floor(Date.now() / 1000).toString(36).toUpperCase().padStart(6, "0");
  return `${time}${issued.toString(36).toUpperCase().padStart(4, "0")}`;
}

/** A waiting record in the shape of the plan's "The payment record", not yet written anywhere. */
function newRecord(change = {}) {
  const now = new Date().toISOString().replace(/\.\d{3}Z$/, "Z");
  const base = { ref: newRef(), created: now, updated: now, user: "recepcija", amount: 21802, reservation: "4567890123", ...change };
  return {
    ...base,
    details1: `booking ${base.reservation}`,
    payment_url: "https://www.cpay.com.mk/client/Page/default.aspx?xml_id=/mk-MK/.loginToPay/.simple/",
    hash: "md5",
    status: "waiting",
    cpay_ref: "",
    extra_refs: [],
    notices: [],
  };
}

/** Writes a waiting record into the private folder, as the staff page does before it sends staff to cPay. */
function writeRecord(folder, change = {}) {
  const record = newRecord(change);
  mkdirSync(`${folder}/cpay-payments`, { recursive: true });
  writeFileSync(recordPath(folder, record), JSON.stringify(record, null, 2));
  return record;
}

/** The eight parameters this site sends to cPay for a record, as pairs in the order it signs them (`change` replaces values). */
function requestPairs(record, change = {}) {
  return Object.entries({
    AmountToPay: `${record.amount}00`,
    PayToMerchant: TEST_CPAY.merchant_id,
    MerchantName: TEST_CPAY.merchant_name,
    AmountCurrency: "MKD",
    Details1: record.details1,
    Details2: record.ref,
    PaymentOKURL: TEST_CPAY.ok_url,
    PaymentFailURL: TEST_CPAY.fail_url,
    ...change,
  });
}

/** What cPay sends back for a record: its eight request parameters (`change` replaces values), signed with the test key. */
function noticeFor(record, { cPayPaymentRef = "123456", change = {} } = {}) {
  return returnNotice(requestPairs(record, change), TEST_KEY, { cPayPaymentRef });
}

/** A notice signed over every parameter but AmountToPay, with the amount posted beside it, unsigned. */
function noticeWithUnsignedAmount(record) {
  const signed = requestPairs(record).filter(([name]) => name !== "AmountToPay");
  return { ...returnNotice(signed, TEST_KEY, { cPayPaymentRef: "123456" }), AmountToPay: `${record.amount}00` };
}

/** Sends the fields to /cpay/<kind>.php: as a form, or with `method: "GET"` in the query string. */
async function send(port, kind, fields, { headers = FORM, method = "POST" } = {}) {
  const res =
    method === "GET"
      ? await request(port, "GET", `/cpay/${kind}.php?${formBody(fields)}`, { headers })
      : await request(port, "POST", `/cpay/${kind}.php`, { body: formBody(fields), headers });
  answers.push(res.body);
  return res;
}

/** Posts a form body to /cpay/<kind>.php in two chunks over a bare socket: without a Content-Length, which node's
    own client always adds. Resolves with the status and the body text. */
function sendChunked(port, kind, body, headers = FORM) {
  const bytes = Buffer.from(body, "utf8");
  const half = Math.ceil(bytes.length / 2);
  const chunk = (part) => Buffer.concat([Buffer.from(`${part.length.toString(16)}\r\n`), part, Buffer.from("\r\n")]);
  const head = [
    `POST /cpay/${kind}.php HTTP/1.1`,
    `Host: 127.0.0.1:${port}`,
    ...Object.entries(headers).map(([name, value]) => `${name}: ${value}`),
    "Transfer-Encoding: chunked",
    "Connection: close",
  ];
  assert.doesNotMatch(head.join("\n"), /content-length/i);
  return new Promise((resolve, reject) => {
    const received = [];
    const socket = net.connect(port, "127.0.0.1", () => {
      socket.write(`${head.join("\r\n")}\r\n\r\n`);
      socket.write(chunk(bytes.subarray(0, half)));
      socket.write(chunk(bytes.subarray(half)));
      socket.write("0\r\n\r\n"); /* the server closes the connection after its answer */
    });
    socket.setTimeout(10000, () => socket.destroy(new Error("no answer to the body sent in chunks")));
    socket.on("data", (data) => received.push(data));
    socket.on("end", () => socket.end());
    socket.on("error", reject);
    socket.on("close", () => {
      const answer = Buffer.concat(received).toString("utf8");
      const res = { status: Number(/^HTTP\/1\.[01] (\d{3}) /.exec(answer)?.[1]), body: answer.slice(answer.indexOf("\r\n\r\n") + 4) };
      answers.push(res.body);
      resolve(res);
    });
  });
}

/** The headers every answer of these addresses carries. */
function assertHeaders(res, type) {
  assert.equal(res.headers["content-type"], `${type}; charset=utf-8`);
  assert.equal(res.headers["cache-control"], "no-store");
  assert.equal(res.headers["x-robots-tag"], "noindex");
  assert.equal(res.headers["content-security-policy"], "frame-ancestors 'none'");
  assert.equal(res.headers["x-content-type-options"], "nosniff");
}

const inboxNames = (folder) => {
  const inbox = `${folder}/cpay-inbox`;
  return existsSync(inbox) && statSync(inbox).isDirectory() ? readdirSync(inbox) : [];
};
/** The raw copies in a private folder's inbox, each with its file name. */
const copies = (folder) =>
  inboxNames(folder)
    .filter((name) => COPY_NAME.test(name))
    .map((name) => ({ name, ...JSON.parse(readFileSync(`${folder}/cpay-inbox/${name}`, "utf8")) }));
const copiesOf = (folder, record) => copies(folder).filter((copy) => copy.fields.Details2 === record.ref);
const marks = (copy) => ({ kind: copy.kind, via: copy.via, verified: copy.verified });

/** Adds empty files to an inbox until it holds `total`. */
function fillInbox(folder, total) {
  const inbox = `${folder}/cpay-inbox`;
  mkdirSync(inbox, { recursive: true });
  for (let i = readdirSync(inbox).length; i < total; i += 1) {
    writeFileSync(`${inbox}/seed-${String(i).padStart(5, "0")}.json`, "{}");
  }
  assert.equal(readdirSync(inbox).length, total);
}

/** Runs `body` while a server with that config answers on the spare port; its logs are kept for the last test. */
async function withSpareServer({ dir, config }, body) {
  const stop = await startPhp(SPARE_PORT, config, { errorLog: phpLog(dir) });
  try {
    await body();
  } finally {
    await stop();
    spareLogs.push(log(dir), text(phpLog(dir)));
    rmSync(dir, { recursive: true, force: true });
  }
}

/* Notices that must mark nothing: the code each is logged with, and whether its checksum, merchant and reference hold. */
const REFUSED = [
  { name: "a wrong checksum", code: "cpay_bad_checksum", verified: false, fields: (record) => ({ ...noticeFor(record), ReturnCheckSum: "0".repeat(32) }) },
  { name: "a value changed after signing", code: "cpay_bad_checksum", verified: false, fields: (record) => ({ ...noticeFor(record), cPayPaymentRef: "654321" }) },
  { name: "another merchant", code: "cpay_wrong_merchant", verified: false, fields: (record) => noticeFor(record, { change: { PayToMerchant: "1234567890" } }) },
  { name: "a reference of another shape", code: "cpay_bad_reference", verified: false, fields: (record) => noticeFor(record, { change: { Details2: `../${record.ref}` } }) },
  { name: "an unknown reference", code: "cpay_unknown_payment", verified: true, fields: (record) => noticeFor(record, { change: { Details2: newRef() } }) },
  { name: "another amount", code: "cpay_amount_mismatch", verified: true, fields: (record) => noticeFor(record, { change: { AmountToPay: "100" } }) },
  /* The amount is compared as text: the same number written another way is another amount. */
  ...["02180200", " 2180200", "2180200.0", "2.1802e6"].map((amount) => ({
    name: `the amount written as "${amount}"`,
    code: "cpay_amount_mismatch",
    verified: true,
    fields: (record) => noticeFor(record, { change: { AmountToPay: amount } }),
  })),
  { name: "an amount that the header does not name", code: "cpay_amount_mismatch", verified: false, fields: noticeWithUnsignedAmount },
  { name: "an OK notice without a cPay reference", code: "cpay_ok_without_ref", verified: true, fields: (record) => noticeFor(record, { cPayPaymentRef: null }) },
];

/** Sends each refused notice to ok.php and checks that nothing changed but the log and the inbox; returns the answers. */
async function sendRefused(port, folder, headers, via) {
  const results = [];
  for (const refused of REFUSED) {
    const record = writeRecord(folder);
    const known = { logged: logged(folder, refused.code), copies: copies(folder).map((copy) => copy.name), records: readdirSync(`${folder}/cpay-payments`) };
    const res = await send(port, "ok", refused.fields(record), { headers });
    assert.equal(res.status, 200, refused.name);
    assert.deepEqual(stored(folder, record), record, `${refused.name}: the record is as it was`);
    assert.deepEqual(readdirSync(`${folder}/cpay-payments`), known.records, `${refused.name}: no file was added to the records`);
    assert.equal(logged(folder, refused.code), known.logged + 1, `${refused.name} is logged as ${refused.code}`);
    const added = copies(folder).filter((copy) => !known.copies.includes(copy.name));
    assert.deepEqual(added.map(marks), [{ kind: "ok", via, verified: refused.verified }], `${refused.name}: the raw copy`);
    results.push({ refused, res });
  }
  return results;
}

test("a verified push to ok.php marks the record paid with the cPay reference and is answered OK", async () => {
  const record = writeRecord(push.dir);
  const res = await send(PUSH_PORT, "ok", noticeFor(record));
  assert.equal(res.status, 200);
  assert.equal(res.body, "OK");
  assertHeaders(res, "text/plain");
  const now = stored(push.dir, record);
  assert.equal(now.notices.length, 1);
  assert.match(now.notices[0].at, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/);
  const expected = { ...record, status: "paid", cpay_ref: "123456", updated: now.notices[0].at, notices: [{ at: now.notices[0].at, kind: "ok", via: "push" }] };
  assert.deepEqual(now, expected, "only status, cPay reference, the notice and its time changed");
  const [copy, ...more] = copiesOf(push.dir, record);
  assert.deepEqual(more, []);
  assert.deepEqual(marks(copy), { kind: "ok", via: "push", verified: true });
  assert.equal(copy.fields.cPayPaymentRef, "123456");
  assert.match(copy.received, /^\d{4}-\d{2}-\d{2}T/);
});

test("a verified push to fail.php marks the record failed and is answered OK", async () => {
  const record = writeRecord(push.dir);
  const res = await send(PUSH_PORT, "fail", noticeFor(record, { cPayPaymentRef: null }));
  assert.equal(res.status, 200);
  assert.equal(res.body, "OK");
  const now = stored(push.dir, record);
  assert.equal(now.status, "failed");
  assert.equal(now.cpay_ref, "");
  assert.deepEqual(now.notices.map(({ kind, via }) => [kind, via]), [["fail", "push"]]);
  assert.deepEqual(copiesOf(push.dir, record).map(marks), [{ kind: "fail", via: "push", verified: true }]);
});

test("a verified browser return shows the amount, reservation, cPay reference and code of the record", async () => {
  const record = writeRecord(stranger.dir);
  const res = await send(STRANGER_PORT, "ok", { ...noticeFor(record), LastName: "POSTEDVALUE" }, { headers: BROWSER });
  assert.equal(res.status, 200);
  assertHeaders(res, "text/html");
  assert.match(res.body, /Наплатено: 21\.802 ден\./);
  assert.ok(res.body.includes(`Резервација 4567890123 · cPay реф. 123456 · шифра ${record.ref}`), res.body);
  assert.match(res.body, /<a [^>]*href="\/naplata\/"[^>]*>Следна наплата<\/a>/);
  assert.doesNotMatch(res.body, /POSTEDVALUE/, "the page is made from the record, not from what was posted");
  const now = stored(stranger.dir, record);
  assert.equal(now.status, "paid");
  assert.deepEqual(now.notices.map(({ kind, via }) => [kind, via]), [["ok", "browser"]]);
  assert.deepEqual(copiesOf(stranger.dir, record).map(marks), [{ kind: "ok", via: "browser", verified: true }]);
});

test("the same return by GET, which the specification allows", async () => {
  const record = writeRecord(stranger.dir, { amount: 5, reservation: "123456" });
  const res = await send(STRANGER_PORT, "ok", noticeFor(record, { cPayPaymentRef: "77" }), { method: "GET", headers: { "Sec-Fetch-Site": "cross-site" } });
  assert.equal(res.status, 200);
  assertHeaders(res, "text/html");
  assert.match(res.body, /Наплатено: 5 ден\./);
  assert.ok(res.body.includes(`Резервација 123456 · cPay реф. 77 · шифра ${record.ref}`), res.body);
  assert.equal(stored(stranger.dir, record).status, "paid");
  assert.equal(stored(stranger.dir, record).cpay_ref, "77");
});

test("a verified browser return to fail.php shows that nothing was charged, the two hints and the way back", async () => {
  const record = writeRecord(stranger.dir);
  const res = await send(STRANGER_PORT, "fail", noticeFor(record, { cPayPaymentRef: null }), { headers: BROWSER });
  assert.equal(res.status, 200);
  assertHeaders(res, "text/html");
  assert.match(res.body, /Не е наплатено\./);
  assert.match(res.body, /Најчесто: погрешни податоци од картичката или износ поголем од состојбата на картичката\./);
  assert.match(res.body, /плаќањето можеби сепак поминало\. Погледнете ја листата по неколку минути пред да пробате пак\./);
  assert.match(res.body, /<a [^>]*href="\/naplata\/"[^>]*>Назад кон наплата<\/a>/);
  assert.doesNotMatch(res.body, /Наплатено:/);
  assert.equal(stored(stranger.dir, record).status, "failed");
});

test("a fail notice keeps its cPay reference, and the same fields at ok.php then mark the record paid with it", async () => {
  const record = writeRecord(push.dir);
  const notice = noticeFor(record, { cPayPaymentRef: "4711" });
  const declined = await send(PUSH_PORT, "fail", notice);
  assert.equal(declined.body, "OK");
  const failed = stored(push.dir, record);
  assert.equal(failed.status, "failed");
  assert.equal(failed.cpay_ref, "4711", "cPay had the card details: staff confirm before they charge this reservation again");
  const accepted = await send(PUSH_PORT, "ok", notice);
  assert.equal(accepted.body, "OK");
  const paid = stored(push.dir, record);
  assert.deepEqual([paid.status, paid.cpay_ref, paid.extra_refs], ["paid", "4711", []], "the outcome is not signed: the address decides");
  assert.deepEqual(paid.notices.map(({ kind }) => kind), ["fail", "ok"]);
});

test("fail then ok ends paid: cPay's documented case of a debited card behind a fail return", async () => {
  const record = writeRecord(push.dir);
  await send(PUSH_PORT, "fail", noticeFor(record, { cPayPaymentRef: null }));
  assert.equal(stored(push.dir, record).status, "failed");
  await send(PUSH_PORT, "ok", noticeFor(record, { cPayPaymentRef: "4242" }));
  const now = stored(push.dir, record);
  assert.equal(now.status, "paid");
  assert.equal(now.cpay_ref, "4242");
  assert.deepEqual(now.notices.map(({ kind }) => kind), ["fail", "ok"]);
});

test("ok then fail stays paid, and the browser at the fail address is shown the paid record", async () => {
  const record = writeRecord(stranger.dir);
  await send(STRANGER_PORT, "ok", noticeFor(record), { headers: BROWSER });
  const res = await send(STRANGER_PORT, "fail", noticeFor(record), { headers: BROWSER });
  assert.equal(res.status, 200);
  assert.match(res.body, /Наплатено: 21\.802 ден\./);
  assert.doesNotMatch(res.body, /Не е наплатено/);
  const now = stored(stranger.dir, record);
  assert.equal(now.status, "paid");
  assert.deepEqual(now.notices.map(({ kind }) => kind), ["ok", "fail"]);
});

test("a second ok with another cPay reference flags two charges, once", async () => {
  const record = writeRecord(stranger.dir);
  await send(STRANGER_PORT, "ok", noticeFor(record, { cPayPaymentRef: "111" }), { headers: BROWSER });
  const second = await send(STRANGER_PORT, "ok", noticeFor(record, { cPayPaymentRef: "222" }), { headers: BROWSER });
  assert.match(second.body, /Наплатено: 21\.802 ден\./);
  assert.match(second.body, /Две наплати: проверете во cPay порталот\./);
  await send(STRANGER_PORT, "ok", noticeFor(record, { cPayPaymentRef: "222" }), { headers: BROWSER });
  const now = stored(stranger.dir, record);
  assert.equal(now.status, "paid");
  assert.equal(now.cpay_ref, "111");
  assert.deepEqual(now.extra_refs, ["222"]);
  assert.equal(now.notices.length, 3);
});

test("a wrong checksum, merchant, reference or amount and an ok without a cPay reference change nothing, answer cPay 200 OK and log their code", async () => {
  for (const { refused, res } of await sendRefused(PUSH_PORT, push.dir, FORM, "push")) {
    assert.equal(res.body, "OK", refused.name);
    assertHeaders(res, "text/plain");
  }
});

test("the same notices from a browser are answered with the page that says the status could not be confirmed", async () => {
  for (const { refused, res } of await sendRefused(STRANGER_PORT, stranger.dir, BROWSER, "browser")) {
    assertHeaders(res, "text/html");
    assert.match(res.body, UNCONFIRMED, refused.name);
    assert.match(res.body, /Ако сте од хотелот: не наплаќајте повторно, прво проверете во cPay порталот\./);
    assert.match(res.body, /The payment status could not be confirmed\. Please contact the hotel\./);
    assert.match(res.body, /href="tel:\+38970310300"[^]*href="mailto:info@oazahotel\.com\.mk"/);
    assert.doesNotMatch(res.body, /Наплатено|Не е наплатено|Payment status received|naplata/, refused.name);
  }
});

test("a verified notice from an address that is neither cPay's nor a browser is applied and logged with the address", async () => {
  const record = writeRecord(stranger.dir);
  const res = await send(STRANGER_PORT, "ok", noticeFor(record));
  assert.equal(res.status, 200);
  assertHeaders(res, "text/html");
  assert.match(res.body, /Наплатено: 21\.802 ден\./);
  assert.deepEqual(stored(stranger.dir, record).notices.map(({ via }) => via), ["unlisted"]);
  assert.match(log(stranger.dir), / cpay_push_from_unlisted 127\.0\.0\.1\n/);
  assert.deepEqual(copiesOf(stranger.dir, record).map(marks), [{ kind: "ok", via: "unlisted", verified: true }]);
});

test("fields that are no notice, and a visit without fields, get the neutral page as before", async () => {
  const record = writeRecord(push.dir);
  const posted = await send(PUSH_PORT, "ok", { Details2: record.ref, AmountToPay: "2180200", cPayPaymentRef: "123456" });
  const visit = await request(STRANGER_PORT, "GET", "/cpay/fail.php");
  for (const res of [posted, visit]) {
    assert.equal(res.status, 200);
    assertHeaders(res, "text/html");
    assert.match(res.body, NEUTRAL);
  }
  assert.deepEqual(stored(push.dir, record), record, "fields without a checksum mark nothing");
  assert.deepEqual(copiesOf(push.dir, record).map(marks), [{ kind: "ok", via: "push", verified: false }]);
});

test("a notice over 16 KB is logged, kept nowhere and marks nothing", async () => {
  const record = writeRecord(push.dir);
  const before = logged(push.dir, "cpay_too_large");
  const res = await send(PUSH_PORT, "ok", { ...noticeFor(record), Details3: "x".repeat(17000) });
  assert.equal(res.status, 200);
  assert.match(res.body, NEUTRAL);
  assert.equal(logged(push.dir, "cpay_too_large"), before + 1);
  assert.deepEqual(stored(push.dir, record), record);
  assert.deepEqual(copiesOf(push.dir, record), []);
});

test("a body sent in chunks, without a Content-Length, is measured by what PHP read of it", async () => {
  const small = writeRecord(stranger.dir);
  const taken = await sendChunked(STRANGER_PORT, "ok", formBody(noticeFor(small)), BROWSER);
  assert.equal(taken.status, 200);
  assert.match(taken.body, /Наплатено: 21\.802 ден\./, "a body in chunks is read like any other");
  assert.equal(stored(stranger.dir, small).status, "paid");

  const record = writeRecord(stranger.dir);
  const known = { logged: logged(stranger.dir, "cpay_too_large"), names: inboxNames(stranger.dir) };
  const padded = await sendChunked(STRANGER_PORT, "ok", formBody({ ...noticeFor(record), Padding: "x".repeat(40000) }), BROWSER);
  const named = await sendChunked(STRANGER_PORT, "ok", `${"n".repeat(100000)}=1&keep=yes`);
  for (const res of [padded, named]) {
    assert.equal(res.status, 200);
    assert.match(res.body, NEUTRAL);
  }
  assert.equal(logged(stranger.dir, "cpay_too_large"), known.logged + 2);
  assert.deepEqual(inboxNames(stranger.dir), known.names, "nothing is kept: neither a valid notice of 40 KB nor a field name of 100 KB");
  assert.deepEqual(stored(stranger.dir, record), record, "and nothing is marked");
});

test("a valid notice in chunks whose body is over 16 KB and whose fields are under it is refused, as text/plain and as a form", async () => {
  for (const headers of [{ "Content-Type": "text/plain" }, FORM]) {
    const type = headers["Content-Type"];
    const record = writeRecord(push.dir);
    const fields = { ...noticeFor(record), Padding: "/".repeat(6000) }; /* each "/" is sent as %2F */
    const body = formBody(fields);
    const decoded = Object.entries(fields).reduce((sum, [name, value]) => sum + name.length + Buffer.byteLength(value), 0);
    assert.ok(body.length > 16384 && decoded < 16384, `${type}: ${body.length} bytes sent, ${decoded} in the fields`);
    const known = { logged: logged(push.dir, "cpay_too_large"), names: inboxNames(push.dir) };
    const res = await sendChunked(PUSH_PORT, "ok", body, headers);
    assert.equal(res.status, 200, type);
    assert.match(res.body, NEUTRAL, type);
    assert.equal(logged(push.dir, "cpay_too_large"), known.logged + 1, `${type}: measured by the body as read`);
    assert.deepEqual(stored(push.dir, record), record, `${type}: nothing is marked`);
    assert.deepEqual(inboxNames(push.dir), known.names, `${type}: nothing is kept`);
  }
});

test("a verified push without a Content-Type, or sent as text/plain, marks the record paid and is answered OK", async () => {
  for (const headers of [{}, { "Content-Type": "text/plain" }]) {
    const record = writeRecord(push.dir);
    const res = await send(PUSH_PORT, "ok", noticeFor(record), { headers });
    assert.equal(res.status, 200, JSON.stringify(headers));
    assert.equal(res.body, "OK", "PHP did not read this body into its form fields: the handler read it");
    const now = stored(push.dir, record);
    assert.deepEqual([now.status, now.cpay_ref], ["paid", "123456"], JSON.stringify(headers));
    assert.deepEqual(copiesOf(push.dir, record).map(marks), [{ kind: "ok", via: "push", verified: true }]);
  }
});

/** The fields as a multipart/form-data body with that boundary. */
function multipartBody(fields, boundary) {
  const parts = Object.entries(fields).map(([name, value]) => `--${boundary}\r\nContent-Disposition: form-data; name="${name}"\r\n\r\n${value}\r\n`);
  return `${parts.join("")}--${boundary}--\r\n`;
}

test("a verified push sent as multipart is marked whether PHP parsed it (with a boundary) or left it to be read (without one)", async () => {
  /* Without a boundary PHP warns "Missing boundary" at request startup (its own log, and the page under php -S), so
     this runs on the spare server, whose logs are not expected to be empty. */
  const spare = makePrivateDir({ cpayPushIps: ["127.0.0.1"], cpay: TEST_CPAY });
  await withSpareServer(spare, async () => {
    const boundary = "oazaBoundary7";
    const cases = [
      [`multipart/form-data; boundary=${boundary}`, (fields) => multipartBody(fields, boundary)],
      ["multipart/form-data", formBody],
      ["multipart/form-data; charset=utf-8", formBody],
      ["multipart/form-data; boundary", formBody],
    ];
    for (const [type, encode] of cases) {
      const record = writeRecord(spare.dir);
      const res = await request(SPARE_PORT, "POST", "/cpay/ok.php", { body: encode(noticeFor(record)), headers: { "Content-Type": type } });
      answers.push(res.body);
      assert.equal(res.status, 200, type);
      /* php -S ignores src/cpay/.user.ini (display_errors = Off), so PHP's startup warning may come before the answer. */
      assert.match(res.body, /(^|\n)OK$/, type);
      const now = stored(spare.dir, record);
      assert.deepEqual([now.status, now.cpay_ref], ["paid", "123456"], type);
    }
    assert.equal(log(spare.dir), "", "nothing went wrong, so nothing is logged");
  });
});

test("a body that holds no field is logged as cpay_unparsed_body, kept as sent and answered with the neutral page; an empty POST logs nothing", async () => {
  const body = "&&=only-a-value&list[]=1";
  const before = { unparsed: logged(push.dir, "cpay_unparsed_body"), log: log(push.dir), phpLog: text(phpLog(push.dir)) };
  const empty = await request(PUSH_PORT, "POST", "/cpay/fail.php", { headers: { "Content-Type": "text/plain" } });
  assert.equal(empty.status, 200);
  assert.match(empty.body, NEUTRAL);
  assert.equal(log(push.dir), before.log, "an empty POST is someone opening the address");
  const res = await request(PUSH_PORT, "POST", "/cpay/fail.php", { body, headers: { "Content-Type": "text/plain" } });
  answers.push(res.body);
  assert.equal(res.status, 200);
  assertHeaders(res, "text/html");
  assert.match(res.body, NEUTRAL);
  assert.equal(logged(push.dir, "cpay_unparsed_body"), before.unparsed + 1);
  assert.doesNotMatch(log(push.dir), /only-a-value/, "the log holds the code, nothing of the body");
  assert.doesNotMatch(text(phpLog(push.dir)), /only-a-value|list\[\]/, "nor does PHP's own log");
  assert.equal(text(phpLog(push.dir)), before.phpLog, "PHP itself logged nothing for it");
  const kept = copies(push.dir).filter((copy) => copy.body === body);
  assert.deepEqual(kept.map(marks), [{ kind: "fail", via: "push", verified: false }], "a raw copy is the trace of what came");
  assert.deepEqual(kept[0].fields, []);
});

test("a form that PHP read into lists only is logged as cpay_unparsed_body, kept once and answered with the neutral page", async () => {
  const body = "a[]=1";
  const before = { unparsed: logged(push.dir, "cpay_unparsed_body"), names: copies(push.dir).map((copy) => copy.name) };
  const res = await request(PUSH_PORT, "POST", "/cpay/ok.php", { body, headers: FORM });
  answers.push(res.body);
  assert.equal(res.status, 200);
  assertHeaders(res, "text/html");
  assert.match(res.body, NEUTRAL);
  assert.equal(logged(push.dir, "cpay_unparsed_body"), before.unparsed + 1);
  const added = copies(push.dir).filter((copy) => !before.names.includes(copy.name));
  assert.deepEqual(added.map((copy) => ({ ...marks(copy), fields: copy.fields, body: copy.body })), [{ kind: "ok", via: "push", verified: false, fields: [], body }]);
});

test("the raw copy of a verified notice holds what was verified, whatever was posted in front of it", async () => {
  const record = writeRecord(stranger.dir);
  const notice = noticeFor(record);
  const padding = Array.from({ length: 60 }, (_, i) => [`pad${i}`, "x"]);
  const known = copies(stranger.dir).map((copy) => copy.name);
  const res = await send(STRANGER_PORT, "ok", [...padding, ...Object.entries(notice)], { headers: BROWSER });
  assert.match(res.body, /Наплатено: 21\.802 ден\./);
  assert.equal(stored(stranger.dir, record).status, "paid");
  const [copy, ...more] = copies(stranger.dir).filter(({ name }) => !known.includes(name));
  assert.deepEqual(more, []);
  assert.deepEqual(marks(copy), { kind: "ok", via: "browser", verified: true });
  assert.equal(Object.keys(copy.fields).length, 50, "still at most 50 fields");
  for (const name of ["ReturnCheckSumHeader", "ReturnCheckSum", "PayToMerchant", "AmountToPay", "Details2", "cPayPaymentRef"]) {
    assert.equal(copy.fields[name], notice[name], `${name} is in the copy`);
  }
  const header = copy.fields.ReturnCheckSumHeader;
  const signed = header.slice(2).split(",").slice(0, Number(header.slice(0, 2))).map((name) => [name, copy.fields[name]]);
  assert.deepEqual(cpayChecksum(signed, TEST_KEY), { header, checksum: copy.fields.ReturnCheckSum }, "the copy alone proves the notice");
  assert.equal(copy.fields.pad0, "x", "what else was posted follows while there is room");
});

test("a record that cannot be written answers 500, to cPay too, and the next try marks it", async () => {
  const record = writeRecord(push.dir);
  const blocker = `${recordPath(push.dir, record)}.tmp`;
  mkdirSync(blocker); /* the store writes to this name first; a folder there makes the write fail on every system */
  const res = await send(PUSH_PORT, "ok", noticeFor(record));
  assert.equal(res.status, 500);
  assertHeaders(res, "text/html");
  assert.match(res.body, UNCONFIRMED);
  assert.deepEqual(stored(push.dir, record), record);
  assert.equal(logged(push.dir, "cpay_record_write"), 1);
  assert.deepEqual(copiesOf(push.dir, record).map(marks), [{ kind: "ok", via: "push", verified: true }], "the copy is the only trace");
  rmdirSync(blocker);
  const again = await send(PUSH_PORT, "ok", noticeFor(record));
  assert.equal(again.status, 200);
  assert.equal(again.body, "OK");
  assert.equal(stored(push.dir, record).status, "paid");
});

test("a verified notice is kept past the hourly cap and in a full inbox, and the record is still marked", async () => {
  for (let i = 0; i < 25; i += 1) await send(STRANGER_PORT, "ok", { n: String(i) }, { headers: BROWSER });
  assert.ok(logged(stranger.dir, "cpay_rate_limited") >= 5, "this visitor's hour is used up for what cannot be verified");
  const known = copies(stranger.dir).length;
  for (let i = 0; i < 25; i += 1) {
    const record = writeRecord(stranger.dir);
    const res = await send(STRANGER_PORT, "ok", noticeFor(record), { headers: BROWSER });
    assert.match(res.body, /Наплатено: 21\.802 ден\./);
    assert.equal(stored(stranger.dir, record).status, "paid");
  }
  assert.equal(copies(stranger.dir).length, known + 25, "25 verified notices in a row are all kept");

  fillInbox(stranger.dir, 500);
  const late = writeRecord(stranger.dir);
  await send(STRANGER_PORT, "ok", noticeFor(late), { headers: BROWSER });
  assert.equal(stored(stranger.dir, late).status, "paid");
  assert.equal(inboxNames(stranger.dir).length, 501, "past the ceiling for strangers");

  fillInbox(push.dir, 5000);
  const last = writeRecord(push.dir);
  const refused = await send(PUSH_PORT, "ok", { ...noticeFor(last), ReturnCheckSum: "0".repeat(32) });
  assert.equal(refused.body, "OK");
  assert.equal(inboxNames(push.dir).length, 5000, "what cannot be verified stops at the ceiling");
  assert.match(log(push.dir), / cpay_inbox_full\n/);
  const res = await send(PUSH_PORT, "ok", noticeFor(last));
  assert.equal(res.body, "OK");
  assert.equal(stored(push.dir, last).status, "paid");
  assert.equal(inboxNames(push.dir).length, 5001, "past the ceiling for cPay's own address");
});

test("a captured notice sent in a loop keeps only the copies that joined its record past the limits; one for no payment keeps the hourly cap", async () => {
  const spare = makePrivateDir({ cpay: TEST_CPAY });
  await withSpareServer(spare, async () => {
    const unknown = noticeFor(newRecord()); /* signed with the key, for a payment that was never started */
    for (let i = 0; i < 25; i += 1) assert.match((await send(SPARE_PORT, "fail", unknown, { headers: BROWSER })).body, UNCONFIRMED);
    assert.equal(copies(spare.dir).length, 20, "a verified notice that marks nothing keeps a stranger's hourly cap");
    assert.ok(copies(spare.dir).every((copy) => copy.verified === true), "and its copies still say it was verified");
    assert.deepEqual([logged(spare.dir, "cpay_unknown_payment"), logged(spare.dir, "cpay_rate_limited")], [25, 5]);

    const record = writeRecord(spare.dir);
    const captured = noticeFor(record);
    for (let i = 0; i < 30; i += 1) assert.match((await send(SPARE_PORT, "ok", captured)).body, /Наплатено: 21\.802 ден\./);
    const now = stored(spare.dir, record);
    assert.deepEqual([now.status, now.cpay_ref, now.notices.length], ["paid", "123456", 20], "the record ends right");
    assert.equal(copiesOf(spare.dir, record).length, 20, "the 20 that joined the record are kept although the hour is used up; the rest are not");
    assert.equal(logged(spare.dir, "cpay_rate_limited"), 15);
    assert.equal(logged(spare.dir, "cpay_push_from_unlisted 127.0.0.1"), 20, "the address is logged only for a notice that joined the record");
  });
});

test("a raw copy that cannot be kept is only logged: the record is marked and the answer stands", async () => {
  const spare = makePrivateDir({ cpayPushIps: ["127.0.0.1"], cpay: TEST_CPAY });
  writeFileSync(`${spare.dir}/cpay-inbox`, "a file where the folder should be");
  await withSpareServer(spare, async () => {
    const record = writeRecord(spare.dir);
    const res = await send(SPARE_PORT, "ok", noticeFor(record));
    assert.equal(res.status, 200);
    assert.equal(res.body, "OK");
    assert.equal(stored(spare.dir, record).status, "paid");
    assert.equal(logged(spare.dir, "cpay_inbox"), 1);
    assert.equal(text(phpLog(spare.dir)), "", "PHP itself had nothing to say");
  });
});

test("a notice-shaped request with a config that does not parse answers 500 and logs no fragment of the config", async () => {
  const broken = makePrivateDir();
  writeFileSync(broken.config, `<?php\nreturn ['rate' => ['salt' => 'x' '${SECRET}']];\n`);
  await withSpareServer(broken, async () => {
    const notice = noticeFor(newRecord());
    for (const res of [await send(SPARE_PORT, "ok", notice), await send(SPARE_PORT, "fail", notice, { method: "GET", headers: {} })]) {
      assert.equal(res.status, 500, "cPay tries again later");
      assertHeaders(res, "text/html");
      assert.match(res.body, UNCONFIRMED);
      assert.doesNotMatch(res.body, new RegExp(SECRET));
    }
    const other = await send(SPARE_PORT, "ok", { a: "1" });
    assert.equal(other.status, 200, "what is not a notice is answered as before");
    assert.match(other.body, NEUTRAL);
    const logText = text(phpLog(broken.dir));
    assert.equal(logText.match(/Hotel Oaza cPay notice: ParseError on line 2/g)?.length, 3);
    assert.doesNotMatch(logText, new RegExp(SECRET));
  });
});

test("without a key in the config a notice is kept unverified and answered with the neutral page", async () => {
  const { key, ...withoutKey } = TEST_CPAY;
  assert.equal(key, TEST_KEY);
  const spare = makePrivateDir({ cpayPushIps: ["127.0.0.1"], cpay: withoutKey });
  await withSpareServer(spare, async () => {
    const record = writeRecord(spare.dir);
    const res = await send(SPARE_PORT, "ok", noticeFor(record));
    assert.equal(res.status, 200);
    assertHeaders(res, "text/html");
    assert.match(res.body, NEUTRAL);
    assert.deepEqual(stored(spare.dir, record), record, "nothing can be verified, so nothing is marked");
    assert.deepEqual(copiesOf(spare.dir, record).map(marks), [{ kind: "ok", via: "push", verified: false }]);
    assert.equal(log(spare.dir), "", "nothing is wrong, so nothing is logged");
  });
});

test("nothing of the key is in any answer, log or raw copy, and PHP itself logged nothing", () => {
  assert.ok(answers.length >= 80, "the tests above ran");
  const kept = [push.dir, stranger.dir].flatMap((folder) => [log(folder), text(phpLog(folder)), JSON.stringify(copies(folder))]);
  for (const part of [...answers, ...spareLogs, ...kept]) {
    assert.ok(!part.includes(TEST_KEY), part.slice(0, 300));
  }
  assert.equal(text(phpLog(push.dir)) + text(phpLog(stranger.dir)), "");
  assert.ok(logged(push.dir, "cpay_bad_checksum") > 0 && copies(push.dir).length > 10, "the logs and copies that were searched exist");
});
