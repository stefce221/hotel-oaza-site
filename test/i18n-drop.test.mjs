import { test } from "node:test";
import assert from "node:assert/strict";
import { dropKeys, planDrop } from "../scripts/i18n-drop.mjs";

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

test('"mail.*" does not drop a key that only starts with the same letters', () => {
  const { table, removed } = dropKeys({ mailer: "1", "mail.x": "2" }, ["mail.*"]);
  assert.deepEqual(removed, ["mail.x"]);
  assert.deepEqual(table, { mailer: "1" });
});

test("an exact key does not drop its children", () => {
  const { table, removed } = dropKeys({ a: "1", "a.b": "2" }, ["a"]);
  assert.deepEqual(removed, ["a"]);
  assert.deepEqual(table, { "a.b": "2" });
});

test("planDrop reports a pattern that matches nothing instead of returning tables to write", () => {
  const tables = { en: { a: "1", b: "2" }, mk: { a: "1" } };
  assert.throws(() => planDrop(tables, ["b"]), /mk: no key matches b/);
  assert.throws(() => planDrop({ en: { a: "1" } }, ["zzz.*"]), /no key matches zzz\.\*/);
});

test("planDrop returns both new tables when every pattern matches", () => {
  const plan = planDrop({ en: { a: "1", b: "2" }, mk: { a: "3", b: "4" } }, ["a"]);
  assert.deepEqual(plan.tables, { en: { b: "2" }, mk: { b: "4" } });
});

test("importing the module without argv[1] does not throw", async () => {
  const saved = process.argv[1];
  process.argv[1] = undefined;
  try {
    await import("../scripts/i18n-drop.mjs?no-argv");
  } finally {
    process.argv[1] = saved;
  }
});
