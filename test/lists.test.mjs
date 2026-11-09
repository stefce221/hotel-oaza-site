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

test("inOrder explains what is missing when the list of keys is not an array", () => {
  assert.throws(() => inOrder(HALLS, undefined), /inOrder needs a list of keys/);
  assert.throws(() => inOrder(HALLS, "grand"), /inOrder needs a list of keys/);
});

test("inOrder refuses a key that does not exist", () => {
  assert.throws(() => inOrder(HALLS, ["ballroom"]), /Unknown entry "ballroom"/);
});
