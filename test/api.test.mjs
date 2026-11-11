import { after, before, beforeEach, test } from "node:test";
import assert from "node:assert/strict";
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { makePrivateDir, request, startPhp, TEST_CPAY } from "./helpers/php.mjs";

/* POST /api/enquiry.php on PHP's built-in server; mail goes to a JSON-lines file. */
const PORT = 8765;
const ORIGIN = `http://127.0.0.1:${PORT}`;
const FORM = "application/x-www-form-urlencoded;charset=UTF-8";
const NO_CONFIG_PORT = 8769;
const { dir, config } = makePrivateDir();
const noConfig = makePrivateDir();
const MAIL = `${dir}/mail/sent.jsonl`;
const stops = [];

before(async () => {
  rmSync(noConfig.config); /* the endpoint on NO_CONFIG_PORT finds no config.php */
  stops.push(await startPhp(PORT, config), await startPhp(NO_CONFIG_PORT, noConfig.config));
});
after(async () => {
  await Promise.all(stops.map((stop) => stop()));
  rmSync(dir, { recursive: true, force: true });
  rmSync(noConfig.dir, { recursive: true, force: true });
});
beforeEach(() => {
  rmSync(`${dir}/ratelimit.json`, { force: true });
  rmSync(MAIL, { force: true });
});

/** Today in Europe/Skopje plus `days`, as YYYY-MM-DD: the form refuses past dates, so test dates are relative. */
function inDays(days) {
  const today = new Intl.DateTimeFormat("en-CA", { timeZone: "Europe/Skopje" }).format(new Date());
  const date = new Date(`${today}T00:00:00Z`);
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
}
const ARRIVAL = inDays(60);
const DEPARTURE = inDays(63);

const BASE = { name: "Test Guest", email: "guest@example.com", phone: "", message: "", page: "contact", lang: "en", website: "" };
const VALID = {
  stay: { ...BASE, type: "stay", arrival: ARRIVAL, departure: DEPARTURE, guests: "2", room: "double" },
  group: { ...BASE, type: "group", arrival: ARRIVAL, departure: DEPARTURE, guests: "10+", room: "any" },
  wedding: { ...BASE, type: "wedding", arrival: inDays(90), eventGuests: "250", page: "weddings" },
  conference: { ...BASE, type: "conference", arrival: inDays(30), eventGuests: "150", company: "Test Company", overnight: "yes", page: "conferences" },
};
const TYPE_LABELS = { stay: "Сместување", group: "Групна резервација", wedding: "Свадба или прослава", conference: "Конференција или семинар" };
const OK = { success: true, data: null, error: null };

const post = (fields, headers = {}) =>
  request(PORT, "POST", "/api/enquiry.php", {
    body: new URLSearchParams(fields).toString(),
    headers: { "Content-Type": FORM, Origin: ORIGIN, ...headers },
  });
const json = (res) => JSON.parse(res.body);
const fieldsOf = (res) => [...json(res).error.fields].sort();
const sent = () => (existsSync(MAIL) ? readFileSync(MAIL, "utf8").split("\n").filter(Boolean).map((line) => JSON.parse(line)) : []);

for (const [type, fields] of Object.entries(VALID)) {
  test(`a valid ${type} enquiry is mailed to reception`, async () => {
    const res = await post(fields);
    assert.equal(res.status, 200);
    assert.deepEqual(json(res), OK);
    const mails = sent();
    assert.equal(mails.length, 1);
    assert.equal(mails[0].to, "info@oazahotel.com.mk");
    assert.equal(mails[0].reply_to, "guest@example.com");
    assert.equal(mails[0].subject, `Барање од веб-страницата: ${TYPE_LABELS[type]} — Test Guest`);
    assert.match(mails[0].body, /Јазик на гостинот: EN\n/);
  });
}

test("answers are JSON in the site's envelope", async () => {
  const res = await post({ ...VALID.stay, email: "" });
  assert.match(res.headers["content-type"], /^application\/json; charset=utf-8/);
  assert.deepEqual(Object.keys(json(res)), ["success", "data", "error"]);
  assert.equal(json(res).success, false);
  assert.equal(json(res).data, null);
});

