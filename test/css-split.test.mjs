import { test } from "node:test";
import assert from "node:assert/strict";
import { fileFor, sha256, splitCss } from "../scripts/lib/css-split.mjs";

const CSS = [
  "/* ======",
  "   Sections",
  "   ====== */",
  "",
  "/* ---------- Shared media ---------- */",
  ".a { color: red; }",
  "",
  "/* ---------- Hero ---------- */",
  ".b { color: blue; }",
  "",
  "/* ======",
  "   Responsive",
  "   ====== */",
  "@media (min-width: 1px) { .c { color: green; } }",
  "",
].join("\n");

test("splitCss cuts at each marker and joins back exactly", () => {
  const chunks = splitCss(CSS);
  assert.deepEqual(chunks.map((c) => c.title), ["Shared media", "Hero", "Responsive"]);
  assert.ok(chunks[0].text.startsWith("/* ======\n   Sections"));
  assert.ok(chunks[2].text.startsWith("/* ======\n   Responsive"));
  assert.equal(chunks.map((c) => c.text).join(""), CSS);
});

test("splitCss refuses a file without markers", () => {
  assert.throws(() => splitCss(".a{}\n"), /No section markers/);
});

test("fileFor maps every known title and refuses unknown ones", () => {
  assert.equal(fileFor("Shared media"), "01-shared-media.css");
  assert.equal(fileFor("Responsive"), "17-responsive.css");
  assert.throws(() => fileFor("Spa"), /No file name for section "Spa"/);
});

test("sha256 ignores CRLF line endings", () => {
  assert.equal(sha256("a\r\nb\r\n"), sha256("a\nb\n"));
});
