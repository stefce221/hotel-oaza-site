import { after, before, test } from "node:test";
import assert from "node:assert/strict";
import { existsSync, mkdirSync, readdirSync, readFileSync, rmSync, statSync, writeFileSync } from "node:fs";
import { makePrivateDir, request, startPhp } from "./helpers/php.mjs";

/* /cpay/ok.php and /cpay/fail.php are registered with CaSys. Fields that are not a notice of cPay's (no return
   checksum), and every notice while the config has no cPay key, are kept as raw copies under the limits below and
   answered with the neutral page. What a verified notice does is in cpay-notice.test.mjs. */
const PORT = 8766;
const FLOOD_PORT = 8767;
const CPAY_PORT = 8770;
const FORM = { "Content-Type": "application/x-www-form-urlencoded" };
const { dir, config } = makePrivateDir();
const flood = makePrivateDir();
/* PHP's test server sees every request as 127.0.0.1, so here that address stands in for cPay's own. */
const cpay = makePrivateDir({ cpayPushIps: ["127.0.0.1"] });
const INBOX = `${dir}/cpay-inbox`;
const stops = [];

before(async () => {
  stops.push(await startPhp(PORT, config), await startPhp(FLOOD_PORT, flood.config), await startPhp(CPAY_PORT, cpay.config));
});
after(async () => {
  await Promise.all(stops.map((stop) => stop()));
  rmSync(dir, { recursive: true, force: true });
  rmSync(flood.dir, { recursive: true, force: true });
  rmSync(cpay.dir, { recursive: true, force: true });
});

const files = (folder, kind) => (existsSync(folder) ? readdirSync(folder).filter((name) => name.includes(`-${kind}-`)) : []);
const inbox = (kind) => files(INBOX, kind);
const log = (folder) => (existsSync(`${folder}/logs/errors.log`) ? readFileSync(`${folder}/logs/errors.log`, "utf8") : "");

/** Adds empty files to an inbox until it holds `total`. */
function fillInbox(folder, total) {
  mkdirSync(folder, { recursive: true });
  for (let i = readdirSync(folder).length; i < total; i += 1) {
    writeFileSync(`${folder}/seed-${String(i).padStart(5, "0")}.json`, "{}");
  }
  assert.equal(readdirSync(folder).length, total);
}

test("a push to /cpay/ok.php is answered 200 and kept in the private folder", async () => {
  const res = await request(PORT, "POST", "/cpay/ok.php", {
    body: "Details2=TEST-ORDER-1&AmountToPay=100",
    headers: FORM,
  });
  assert.equal(res.status, 200);
  assert.match(res.headers["content-type"], /^text\/html; charset=utf-8/);
  assert.match(res.body, /Payment status received/);
  const saved = inbox("ok");
  assert.equal(saved.length, 1);
  assert.match(saved[0], /^\d{8}-\d{6}-ok-[0-9a-f]{8}\.json$/);
  const record = JSON.parse(readFileSync(`${INBOX}/${saved[0]}`, "utf8"));
  assert.equal(record.kind, "ok");
  assert.match(record.received, /^\d{4}-\d{2}-\d{2}T/);
  assert.deepEqual(record.fields, { Details2: "TEST-ORDER-1", AmountToPay: "100" });
});

test("a saved push is not readable by other users (checked where file modes exist)", async (t) => {
  const [name] = inbox("ok");
  assert.ok(name, "the first test saved a push");
  if (process.platform === "win32") {
    t.diagnostic("file modes are not enforced on Windows; the save itself is asserted above");
    return;
  }
  assert.equal(statSync(`${INBOX}/${name}`).mode & 0o077, 0);
});

test("/cpay/fail.php answers 200 in both languages; a visit without fields saves nothing", async () => {
  const res = await request(PORT, "GET", "/cpay/fail.php");
  assert.equal(res.status, 200);
  assert.match(res.body, /Го примивме статусот на плаќањето/);
  assert.match(res.body, /Payment status received/);
  assert.equal(inbox("fail").length, 0);
});