test("missing name and email are listed", async () => {
  const res = await post({ ...VALID.stay, name: "", email: "" });
  assert.equal(res.status, 422);
  assert.equal(json(res).error.code, "invalid");
  assert.deepEqual(fieldsOf(res), ["email", "name"]);
  assert.equal(sent().length, 0);
});

test("invalid values are listed field by field", async () => {
  const cases = [
    [{ ...VALID.stay, email: "not-an-email" }, ["email"]],
    [{ ...VALID.stay, type: "spa" }, ["type"]],
    [{ ...VALID.stay, guests: "99", room: "suite" }, ["guests", "room"]],
    [{ ...VALID.wedding, eventGuests: "1000" }, ["eventGuests"]],
    [{ ...VALID.conference, overnight: "maybe" }, ["overnight"]],
    [{ ...VALID.stay, arrival: "2020-01-01" }, ["arrival"]],
    [{ ...VALID.stay, departure: inDays(59) }, ["departure"]],
  ];
  for (const [fields, expected] of cases) {
    const res = await post(fields);
    assert.equal(res.status, 422, JSON.stringify(fields));
    assert.deepEqual(fieldsOf(res), expected);
  }
  assert.equal(sent().length, 0);
});

test("over-long values are refused, counted in characters", async () => {
  const cases = [
    [{ ...VALID.stay, name: "a".repeat(101) }, ["name"]],
    [{ ...VALID.stay, email: `${"a".repeat(189)}@example.com` }, ["email"]],
    [{ ...VALID.stay, phone: "1".repeat(41) }, ["phone"]],
    [{ ...VALID.conference, company: "x".repeat(151) }, ["company"]],
    [{ ...VALID.stay, message: "m".repeat(2001) }, ["message"]],
  ];
  for (const [fields, expected] of cases) {
    const res = await post(fields);
    assert.equal(res.status, 422);
    assert.deepEqual(fieldsOf(res), expected);
  }
  const res = await post({ ...VALID.stay, name: "ж".repeat(100), message: "m".repeat(2000) });
  assert.equal(res.status, 200);
});

test("line breaks cannot add mail headers", async () => {
  const bad = await post({ ...VALID.stay, email: "guest@example.com\r\nBcc: other@example.com" });
  assert.equal(bad.status, 422);
  assert.deepEqual(fieldsOf(bad), ["email"]);

  const res = await post({ ...VALID.stay, name: "Test\r\nBcc: other@example.com" });
  assert.equal(res.status, 200);
  const [mail] = sent();
  assert.doesNotMatch(mail.subject, /[\r\n]/);
  assert.equal(mail.subject, "Барање од веб-страницата: Сместување — Test Bcc: other@example.com");
});

test("the spam trap answers success and sends nothing", async () => {
  const res = await post({ ...VALID.stay, website: "https://spam.example" });
  assert.equal(res.status, 200);
  assert.deepEqual(json(res), OK);
  assert.equal(sent().length, 0);
  const log = readFileSync(`${dir}/logs/errors.log`, "utf8");
  assert.match(log, /^\d{4}-\d{2}-\d{2}T[\d:+-]+ spam_trap$/m);
  assert.doesNotMatch(log, /spam\.example|guest@example\.com|Test Guest/);
});

test("a sixth enquiry within the hour is refused", async () => {
  for (let i = 1; i <= 5; i += 1) assert.equal((await post(VALID.stay)).status, 200, `enquiry ${i}`);
  const res = await post(VALID.stay);
  assert.equal(res.status, 429);
  assert.deepEqual(json(res).error, { code: "rate_limited" });
  assert.equal(sent().length, 5);
});

test("requests from another site, or naming no site, are refused", async () => {
  const other = await post(VALID.stay, { Origin: "https://evil.example" });
  assert.equal(other.status, 403);
  assert.deepEqual(json(other).error, { code: "bad_request" });

  const bare = await request(PORT, "POST", "/api/enquiry.php", { body: new URLSearchParams(VALID.stay).toString(), headers: { "Content-Type": FORM } });
  assert.equal(bare.status, 403);
  assert.equal(sent().length, 0);
});

