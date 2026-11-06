import { test } from "node:test";
import assert from "node:assert/strict";
import { mkdirSync, mkdtempSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { assetUrl, bundleCss, contentHash, cssFiles } from "../config/assets.js";

function tree(files) {
  const root = mkdtempSync(path.join(tmpdir(), "oaza-assets-"));
  for (const [rel, text] of Object.entries(files)) {
    mkdirSync(path.dirname(path.join(root, rel)), { recursive: true });
    writeFileSync(path.join(root, rel), text);
  }
  return root;
}

const CSS = {
  "tokens.css": "/*t*/",
  "base.css": "/*b*/",
  "sections/02-b.css": "/*2*/",
  "sections/01-a.css": "/*1*/",
  "fx.css": "/*f*/",
  "additions/z.css": "/*z*/",
};

test("cssFiles orders tokens, base, sections, fx, additions", () => {
  const root = tree(CSS);
  const rel = cssFiles(root).map((f) => path.relative(root, f).split(path.sep).join("/"));
  assert.deepEqual(rel, ["tokens.css", "base.css", "sections/01-a.css", "sections/02-b.css", "fx.css", "additions/z.css"]);
});

test("bundleCss joins the files in that order", () => {
  assert.equal(bundleCss(tree(CSS)), "/*t*/\n/*b*/\n/*1*/\n/*2*/\n/*f*/\n/*z*/");
});

test("a missing additions folder is fine", () => {
  const { "additions/z.css": _unused, ...rest } = CSS;
  assert.equal(bundleCss(tree(rest)), "/*t*/\n/*b*/\n/*1*/\n/*2*/\n/*f*/");
});

test("@import is refused because a bundle cannot keep it", () => {
  assert.throws(() => bundleCss(tree({ ...CSS, "additions/z.css": "@import url(x.css);" })), /@import/);
});

test("contentHash is short and follows the content", () => {
  assert.match(contentHash("a"), /^[0-9a-f]{10}$/);
  assert.equal(contentHash("a"), contentHash("a"));
  assert.notEqual(contentHash("a"), contentHash("b"));
});

test("assetUrl appends the file's hash, and the bundle's hash for site.css", () => {
  const root = tree({ "assets/js/x.js": "x", ...Object.fromEntries(Object.entries(CSS).map(([k, v]) => [`assets/css/${k}`, v])) });
  assert.equal(assetUrl("/assets/js/x.js", root), `/assets/js/x.js?v=${contentHash("x")}`);
  assert.equal(assetUrl("/assets/css/site.css", root), `/assets/css/site.css?v=${contentHash(bundleCss(path.join(root, "assets/css")))}`);
});
