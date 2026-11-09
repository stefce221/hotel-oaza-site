import { test } from "node:test";
import assert from "node:assert/strict";
import { inOrder } from "../config/lists.js";

const HALLS = { small: { capacity: "20–30" }, seminar: { capacity: "80–150" }, grand: { capacity: "250" } };

test("inOrder returns the entries in the requested order, with their key", () => {
  assert.deepEqual(inOrder(HALLS, ["grand", "small"]), [
    { key: "grand", capacity: "250" },
    { key: "small", capacity: "20–30" },
  ]);
});

test("inOrder refuses a key that does not exist", () => {
  assert.throws(() => inOrder(HALLS, ["ballroom"]), /Unknown entry "ballroom"/);
});