test("GET is not allowed", async () => {
  const res = await request(PORT, "GET", "/api/enquiry.php");
  assert.equal(res.status, 405);
  assert.equal(res.headers.allow, "POST");
  assert.deepEqual(json(res).error, { code: "bad_request" });
});

test("only form-encoded bodies are accepted", async () => {
  const res = await request(PORT, "POST", "/api/enquiry.php", { body: JSON.stringify(VALID.stay), headers: { "Content-Type": "application/json", Origin: ORIGIN } });
  assert.equal(res.status, 400);
  assert.deepEqual(json(res).error, { code: "bad_request" });
});

test("a failed send answers send_failed and logs only the code", async () => {
  rmSync(`${dir}/mail`, { recursive: true, force: true });
  try {
    const res = await post(VALID.stay);
    assert.equal(res.status, 500);
    assert.deepEqual(json(res).error, { code: "send_failed" });
    const log = readFileSync(`${dir}/logs/errors.log`, "utf8");
    assert.match(log, /^\d{4}-\d{2}-\d{2}T[\d:+-]+ send_failed\n$/m);
    assert.doesNotMatch(log, /guest@example\.com|Test Guest/);
  } finally {
    mkdirSync(`${dir}/mail`, { recursive: true });
  }
});

test("a request that names the site only in Referer is accepted", async () => {
  const res = await request(PORT, "POST", "/api/enquiry.php", {
    body: new URLSearchParams(VALID.stay).toString(),
    headers: { "Content-Type": FORM, Referer: `${ORIGIN}/en/contact/` },
  });
  assert.equal(res.status, 200);
  assert.deepEqual(json(res), OK);
  assert.equal(sent().length, 1);
});

test("a missing config file answers send_failed in the envelope and sends nothing", async () => {
  const res = await request(NO_CONFIG_PORT, "POST", "/api/enquiry.php", {
    body: new URLSearchParams(VALID.stay).toString(),
    headers: { "Content-Type": FORM, Origin: `http://127.0.0.1:${NO_CONFIG_PORT}` },
  });
  assert.equal(res.status, 500);
  assert.deepEqual(json(res), { success: false, data: null, error: { code: "send_failed" } });
  assert.equal(existsSync(`${noConfig.dir}/mail/sent.jsonl`), false);
});

test("a config that raises a warning while it loads still sends the enquiry, and its private log names config_warning", async () => {
  const warned = makePrivateDir({ cpay: TEST_CPAY });
  const code = readFileSync(warned.config, "utf8");
  const quoted = code.replace("'key' => 'TEST_PASS'", "'key' => \"TEST_$PASS\""); /* PHP reads $PASS as a variable */
  assert.notEqual(quoted, code, "the key is between double quotes now");
  writeFileSync(noConfig.config, quoted); /* the endpoint on NO_CONFIG_PORT reads it with the next request */
  try {
    const res = await request(NO_CONFIG_PORT, "POST", "/api/enquiry.php", {
      body: new URLSearchParams(VALID.stay).toString(),
      headers: { "Content-Type": FORM, Origin: `http://127.0.0.1:${NO_CONFIG_PORT}` },
    });
    assert.equal(res.status, 200, "a mistake in the cPay part does not stop the enquiry form");
    assert.deepEqual(json(res), OK);
    assert.equal(readFileSync(`${warned.dir}/mail/sent.jsonl`, "utf8").split("\n").filter(Boolean).length, 1);
    const lines = readFileSync(`${warned.dir}/logs/errors.log`, "utf8").split("\n").filter(Boolean);
    assert.deepEqual(lines.map((line) => line.slice(line.indexOf(" ") + 1)), ["config_warning"], "the code, once");
  } finally {
    rmSync(noConfig.config, { force: true });
    rmSync(warned.dir, { recursive: true, force: true });
  }
});
