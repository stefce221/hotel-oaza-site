import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";

/* Runs the plain-PHP unit tests (test/php/*.test.php) as part of npm test. */
const PHP = process.env.PHP_BIN || "php";
/* Test files for the runner itself, one folder per case; the PHP suite never reads them (run.php reads test/php). */
const FIXTURES = "test/fixtures/php-runner";

/** Runs run.php on the test files of one fixture folder; display_errors=stderr so an uncaught error is always shown. */
function runFixture(folder) {
  return spawnSync(PHP, ["-d", "display_errors=stderr", "test/php/run.php"], {
    encoding: "utf8",
    env: { ...process.env, OAZA_PHP_TESTS: `${FIXTURES}/${folder}` },
  });
}

test("PHP unit tests pass", () => {
  const run = spawnSync(PHP, ["test/php/run.php"], { encoding: "utf8", env: { ...process.env, OAZA_PHP_TESTS: "" } });
  assert.equal(run.error, undefined, `Cannot start ${PHP}: ${run.error?.message}. Install PHP 8 (Task 25) or set PHP_BIN.`);
  assert.equal(run.status, 0, run.stdout + run.stderr);
});

test("the PHP runner counts a skipped test in its summary and still ends with success", () => {
  const run = runFixture("skips");
  assert.equal(run.status, 0, run.stdout + run.stderr);
  assert.match(run.stdout, /^ok - fixture: a test that passes$/m);
  assert.match(run.stdout, /^ok - fixture: a test that cannot run here # skipped: the fixture's reason$/m);
  assert.match(run.stdout, /\n2 tests, 0 failed, 1 skipped\n$/);
});

test("the PHP runner stops at a second test of the same name", () => {
  const run = runFixture("duplicate");
  assert.notEqual(run.status, 0, "the run fails");
  assert.match(run.stdout + run.stderr, /LogicException: two tests are named "fixture: one name": the second would replace the first/);
  assert.doesNotMatch(run.stdout, /tests, \d+ failed/, "no test ran");
});
