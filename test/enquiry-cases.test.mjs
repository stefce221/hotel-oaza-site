import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import vm from "node:vm";

/* The same cases run against src/api/lib/validate.php in test/php/validate.test.php. */
const sandbox = { window: {}, URLSearchParams };
vm.runInNewContext(readFileSync("src/assets/js/enquiry-rules.js", "utf8"), sandbox);
const rules = sandbox.window.oazaEnquiryRules;
const fixture = JSON.parse(readFileSync("test/fixtures/enquiry-cases.json", "utf8"));

/** {"repeat": "a", "times": 3, "then": "@x"} → "aaa@x"; plain strings pass through. */
const expand = (value) => (typeof value === "string" ? value : value.repeat.repeat(value.times) + (value.then ?? ""));

for (const { name, change, errors } of fixture.cases) {
  test(`browser rules: ${name}`, () => {
    const values = { ...fixture.base, ...Object.fromEntries(Object.entries(change).map(([key, value]) => [key, expand(value)])) };
    assert.deepEqual(JSON.parse(JSON.stringify(rules.validate(values, fixture.today))), errors);
  });
}
