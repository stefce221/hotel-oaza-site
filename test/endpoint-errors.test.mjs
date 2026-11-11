import { after, before, test } from "node:test";
import assert from "node:assert/strict";
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { request, startPhp } from "./helpers/php.mjs";

/* A config.php with a syntax error: PHP's ParseError quotes the offending token, which could be part of a password. */
const PORT = 8768;
const SECRET = "SECRETFRAGMENT";
const dir = mkdtempSync(path.join(tmpdir(), "oaza-php-")).replaceAll("\\", "/");
const config = `${dir}/config.php`;
const phpLog = `${dir}/php-errors.log`;
let stop;

before(async () => {
  writeFileSync(config, `<?php\nreturn ['rate' => ['salt' => 'x' '${SECRET}']];\n`);
  stop = await startPhp(PORT, config, { errorLog: phpLog });
});
after(async () => {
  await stop?.();
  rmSync(dir, { recursive: true, force: true });
});

const FORM = { "Content-Type": "application/x-www-form-urlencoded", Origin: `http://127.0.0.1:${PORT}` };

test("a config that does not parse answers send_failed and the log keeps no fragment of it", async () => {
  const res = await request(PORT, "POST", "/api/enquiry.php", { body: "name=x", headers: FORM });
  assert.equal(res.status, 500);
  assert.deepEqual(JSON.parse(res.body), { success: false, data: null, error: { code: "send_failed" } });
  const log = readFileSync(phpLog, "utf8");
  assert.match(log, /ParseError on line 2/);
  assert.doesNotMatch(log, new RegExp(SECRET));
});

/* Fields that are not a notice of cPay's; a notice gets 500 here, so that cPay tries again (cpay-notice.test.mjs). */
test("the cPay addresses log a broken config the same way and still answer 200 to what is not a notice", async () => {
  const res = await request(PORT, "POST", "/cpay/ok.php", { body: "a=1", headers: { "Content-Type": "application/x-www-form-urlencoded" } });
  assert.equal(res.status, 200);
  assert.match(res.body, /Payment status received/);
  assert.doesNotMatch(readFileSync(phpLog, "utf8"), new RegExp(SECRET));
});
