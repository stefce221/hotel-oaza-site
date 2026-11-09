import { after, before, test } from "node:test";
import assert from "node:assert/strict";
import { existsSync, readdirSync, readFileSync, rmSync, statSync } from "node:fs";
import { makePrivateDir, request, startPhp } from "./helpers/php.mjs";

/* /cpay/ok.php and /cpay/fail.php are registered with CaSys; until the cPay project they keep each push. */
const PORT = 8766;
const FLOOD_PORT = 8767;
const FORM = { "Content-Type": "application/x-www-form-urlencoded" };
const { dir, config } = makePrivateDir();
const flood = makePrivateDir();
const INBOX = `${dir}/cpay-inbox`;
const stops = [];

before(async () => {
  stops.push(await startPhp(PORT, config), await startPhp(FLOOD_PORT, flood.config));
});
after(async () => {
  await Promise.all(stops.map((stop) => stop()));
  rmSync(dir, { recursive: true, force: true });
  rmSync(flood.dir, { recursive: true, force: true });
});

const files = (folder, kind) => (existsSync(folder) ? readdirSync(folder).filter((name) => name.includes(`-${kind}-`)) : []);
const inbox = (kind) => files(INBOX, kind);

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