test("a push over 16 KB is answered 200 and saves nothing", async () => {
  const res = await request(PORT, "POST", "/cpay/fail.php", { body: `Details2=${"a".repeat(20000)}`, headers: FORM });
  assert.equal(res.status, 200);
  assert.match(res.body, /Payment status received/);
  assert.equal(inbox("fail").length, 0);
  assert.match(readFileSync(`${dir}/logs/errors.log`, "utf8"), / cpay_too_large\n/);
});

test("a push with 60 fields keeps 50, and a long value is cut to 1024 characters", async () => {
  const fields = Array.from({ length: 60 }, (_, i) => `f${String(i).padStart(2, "0")}=${i === 0 ? "x".repeat(3000) : "v"}`);
  const known = inbox("ok");
  const res = await request(PORT, "POST", "/cpay/ok.php", { body: fields.join("&"), headers: FORM });
  assert.equal(res.status, 200);
  const added = inbox("ok").filter((file) => !known.includes(file));
  assert.equal(added.length, 1);
  const record = JSON.parse(readFileSync(`${INBOX}/${added[0]}`, "utf8"));
  assert.equal(Object.keys(record.fields).length, 50);
  assert.equal(record.fields.f00.length, 1024);
  assert.equal(record.fields.f50, undefined);
});

test("array values are dropped from a saved push", async () => {
  await request(FLOOD_PORT, "POST", "/cpay/ok.php", { body: "a[]=1&a[]=2&keep=yes", headers: FORM });
  const [name] = files(`${flood.dir}/cpay-inbox`, "ok");
  assert.deepEqual(JSON.parse(readFileSync(`${flood.dir}/cpay-inbox/${name}`, "utf8")).fields, { keep: "yes" });
});

test("the 21st push within the hour from one visitor saves nothing, and still answers 200", async () => {
  const folder = `${flood.dir}/cpay-inbox`;
  const before = files(folder, "ok").length;
  let last;
  for (let i = 0; i < 25; i += 1) {
    last = await request(FLOOD_PORT, "POST", "/cpay/ok.php", { body: `n=${i}`, headers: FORM });
    assert.equal(last.status, 200);
  }
  assert.match(last.body, /Payment status received/);
  assert.equal(before + 19, files(folder, "ok").length, "20 pushes in the hour in all, the first one was the array test");
  assert.match(readFileSync(`${flood.dir}/logs/errors.log`, "utf8"), / cpay_rate_limited\n/);
});

test("pushes from cPay's own address are all kept, past the hourly cap", async () => {
  const folder = `${cpay.dir}/cpay-inbox`;
  for (let i = 0; i < 30; i += 1) {
    const res = await request(CPAY_PORT, "POST", "/cpay/ok.php", { body: `Details2=BATCH${i}`, headers: FORM });
    assert.equal(res.status, 200);
  }
  assert.equal(files(folder, "ok").length, 30, "a batch of card charges sends more than 20 pushes within the hour");
  assert.doesNotMatch(log(cpay.dir), /cpay_rate_limited/);
});

test("an inbox with 500 files takes nothing more from a stranger", async () => {
  fillInbox(INBOX, 500);
  const res = await request(PORT, "POST", "/cpay/ok.php", { body: "Details2=LATE", headers: FORM });
  assert.equal(res.status, 200);
  assert.equal(readdirSync(INBOX).length, 500);
  assert.match(log(dir), / cpay_inbox_full\n/);
});

test("cPay's own pushes are still kept past 500 files, and stop at 5000", async () => {
  const folder = `${cpay.dir}/cpay-inbox`;
  fillInbox(folder, 500);
  await request(CPAY_PORT, "POST", "/cpay/fail.php", { body: "Details2=PAST500", headers: FORM });
  assert.equal(readdirSync(folder).length, 501);
  assert.equal(files(folder, "fail").length, 1);
  assert.doesNotMatch(log(cpay.dir), /cpay_inbox_full/);
  fillInbox(folder, 5000);
  const res = await request(CPAY_PORT, "POST", "/cpay/fail.php", { body: "Details2=PAST5000", headers: FORM });
  assert.equal(res.status, 200);
  assert.equal(readdirSync(folder).length, 5000);
  assert.match(log(cpay.dir), / cpay_inbox_full\n/);
});
