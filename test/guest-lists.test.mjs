import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import vm from "node:vm";
import { GUEST_VALUES } from "../config/i18n.js";

/* The rules script is a browser IIFE that sets window.oazaEnquiryRules; run it in a sandbox. */
const sandbox = { window: {}, URLSearchParams };
vm.runInNewContext(readFileSync("src/assets/js/enquiry-rules.js", "utf8"), sandbox);
const rules = sandbox.window.oazaEnquiryRules;

test("the guest <option> values built into the pages match the browser rules' GUESTS", () => {
  assert.deepEqual([...GUEST_VALUES], JSON.parse(JSON.stringify(rules.GUESTS)));
});
