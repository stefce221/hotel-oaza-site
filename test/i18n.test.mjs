import { test } from "node:test";
import assert from "node:assert/strict";
import { mkdirSync, mkdtempSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import vm from "node:vm";
import {
  createTranslator,
  escapeAttr,
  guestOptionsHtml,
  langFromUrl,
  loadDictionaries,
  loadRuntime,
  missingKeys,
  runtimeScript,
} from "../config/i18n.js";

function fixture(files) {
  const dir = mkdtempSync(path.join(tmpdir(), "oaza-i18n-"));
  for (const [rel, data] of Object.entries(files)) {
    const file = path.join(dir, rel);
    mkdirSync(path.dirname(file), { recursive: true });
    writeFileSync(file, JSON.stringify(data));
  }
  return dir;
}

test("loadDictionaries merges every file of a language", () => {
  const dir = fixture({ "mk/a.json": { x: "Х" }, "mk/b.json": { y: "У" }, "en/a.json": { x: "X", y: "Y" } });
  assert.deepEqual(loadDictionaries(dir), { mk: { x: "Х", y: "У" }, en: { x: "X", y: "Y" } });
});

test("loadDictionaries refuses a key defined twice", () => {
  const dir = fixture({ "mk/a.json": { x: "1" }, "mk/b.json": { x: "2" }, "en/a.json": {} });
  assert.throws(() => loadDictionaries(dir), /Duplicate key "x" in mk\/b\.json/);
});

test("loadRuntime merges runtime files per language", () => {
  const dir = fixture({
    "runtime/a.json": { en: { a: "A" }, mk: { a: "А" } },
    "runtime/b.json": { en: { b: "B" }, mk: { b: "Б" } },
  });
  assert.deepEqual(loadRuntime(dir), { en: { a: "A", b: "B" }, mk: { a: "А", b: "Б" } });
});

test("the translator returns text and fails loudly on a missing key", () => {
  const t = createTranslator({ mk: { hi: "Здраво" }, en: { hi: "Hello" } });
  assert.equal(t("mk", "hi"), "Здраво");
  assert.equal(t("en", "hi"), "Hello");
  assert.throws(() => t("en", "bye"), /Missing en text for "bye"/);
  assert.throws(() => t("de", "hi"), /Unknown language "de"/);
});

test("missingKeys lists keys that exist in only one language", () => {
  assert.deepEqual(missingKeys({ mk: { a: "1", b: "2" }, en: { a: "1", c: "3" } }), ["en: b", "mk: c"]);
  assert.deepEqual(missingKeys({ mk: { a: "1" }, en: { a: "1" } }), []);
});

test("langFromUrl reads the language from the page URL", () => {
  assert.equal(langFromUrl("/en/"), "en");
  assert.equal(langFromUrl("/en/rooms/"), "en");
  assert.equal(langFromUrl("/"), "mk");
  assert.equal(langFromUrl("/sobi/"), "mk");
  assert.equal(langFromUrl("/404.html"), "mk");
  assert.equal(langFromUrl(false), "mk");
});

test("escapeAttr escapes quotes and brackets but keeps existing entities", () => {
  assert.equal(
    escapeAttr('Say "hi" <b> & go &amp; &#8212; &mdash;'),
    "Say &quot;hi&quot; &lt;b&gt; &amp; go &amp; &#8212; &mdash;",
  );
});

test("runtimeScript defines window.oazaI18n with lang, t() and onChange()", () => {
  const sandbox = { window: {} };
  vm.runInNewContext(runtimeScript({ "nights.many": "{n} nights" }, "en"), sandbox);
  const i18n = sandbox.window.oazaI18n;
  assert.equal(i18n.lang, "en");
  assert.equal(i18n.t("nights.many", { n: 3 }), "3 nights");
  assert.equal(i18n.t("unknown.key"), "unknown.key");
  assert.equal(typeof i18n.onChange, "function");
});

test("runtimeScript cannot close its own script tag", () => {
  assert.doesNotMatch(runtimeScript({ x: "</script><b>" }, "en"), /<\/script>/);
});

test("guestOptionsHtml labels one guest, many guests and 10+", () => {
  const html = guestOptionsHtml({ "guest.one": "{n} guest", "guest.many": "{n} guests", "guest.more": "{n} guests" }, "2");
  assert.match(html, /<option value="1">1 guest<\/option>/);
  assert.match(html, /<option value="2" selected>2 guests<\/option>/);
  assert.match(html, /<option value="10\+">10\+ guests<\/option>/);
  assert.equal(html.match(/<option/g).length, 10);
});
