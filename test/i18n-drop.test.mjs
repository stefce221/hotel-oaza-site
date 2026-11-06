import { test } from "node:test";
import assert from "node:assert/strict";
import { dropKeys } from "../scripts/i18n-drop.mjs";

test("dropKeys removes exact keys and prefix.* groups, and reports what it removed", () => {
  const { table, removed } = dropKeys({ "a.b": "1", "mail.x": "2", "mail.y": "3", c: "4" }, ["a.b", "mail.*"]);
  assert.deepEqual(table, { c: "4" });
  assert.deepEqual(removed, ["a.b", "mail.x", "mail.y"]);
});

test("dropKeys leaves the input object untouched", () => {
  const input = { a: "1", b: "2" };
  dropKeys(input, ["a"]);
  assert.deepEqual(input, { a: "1", b: "2" });
});
