import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";

/* Runs the plain-PHP unit tests (test/php/*.test.php) as part of npm test. */
const PHP = process.env.PHP_BIN || "php";

test("PHP unit tests pass", () => {
  const run = spawnSync(PHP, ["test/php/run.php"], { encoding: "utf8" });
  assert.equal(run.error, undefined, `Cannot start ${PHP}: ${run.error?.message}. Install PHP 8 (Task 25) or set PHP_BIN.`);
  assert.equal(run.status, 0, run.stdout + run.stderr);
});
