import { after, before, beforeEach, test } from "node:test";
import assert from "node:assert/strict";
import { existsSync, mkdirSync, readFileSync, rmSync } from "node:fs";
import { makePrivateDir, request, startPhp } from "./helpers/php.mjs";

/* POST /api/enquiry.php on PHP's built-in server; mail goes to a JSON-lines file. */
const PORT = 8765;
const ORIGIN = `http://127.0.0.1:${PORT}`;
const FORM = "application/x-www-form-urlencoded;charset=UTF-8";
const { dir, config } = makePrivateDir();
const MAIL = `${dir}/mail/sent.jsonl`;
let stop;

before(async () => {
  stop = await startPhp(PORT, config);
});
after(async () => {
  await stop?.();
  rmSync(dir, { recursive: true, force: true });
});
beforeEach(() => {
  rmSync(`${dir}/ratelimit.json`, { force: true });
  rmSync(MAIL, { force: true });
});

const BASE = { name: "Test Guest", email: "guest@example.com", phone: "", message: "", page: "contact", lang: "en", website: "" };
const VALID = {
  stay: { ...BASE, type: "stay", arrival: "2030-05-20", departure: "2030-05-23", guests: "2", room: "double" },
  group: { ...BASE, type: "group", arrival: "2030-05-20", departure: "2030-05-23", guests: "10+", room: "any" },
  wedding: { ...BASE, type: "wedding", arrival: "2030-06-14", eventGuests: "250", page: "weddings" },
  conference: { ...BASE, type: "conference", arrival: "2030-03-10", eventGuests: "150", company: "Test Company", overnight: "yes", page: "conferences" },
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
    [{ ...VALID.stay, departure: "2030-05-19" }, ["departure"]],
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
