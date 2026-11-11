import { after, before, test } from "node:test";
import assert from "node:assert/strict";
import { existsSync, mkdirSync, mkdtempSync, readdirSync, readFileSync, renameSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import vm from "node:vm";
import { cpayChecksum, formBody } from "./helpers/cpay.mjs";
import { TEST_CPAY, makePrivateDir, request, startPhp } from "./helpers/php.mjs";

/* /naplata/ (the staff page) and /naplata/start.php on PHP's built-in server. The signed-in user comes from an
   "Authorization: Basic" header, which that server hands to PHP as PHP_AUTH_USER; on the live site Apache checks
   the password before PHP runs. Nothing here is ever sent to cPay: the form for cPay is only read. */
const PORT = 8771;
const NO_KEY_PORT = 8772;
const BROKEN_PORT = 8773;
const USER = "recepcija";
const FORM = "application/x-www-form-urlencoded";
const SECRET = "SECRETFRAGMENT";
/* Not the default address, so the test shows that the configured one is used. */
const PAYMENT_URL = "https://www.cpay.com.mk/client/Page/default.aspx?xml_id=/en-US/.loginToPay/.simple/";
const CPAY_NAMES = ["AmountToPay", "PayToMerchant", "MerchantName", "AmountCurrency", "Details1", "Details2", "PaymentOKURL", "PaymentFailURL", "CheckSumHeader", "CheckSum"];
const TEXT = {
  amountFormat: "Износот не е добар. Внесете го само со цифри, без точки и запирки (пример: 21802).",
  amountLimit: "Износот е поголем од дозволениот. Проверете дали има цифра повеќе.",
  reservation: "Бројот на резервација не е добар. Внесете го само со цифри, како што стои во Booking.",
  stale: "Страницата беше отворена предолго. Проверете ги износот и резервацијата и притиснете повторно.",
  alreadyStarted: "Оваа наплата е веќе започната. Не ја праќаме повторно. Погледнете ја листата.",
  notSetUp: "Наплатата не е поставена до крај. Јавете му се на сопственикот.",
  siteFailed: "Наплатата не е започната. Пробајте повторно за една минута. Ако се повтори, јавете му се на сопственикот.",
};

const main = makePrivateDir({ cpay: { ...TEST_CPAY, payment_url: PAYMENT_URL } });
const noKey = makePrivateDir({ cpay: { ...TEST_CPAY, key: "" } });
const brokenDir = mkdtempSync(path.join(tmpdir(), "oaza-php-")).replaceAll("\\", "/");
const mainLog = `${main.dir}/php-errors.log`;
const brokenLog = `${brokenDir}/php-errors.log`;
const stops = [];
const bodies = [];

before(async () => {
  writeFileSync(`${brokenDir}/config.php`, `<?php\nreturn ['rate' => ['salt' => 'x' '${SECRET}']];\n`);
  stops.push(
    await startPhp(PORT, main.config, { errorLog: mainLog }),
    await startPhp(NO_KEY_PORT, noKey.config),
    await startPhp(BROKEN_PORT, `${brokenDir}/config.php`, { errorLog: brokenLog }),
  );
});
after(async () => {
  await Promise.all(stops.map((stop) => stop()));
  for (const dir of [main.dir, noKey.dir, brokenDir]) rmSync(dir, { recursive: true, force: true });
});

const auth = (user) => (user ? { Authorization: `Basic ${Buffer.from(`${user}:x`).toString("base64")}` } : {});
const origin = (port) => `http://127.0.0.1:${port}`;

/** One request; every answer's body is remembered for the last test. */
async function ask(port, method, urlPath, options = {}) {
  const res = await request(port, method, urlPath, options);
  bodies.push(res.body);
  return res;
}
const getPage = (port = PORT, { user = USER, urlPath = "/naplata/" } = {}) => ask(port, "GET", urlPath, { headers: auth(user) });
const postStart = (port, fields, { user = USER } = {}) =>
  ask(port, "POST", "/naplata/start.php", { body: formBody(fields), headers: { "Content-Type": FORM, Origin: origin(port), ...auth(user) } });

/** Every answer of the two addresses: a page in UTF-8 that is never stored, indexed, framed or read as another type. */
function assertPageHeaders(res) {
  assert.match(res.headers["content-type"], /^text\/html; charset=utf-8$/i);
  assert.equal(res.headers["cache-control"], "no-store");
  assert.equal(res.headers["x-robots-tag"], "noindex");
  assert.equal(res.headers["content-security-policy"], "frame-ancestors 'none'");
  assert.equal(res.headers["x-content-type-options"], "nosniff");
}

const decode = (text) =>
  text.replace(/&quot;/g, '"').replace(/&#0?39;/g, "'").replace(/&lt;/g, "<").replace(/&gt;/g, ">").replace(/&amp;/g, "&");
/** The hidden fields of a page as [name, value] pairs, in their order. */
const hidden = (html) =>
  [...html.matchAll(/<input type="hidden" name="([^"]*)" value="([^"]*)">/g)].map(([, name, value]) => [decode(name), decode(value)]);
/** The table row that shows this reference; "" when there is none. */
const rowOf = (html, ref) => html.split("<tr").find((part) => part.includes(`${ref}</td>`)) ?? "";

/** Opens the page as that user and returns the reference and token of its form. */
async function freshForm(port = PORT, user = USER) {
  const res = await getPage(port, { user });
  assert.equal(res.status, 200);
  const fields = Object.fromEntries(hidden(res.body));
  assert.match(fields.ref, /^[0-9A-Z]{10}$/);
  assert.match(fields.token, /^[0-9a-f]{64}$/);
  return fields;
}

const recordFiles = (dir = main.dir) =>
  existsSync(`${dir}/cpay-payments`) ? readdirSync(`${dir}/cpay-payments`).filter((name) => /^[0-9A-Z]{10}-\d{6,20}\.json$/.test(name)).sort() : [];
const recordsOf = (reservation) => recordFiles().filter((name) => name.endsWith(`-${reservation}.json`));
const readRecord = (name) => JSON.parse(readFileSync(`${main.dir}/cpay-payments/${name}`, "utf8"));
const stamp = (time) => new Date(time * 1000).toISOString().replace(/\.\d{3}Z$/, "Z");

/** Writes a record file the way the store does, started `ago` seconds ago; `changes` replaces parts of it. */
function seedRecord(tail, ago, changes = {}) {
  const time = Math.floor(Date.now() / 1000) - ago;
  const ref = time.toString(36).toUpperCase().padStart(6, "0") + tail;
  const base = { ref, created: stamp(time), updated: stamp(time), user: USER, amount: 21802, reservation: "4567890123" };
  const rest = { payment_url: PAYMENT_URL, hash: "md5", status: "waiting", cpay_ref: "", extra_refs: [], notices: [] };
  const record = { ...base, ...rest, ...changes };
  mkdirSync(`${main.dir}/cpay-payments`, { recursive: true });
  writeFileSync(`${main.dir}/cpay-payments/${ref}-${record.reservation}.json`, JSON.stringify({ ...record, details1: `booking ${record.reservation}` }, null, 2));
  return { ref, time };
}

/** dd.mm.yyyy hh:mm in Skopje, as the list shows a time. */
function skopje(time) {
  const options = { timeZone: "Europe/Skopje", day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit", hourCycle: "h23" };
  const part = Object.fromEntries(new Intl.DateTimeFormat("en-GB", options).formatToParts(new Date(time * 1000)).map(({ type, value }) => [type, value]));
  return `${part.day}.${part.month}.${part.year} ${part.hour}:${part.minute}`;
}

/* The two kinds of refusal in the design's words (section 8): the page could not open, or the request did not come from it. */
const REFUSED = {
  opening: ["Страницата за наплата не може да се отвори вака.", "Затворете го прозорецот, отворете ја страницата одново и најавете се. Ако пак не се отвори, јавете му ја на сопственикот оваа шифра:"],
  request: ["Барањето не дојде од страницата за наплата. Не е започната наплата.", "Вратете се на страницата за наплата и пробајте одново. Ако се повтори, јавете му ја на сопственикот оваа шифра:"],
};

/** Asserts that a page is the refusal of that kind, ending with that code and the way back. */
function assertRefused(body, kind, code) {
  const [what, todo] = REFUSED[kind];
  assert.ok(body.includes(`<p class="message">${what}</p>`), `${code}: what happened`);
  assert.ok(body.includes(`<p>${todo} <code>${code}</code></p>`), `${code}: what to do, then the code`);
  assert.ok(body.includes('<a class="button quiet" href="/naplata/">Назад кон наплата</a>'), `${code}: the way back`);
}

test("without a user both addresses answer 403 with the code staff_no_user", async () => {
  for (const urlPath of ["/naplata/", "/naplata/index.php"]) {
    const res = await getPage(PORT, { user: null, urlPath });
    assert.equal(res.status, 403, urlPath);
    assertPageHeaders(res);
    assertRefused(res.body, "opening", "staff_no_user");
    assert.doesNotMatch(res.body, /<form|<input/);
  }
  const start = await postStart(PORT, { amount: "21802", reservation: "4567890123" }, { user: null });
  assert.equal(start.status, 403);
  assertPageHeaders(start);
  assertRefused(start.body, "opening", "staff_no_user");
  assert.deepEqual(recordFiles(), []);
});

test("a signed-in user sees the form, the notes, the user name and an empty list", async () => {
  const res = await getPage();
  assert.equal(res.status, 200);
  assertPageHeaders(res);
  for (const text of [
    "<title>Наплата на картички од Booking</title>",
    "<h1>Наплата на картички од Booking</h1>",
    "Најавени сте како: <strong>recepcija</strong>",
    "Износ во денари",
    "Само цифри, без точки и запирки. Пример: 21802",
    "Број на резервација (Booking)",
    "Само цифри.",
    '<button type="submit">Продолжи кон cPay</button>',
    "Картичката ја внесувате на страницата на cPay. Таму не притискајте Назад и не освежувајте.",
    "Ако cPay покаже грешка наместо форма за картичка, застанете и јавете му се на сопственикот.",
    "Последни наплати",
    "Сè уште нема наплати.",
    '<meta name="robots" content="noindex">',
  ]) {
    assert.ok(res.body.includes(text), text);
  }
  assert.match(res.body, /<form class="charge" method="post" action="\/naplata\/start\.php" autocomplete="off">/);
  assert.match(res.body, /<input type="text" id="amount" name="amount" value="" inputmode="numeric" autocomplete="off"[^>]* autofocus>/);
  assert.match(res.body, /<input type="text" id="reservation" name="reservation" value="" inputmode="numeric" autocomplete="off"[^>]*>/);
  assert.deepEqual(hidden(res.body).map(([name]) => name), ["ref", "token"]);
  assert.doesNotMatch(res.body, /https?:\/\/|<link| src=/, "no external files, fonts or images");
  const other = await getPage(PORT, { user: "ana.petrova" });
  assert.ok(other.body.includes("Најавени сте како: <strong>ana.petrova</strong>"));
  assert.notEqual(Object.fromEntries(hidden(other.body)).ref, Object.fromEntries(hidden(res.body)).ref, "every page gets a reference of its own");
  assert.deepEqual(recordFiles(), [], "opening the page starts nothing");
});

test("HEAD is answered like GET, without a body", async () => {
  const res = await ask(PORT, "HEAD", "/naplata/", { headers: auth(USER) });
  assert.equal(res.status, 200);
  assertPageHeaders(res);
  assert.equal(res.body, "");
});

test("the page's script says what will be charged while the amount is typed, and lets a double click send once", async () => {
  const res = await getPage();
  const scripts = [...res.body.matchAll(/<script>([\s\S]*?)<\/script>/g)].map(([, code]) => code);
  assert.equal(scripts.length, 1, "one inline script");
  const max = res.body.match(/ data-max="(\d+)"/)?.[1];
  assert.equal(max, String(TEST_CPAY.max_amount), "the limit comes from the config");
  /* A stand-in for the browser: the two elements the script reads and writes, and the form it listens to. */
  const heard = {};
  const amount = { value: "", getAttribute: (name) => (name === "data-max" ? max : null), addEventListener: (type, listener) => (heard[type] = listener) };
  const will = { textContent: "not yet set" };
  const form = { addEventListener: (type, listener) => (heard[`form ${type}`] = listener) };
  const timers = [];
  const page = { document: { getElementById: (id) => ({ amount, will })[id], forms: [form] }, setTimeout: (callback) => timers.push(callback) };
  vm.runInNewContext(scripts[0], page);
  assert.equal(will.textContent, "", "nothing is said about an empty field");
  const said = (typed) => {
    amount.value = typed;
    heard.input();
    return will.textContent;
  };
  assert.equal(said("21802"), "Ќе се наплатат: 21.802 ден.");
  assert.equal(said("5"), "Ќе се наплатат: 5 ден.");
  assert.equal(said("1000"), "Ќе се наплатат: 1.000 ден.");
  assert.equal(said("300000"), "Ќе се наплатат: 300.000 ден.", "the limit itself");
  for (const typed of ["300001", "1234567", "12345678", "0", "021802", "21.802", "21802,00", " 21802", "21802\n", "2e4", ""]) {
    assert.equal(said(typed), "", `${JSON.stringify(typed)} will not be charged, so nothing is promised`);
  }
  let stopped = 0;
  const press = () => heard["form submit"]({ preventDefault: () => (stopped += 1) });
  press();
  assert.equal(stopped, 0, "the first press sends the form");
  press();
  press();
  assert.equal(stopped, 2, "further presses right after it send nothing");
  timers.forEach((callback) => callback());
  press();
  assert.equal(stopped, 2, "a few seconds later the button works again");
});

test("the list shows each state as text, the amounts as 21.802 and today's total", async () => {
  const push = [{ at: stamp(Math.floor(Date.now() / 1000)), kind: "ok", via: "push" }];
  const waiting = seedRecord("WAIT", 90, { reservation: "1000000001" });
  const paid = seedRecord("PAID", 0, { reservation: "1000000002", status: "paid", cpay_ref: "123456", notices: push });
  const failed = seedRecord("FAIL", 120, { reservation: "1000000003", status: "failed", amount: 950 });
  const silent = seedRecord("NONE", 2760, { reservation: "1000000004", amount: 1234567 });
  const twice = seedRecord("TWO2", 0, { reservation: "1000000005", status: "paid", amount: 5000, cpay_ref: "777001", extra_refs: ["777002"] });
  const res = await getPage();
  assert.equal(res.status, 200);
  for (const column of ["Време", "Резервација", "Износ", "Состојба", "cPay реф.", "Шифра"]) {
    assert.ok(res.body.includes(`>${column}</th>`), column);
  }
  const row = (record) => rowOf(res.body, record.ref);
  assert.match(row(waiting), /Чека одговор/);
  assert.match(row(waiting), /Не наплаќајте повторно додека чека\./);
  assert.ok(row(waiting).includes(">21.802<") && row(waiting).includes("1000000001") && row(waiting).includes(skopje(waiting.time)), "amount, reservation, time");
  assert.match(row(paid), /Наплатено/);
  assert.ok(row(paid).includes("123456") && row(paid).includes("потврдено од cPay"), "the cPay reference, confirmed by a push");
  assert.match(row(failed), /Не е наплатено/);
  assert.ok(row(failed).includes(">950<") && !row(failed).includes("потврдено од cPay"));
  assert.match(row(silent), /Нема одговор/);
  assert.match(row(silent), /Проверете во cPay порталот по шифрата пред да наплатите повторно\./);
  assert.ok(row(silent).includes(">1.234.567<") && !row(silent).includes("Чека одговор"));
  assert.match(row(twice), /Две наплати: проверете во cPay порталот\./);
  assert.ok(row(twice).includes("777001") && row(twice).includes("777002") && row(twice).includes(">5.000<"));
  assert.ok(!row(paid).includes("Две наплати"), "the flag is on that row only");
  assert.ok(res.body.includes("Денес наплатено: 2 наплати, 26.802 ден."), "today's count and sum");
  assert.ok(!res.body.includes("Сè уште нема наплати."));
  assert.equal(res.body.split("<tr class=").length - 1, 5);
});

test("index.php takes GET and HEAD only, start.php POST only", async () => {
  const form = await freshForm();
  const toIndex = await ask(PORT, "POST", "/naplata/index.php", {
    body: formBody({ amount: "21802", reservation: "2000000001", ...form }),
    headers: { "Content-Type": FORM, Origin: origin(PORT), ...auth(USER) },
  });
  assert.equal(toIndex.status, 405);
  assert.equal(toIndex.headers.allow, "GET, HEAD");
  assertPageHeaders(toIndex);
  const toStart = await getPage(PORT, { urlPath: `/naplata/start.php?${formBody({ amount: "21802", reservation: "2000000001", ...form })}` });
  assert.equal(toStart.status, 405);
  assert.equal(toStart.headers.allow, "POST");
  assertPageHeaders(toStart);
  for (const res of [toIndex, toStart]) {
    assert.deepEqual(hidden(res.body), []);
    assertRefused(res.body, "request", "staff_method");
  }
  assert.deepEqual(recordsOf("2000000001"), []);
});

test("a POST without Origin answers 403, one with another content type 400", async () => {
  const form = await freshForm();
  const body = formBody({ amount: "21802", reservation: "2000000002", ...form });
  const noOrigin = await ask(PORT, "POST", "/naplata/start.php", { body, headers: { "Content-Type": FORM, ...auth(USER) } });
  assert.equal(noOrigin.status, 403);
  assertPageHeaders(noOrigin);
  const elsewhere = await ask(PORT, "POST", "/naplata/start.php", { body, headers: { "Content-Type": FORM, Origin: "https://www.booking.example", ...auth(USER) } });
  assert.equal(elsewhere.status, 403);
  const asText = await ask(PORT, "POST", "/naplata/start.php", { body, headers: { "Content-Type": "text/plain", Origin: origin(PORT), ...auth(USER) } });
  assert.equal(asText.status, 400);
  assertPageHeaders(asText);
  for (const res of [noOrigin, elsewhere, asText]) assert.deepEqual(hidden(res.body), []);
  assertRefused(noOrigin.body, "request", "staff_other_origin");
  assertRefused(elsewhere.body, "request", "staff_other_origin");
  assertRefused(asText.body, "request", "staff_not_a_form");
  assert.deepEqual(recordsOf("2000000002"), []);
});

test("a good POST starts one payment: a waiting record and a form for cPay with the ten signed fields; again it starts nothing", async () => {
  const form = await freshForm();
  const fields = { amount: "21802", reservation: "2000000003", ref: form.ref, token: form.token };
  const res = await postStart(PORT, fields);
  assert.equal(res.status, 200);
  assertPageHeaders(res);
  assert.deepEqual(recordsOf("2000000003"), [`${form.ref}-2000000003.json`]);
  const record = readRecord(`${form.ref}-2000000003.json`);
  assert.match(record.created, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/);
  assert.deepEqual(record, {
    ref: form.ref, created: record.created, updated: record.created, user: USER, amount: 21802, reservation: "2000000003",
    details1: "booking 2000000003", payment_url: PAYMENT_URL, hash: "md5", status: "waiting", cpay_ref: "", extra_refs: [], notices: [],
  });

  const action = res.body.match(/<form method="post" action="([^"]*)">/);
  assert.ok(action, "a form that posts, with no other attribute");
  assert.equal(decode(action[1]), PAYMENT_URL, "to the configured payment address");
  assert.doesNotMatch(res.body, /target=|enctype=/);
  const sent = hidden(res.body);
  assert.deepEqual(sent.map(([name]) => name), CPAY_NAMES, "exactly the ten fields, in order");
  assert.equal(res.body.split("<input").length - 1, 10, "and no other field");
  assert.deepEqual(sent.slice(0, 8), [
    ["AmountToPay", "2180200"], ["PayToMerchant", TEST_CPAY.merchant_id], ["MerchantName", TEST_CPAY.merchant_name], ["AmountCurrency", "MKD"],
    ["Details1", "booking 2000000003"], ["Details2", form.ref], ["PaymentOKURL", TEST_CPAY.ok_url], ["PaymentFailURL", TEST_CPAY.fail_url],
  ]);
  const { header, checksum } = cpayChecksum(sent.slice(0, 8), TEST_CPAY.key);
  assert.deepEqual(sent.slice(8), [["CheckSumHeader", header], ["CheckSum", checksum]], "the checksum the JavaScript helper computes");
  assert.ok(res.body.includes('<noscript><button type="submit">Продолжи кон cPay</button></noscript>'), "a nameless button without JavaScript");
  assert.ok(res.body.includes("<script>document.forms[0].submit();</script>"), "sent by script");
  assert.ok(res.body.includes("Ве префрламе на cPay…") && res.body.includes("Ќе се наплатат: 21.802 ден."));

  const again = await postStart(PORT, fields);
  assert.equal(again.status, 409);
  assertPageHeaders(again);
  assert.ok(again.body.includes(TEXT.alreadyStarted));
  assert.deepEqual(hidden(again.body), []);
  assert.doesNotMatch(again.body, /cpay\.com\.mk|<form/, "no cPay form");
  assert.deepEqual(recordsOf("2000000003"), [`${form.ref}-2000000003.json`]);
  assert.deepEqual(readRecord(`${form.ref}-2000000003.json`), record, "the record is untouched");
  const list = await getPage();
  assert.match(rowOf(list.body, form.ref), /Чека одговор/);
});

test("a changed token, another user's token or none answers 409 with the typed values kept and a fresh reference", async () => {
  const form = await freshForm();
  const foreign = await freshForm(PORT, "ana");
  const typed = { amount: "21802", reservation: "2000000004" };
  const flipped = form.token.slice(0, -1) + (form.token.endsWith("0") ? "1" : "0");
  const cases = { changed: { ...typed, ref: form.ref, token: flipped }, "another user's": { ...typed, ...foreign }, none: typed, "a made-up reference": { ...typed, ref: "../x", token: form.token } };
  let last;
  for (const [label, fields] of Object.entries(cases)) {
    last = await postStart(PORT, fields);
    assert.equal(last.status, 409, label);
    assertPageHeaders(last);
    assert.ok(last.body.includes(TEXT.stale), label);
    assert.ok(last.body.includes('name="amount" value="21802"') && last.body.includes('name="reservation" value="2000000004"'), `${label}: typed values`);
    assert.ok(last.body.includes("Ќе се наплатат: 21.802 ден."), `${label}: the amount is echoed`);
    const fresh = Object.fromEntries(hidden(last.body));
    assert.deepEqual(Object.keys(fresh), ["ref", "token"], label);
    assert.ok(fresh.ref !== form.ref && fresh.ref !== foreign.ref, `${label}: a fresh reference`);
  }
  assert.deepEqual(recordsOf("2000000004"), []);
  const pressedAgain = await postStart(PORT, { ...typed, ...Object.fromEntries(hidden(last.body)) });
  assert.equal(pressedAgain.status, 200, "pressing the button of that page works");
  assert.equal(recordsOf("2000000004").length, 1);
});

test("a bad amount or reservation number answers 422 with its message, keeps what was typed and starts nothing", async () => {
  const cases = [
    [{ amount: "21.802", reservation: "2000000005" }, TEXT.amountFormat],
    [{ amount: "0", reservation: "2000000005" }, TEXT.amountFormat],
    [{ amount: "", reservation: "2000000005" }, TEXT.amountFormat],
    [{ amount: "21802,00", reservation: "2000000005" }, TEXT.amountFormat],
    [{ amount: "300001", reservation: "2000000005" }, TEXT.amountLimit],
    [{ amount: "21802", reservation: "20000a0005" }, TEXT.reservation],
    [{ amount: "21802", reservation: "" }, TEXT.reservation],
  ];
  for (const [typed, message] of cases) {
    const res = await postStart(PORT, { ...typed, ...(await freshForm()) });
    const label = JSON.stringify(typed);
    assert.equal(res.status, 422, label);
    assertPageHeaders(res);
    for (const text of [TEXT.amountFormat, TEXT.amountLimit, TEXT.reservation]) assert.equal(res.body.includes(text), text === message, `${label}: ${text}`);
    assert.ok(res.body.includes(`name="amount" value="${typed.amount}"`) && res.body.includes(`name="reservation" value="${typed.reservation}"`), `${label}: typed values`);
    assert.deepEqual(hidden(res.body).map(([name]) => name), ["ref", "token"], `${label}: the form again`);
  }
  assert.deepEqual([...recordsOf("2000000005"), ...recordsOf("20000a0005")], []);
  const atLimit = await postStart(PORT, { amount: "300000", reservation: "2000000005", ...(await freshForm()) });
  assert.equal(atLimit.status, 200, "the limit itself is allowed");
});

test("markup typed into a field comes back escaped", async () => {
  const res = await postStart(PORT, { amount: "<b>1</b>", reservation: '"><script>alert(1)</script>', ...(await freshForm()) });
  assert.equal(res.status, 422);
  assert.ok(res.body.includes('name="reservation" value="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"'));
  assert.ok(res.body.includes('name="amount" value="&lt;b&gt;1&lt;/b&gt;"'));
  assert.ok(!res.body.includes('"><script>') && !res.body.includes("<script>alert") && !res.body.includes("<b>1</b>"));
});

test("a second charge for a paid reservation asks first and starts nothing; with confirm=yes it goes on", async () => {
  const paid = seedRecord("PAY2", 300, { reservation: "2000000006", status: "paid", cpay_ref: "424242" });
  const fields = { amount: "21802", reservation: "2000000006", ...(await freshForm()) };
  const asked = await postStart(PORT, fields);
  assert.equal(asked.status, 200);
  assertPageHeaders(asked);
  for (const text of [
    "За резервација 2000000006 веќе има наплата:",
    "Ќе се наплатат: 21.802 ден.",
    "Да се наплати уште еднаш?",
    '<a class="button" href="/naplata/">Не, врати ме</a>',
    '<button type="submit" class="quiet">Да, наплати повторно</button>',
    '<form method="post" action="/naplata/start.php">',
  ]) {
    assert.ok(asked.body.includes(text), text);
  }
  assert.match(rowOf(asked.body, paid.ref), /Наплатено/);
  assert.ok(rowOf(asked.body, paid.ref).includes("424242"));
  assert.deepEqual(hidden(asked.body), [...Object.entries(fields), ["seen", paid.ref], ["confirm", "yes"]], "the same values again, the charge it showed, and confirm=yes");
  assert.doesNotMatch(asked.body, /cpay\.com\.mk/, "nothing for cPay yet");
  assert.equal(recordsOf("2000000006").length, 1, "nothing was started");

  const confirmed = await postStart(PORT, Object.fromEntries(hidden(asked.body)));
  assert.equal(confirmed.status, 200);
  assert.deepEqual(hidden(confirmed.body).map(([name]) => name), CPAY_NAMES);
  assert.deepEqual(recordsOf("2000000006").sort(), [`${paid.ref}-2000000006.json`, `${fields.ref}-2000000006.json`].sort());
});

const toCpay = (res) => res.status === 200 && JSON.stringify(hidden(res.body).map(([name]) => name)) === JSON.stringify(CPAY_NAMES);
const askedAgain = (res) => res.status === 200 && res.body.includes("Да се наплати уште еднаш?") && !/cpay\.com\.mk/.test(res.body);

/** Two people open the page and are each asked about the same paid reservation; the hidden fields of their confirm pages. */
async function twoConfirmPages(reservation, tail) {
  const paid = seedRecord(tail, 300, { reservation, status: "paid", cpay_ref: "434343" });
  const pages = [];
  for (const form of [await freshForm(), await freshForm()]) {
    const asked = await postStart(PORT, { amount: "21802", reservation, ...form });
    assert.ok(askedAgain(asked) && rowOf(asked.body, paid.ref).includes("Наплатено"), "each is asked about the paid charge");
    pages.push(Object.fromEntries(hidden(asked.body)));
  }
  return pages;
}

test("of two confirmations for one reservation, the second is asked again and shown the charge the first one started", async () => {
  const [first, second] = await twoConfirmPages("2000000011", "PAY3");
  assert.ok(toCpay(await postStart(PORT, first)), "the first goes on to cPay with one press");
  const again = await postStart(PORT, second);
  assert.ok(askedAgain(again), "the second gets the question again and nothing for cPay");
  assert.match(rowOf(again.body, first.ref), /Чека одговор/, "with the charge the first one started");
  assert.equal(recordsOf("2000000011").length, 2, "nothing more was started");
  assert.ok(toCpay(await postStart(PORT, Object.fromEntries(hidden(again.body)))), "confirmed once more, knowing both, it goes on");
  assert.equal(recordsOf("2000000011").length, 3);
});

/* PHP's built-in server answers one request at a time; the true race is the PHP process test "of confirmations ... sent at the same moment" in test/php/staff.test.php. */
test("of two quick confirmations for one reservation, one goes on and the second is asked again", async () => {
  const [first, second] = await twoConfirmPages("2000000012", "PAY4");
  const answers = await Promise.all([postStart(PORT, first), postStart(PORT, second)]);
  assert.equal(answers.filter(toCpay).length, 1, "one form for cPay");
  const asked = answers.find(askedAgain);
  assert.ok(asked, "the other is asked again");
  const started = answers.indexOf(asked) === 0 ? second : first;
  assert.match(rowOf(asked.body, started.ref), /Чека одговор/, "and shown the charge that went on");
  assert.equal(recordsOf("2000000012").length, 2, "one more record");
});

test("without the cPay key the page says that charging is not set up, and start.php answers 503", async () => {
  const page = await getPage(NO_KEY_PORT);
  assert.equal(page.status, 200);
  assertPageHeaders(page);
  assert.ok(page.body.includes(TEXT.notSetUp));
  assert.ok(page.body.includes("Последни наплати") && page.body.includes("Најавени сте како: <strong>recepcija</strong>"), "the list and the user are still shown");
  assert.doesNotMatch(page.body, /<form|<input/);
  const start = await postStart(NO_KEY_PORT, { amount: "21802", reservation: "2000000007", ref: "TMZK4A7Q2B", token: "0".repeat(64) });
  assert.equal(start.status, 503);
  assertPageHeaders(start);
  assert.ok(start.body.includes(TEXT.notSetUp));
  assert.deepEqual(hidden(start.body), []);
  assert.deepEqual(recordFiles(noKey.dir), []);
});

test("a config that does not parse answers 500 and the log keeps no fragment of it", async () => {
  const page = await getPage(BROKEN_PORT);
  assert.equal(page.status, 500);
  assertPageHeaders(page);
  assert.ok(page.body.includes(TEXT.notSetUp));
  assert.doesNotMatch(page.body, /<form|<input/);
  const start = await postStart(BROKEN_PORT, { amount: "21802", reservation: "2000000008" });
  assert.equal(start.status, 500);
  assertPageHeaders(start);
  assert.ok(start.body.includes(TEXT.notSetUp));
  const log = readFileSync(brokenLog, "utf8");
  assert.equal(log.match(/Hotel Oaza staff page: ParseError on line 2/g)?.length, 2, log);
  assert.doesNotMatch(log + page.body + start.body, new RegExp(SECRET));
});

test("a record that cannot be written answers 500, sends nothing to cPay and is logged by its code", async () => {
  const form = await freshForm();
  const folder = `${main.dir}/cpay-payments`;
  renameSync(folder, `${folder}-aside`);
  writeFileSync(folder, "a file where the folder should be");
  try {
    const res = await postStart(PORT, { amount: "21802", reservation: "2000000009", ...form });
    assert.equal(res.status, 500);
    assertPageHeaders(res);
    assert.ok(res.body.includes(TEXT.siteFailed) && !res.body.includes(TEXT.notSetUp), "says that nothing was started, not that the setup is incomplete");
    assert.deepEqual(hidden(res.body), []);
    assert.doesNotMatch(res.body, /cpay\.com\.mk|<form/, "no cPay form");
    assert.match(readFileSync(`${main.dir}/logs/errors.log`, "utf8"), / staff_failed payments_write\n/);
  } finally {
    rmSync(folder);
    renameSync(`${folder}-aside`, folder);
  }
  assert.deepEqual(recordsOf("2000000009"), []);
  assert.equal((await getPage()).status, 200, "the page works again once the folder is back");
});

test("a start that cannot have the page's lock answers 500, starts nothing and is logged by its code", async () => {
  const fields = { amount: "21802", reservation: "2000000010", ...(await freshForm()) };
  const lock = `${main.dir}/cpay-staff-start.lock`;
  rmSync(lock, { force: true });
  mkdirSync(lock); /* a folder where the lock file should be */
  try {
    const res = await postStart(PORT, fields);
    assert.equal(res.status, 500);
    assertPageHeaders(res);
    assert.ok(res.body.includes(TEXT.siteFailed) && !res.body.includes(TEXT.notSetUp), "says that nothing was started, not that the setup is incomplete");
    assert.deepEqual(hidden(res.body), []);
    assert.doesNotMatch(res.body, /cpay\.com\.mk|<form/, "no cPay form");
    assert.match(readFileSync(`${main.dir}/logs/errors.log`, "utf8"), / staff_failed staff_lock\n/);
  } finally {
    rmSync(lock, { recursive: true });
  }
  assert.deepEqual(recordsOf("2000000010"), []);
  const retried = await postStart(PORT, fields);
  assert.equal(retried.status, 200, "the same form goes on once the lock can be had");
  assert.deepEqual(recordsOf("2000000010"), [`${fields.ref}-2000000010.json`]);
});

test("nothing of the key or of the page's own secret is in any answer or log", () => {
  const secret = readFileSync(`${main.dir}/cpay-staff-secret`, "utf8");
  assert.match(secret, /^[0-9a-f]{64}$/);
  const logs = [mainLog, `${main.dir}/logs/errors.log`, `${noKey.dir}/logs/errors.log`].filter((file) => existsSync(file)).map((file) => readFileSync(file, "utf8"));
  const everything = [...bodies, ...logs].join("\n");
  assert.ok(bodies.length > 20, "the answers of the tests above");
  assert.ok(!everything.includes(TEST_CPAY.key), "the key");
  assert.ok(!everything.includes(secret), "the secret");
  assert.deepEqual(logs.filter((log) => /uncaught|Warning|Notice|Deprecated|Fatal/.test(log)), [], "PHP had nothing to complain about");
});
