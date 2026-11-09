import { after, before, test } from "node:test";
import assert from "node:assert/strict";
import { existsSync, readdirSync, readFileSync, rmSync } from "node:fs";
import { makePrivateDir, request, startPhp } from "./helpers/php.mjs";

/* /cpay/ok.php and /cpay/fail.php are registered with CaSys; until the cPay project they keep each push. */
const PORT = 8766;
const { dir, config } = makePrivateDir();
const INBOX = `${dir}/cpay-inbox`;
let stop;

before(async () => {
  stop = await startPhp(PORT, config);
});
after(async () => {
  await stop?.();
  rmSync(dir, { recursive: true, force: true });
});

const inbox = (kind) => (existsSync(INBOX) ? readdirSync(INBOX).filter((name) => name.includes(`-${kind}-`)) : []);

test("a push to /cpay/ok.php is answered 200 and kept in the private folder", async () => {
  const res = await request(PORT, "POST", "/cpay/ok.php", {
    body: "Details2=TEST-ORDER-1&AmountToPay=100",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
  });
  assert.equal(res.status, 200);
  assert.match(res.headers["content-type"], /^text\/html; charset=utf-8/);
  assert.match(res.body, /Payment status received/);
  const files = inbox("ok");
  assert.equal(files.length, 1);
  assert.match(files[0], /^\d{8}-\d{6}-ok-[0-9a-f]{8}\.json$/);
  const saved = JSON.parse(readFileSync(`${INBOX}/${files[0]}`, "utf8"));
  assert.equal(saved.kind, "ok");
  assert.match(saved.received, /^\d{4}-\d{2}-\d{2}T/);
  assert.deepEqual(saved.fields, { Details2: "TEST-ORDER-1", AmountToPay: "100" });
});

test("/cpay/fail.php answers 200 in both languages; a visit without fields saves nothing", async () => {
  const res = await request(PORT, "GET", "/cpay/fail.php");
  assert.equal(res.status, 200);
  assert.match(res.body, /Го примивме статусот на плаќањето/);
  assert.match(res.body, /Payment status received/);
  assert.equal(inbox("fail").length, 0);
});
