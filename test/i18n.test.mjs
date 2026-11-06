import { after, test } from "node:test";
import assert from "node:assert/strict";
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
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

const tempDirs = [];

after(() => {
  for (const dir of tempDirs) rmSync(dir, { recursive: true, force: true });
});

/** Write `files` ({ "mk/a.json": data }) into a temp dir; a string value is written as-is, anything else as JSON. */
function fixture(files) {
  const dir = mkdtempSync(path.join(tmpdir(), "oaza-i18n-"));
  tempDirs.push(dir);
  for (const [rel, data] of Object.entries(files)) {
    const file = path.join(dir, rel);
    mkdirSync(path.dirname(file), { recursive: true });
    writeFileSync(file, typeof data === "string" ? data : JSON.stringify(data));
  }
  return dir;
}

/** Run the generated script in a fresh context and hand back window.oazaI18n. */
function runtimeApi(strings, lang) {
  const sandbox = { window: {} };
  vm.runInNewContext(runtimeScript(strings, lang), sandbox);
  return sandbox.window.oazaI18n;
}

test("loadDictionaries merges every file of a language", () => {
  const dir = fixture({ "mk/a.json": { x: "Х" }, "mk/b.json": { y: "У" }, "en/a.json": { x: "X", y: "Y" } });
  assert.deepEqual(loadDictionaries(dir), { mk: { x: "Х", y: "У" }, en: { x: "X", y: "Y" } });
});

test("loadDictionaries refuses a key defined twice", () => {
  const dir = fixture({ "mk/a.json": { x: "1" }, "mk/b.json": { x: "2" }, "en/a.json": {} });
  assert.throws(() => loadDictionaries(dir), /Duplicate key "x" in mk\/b\.json/);
});

test("loadDictionaries reads a file that starts with a byte order mark", () => {
  const dir = fixture({ "mk/a.json": "\uFEFF" + JSON.stringify({ x: "Х" }), "en/a.json": { x: "X" } });
  assert.deepEqual(loadDictionaries(dir), { mk: { x: "Х" }, en: { x: "X" } });
});

test("a dictionary file that is empty or not valid JSON is reported by name", () => {
  const empty = fixture({ "mk/a.json": "", "en/a.json": {} });
  assert.throws(() => loadDictionaries(empty), /^Error: mk\/a\.json: /);
  const broken = fixture({ "mk/a.json": {}, "en/a.json": '{"x": ' });
  assert.throws(() => loadDictionaries(broken), /^Error: en\/a\.json: /);
});

test("a dictionary file must be an object whose values are strings", () => {
  const list = fixture({ "mk/a.json": ["x"], "en/a.json": {} });
  assert.throws(() => loadDictionaries(list), /mk\/a\.json: .*object/);
  const nothing = fixture({ "mk/a.json": "null", "en/a.json": {} });
  assert.throws(() => loadDictionaries(nothing), /mk\/a\.json: .*object/);
  const nested = fixture({ "mk/a.json": { nav: { home: "Дома" } }, "en/a.json": {} });
  assert.throws(() => loadDictionaries(nested), /mk\/a\.json: "nav" must be a string/);
  const number = fixture({ "mk/a.json": {}, "en/a.json": { year: 2026 } });
  assert.throws(() => loadDictionaries(number), /en\/a\.json: "year" must be a string/);
});

test("loadRuntime merges runtime files per language", () => {
  const dir = fixture({
    "runtime/a.json": { en: { a: "A" }, mk: { a: "А" } },
    "runtime/b.json": { en: { b: "B" }, mk: { b: "Б" } },
  });
  assert.deepEqual(loadRuntime(dir), { en: { a: "A", b: "B" }, mk: { a: "А", b: "Б" } });
});

test("loadRuntime accepts a file that has only one language", () => {
  const dir = fixture({ "runtime/a.json": { en: { a: "A" } } });
  assert.deepEqual(loadRuntime(dir), { en: { a: "A" }, mk: {} });
});

test("loadRuntime rejects a value that is not a string, naming the file and key", () => {
  const dir = fixture({ "runtime/a.json": { en: { a: 1 }, mk: {} } });
  assert.throws(() => loadRuntime(dir), /runtime\/a\.json \(en\): "a" must be a string/);
});

test("loadRuntime rejects a language it does not know and a file that is not an object", () => {
  const typo = fixture({ "runtime/a.json": { en: { a: "A" }, mkk: { a: "А" } } });
  assert.throws(() => loadRuntime(typo), /runtime\/a\.json: unknown language "mkk"/);
  const list = fixture({ "runtime/a.json": [] });
  assert.throws(() => loadRuntime(list), /runtime\/a\.json: .*object/);
  const empty = fixture({ "runtime/a.json": "" });
  assert.throws(() => loadRuntime(empty), /^Error: runtime\/a\.json: /);
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

test("escapeAttr keeps hex entities in either case and accepts non-string input", () => {
  assert.equal(escapeAttr("a &#x2014; b &#X2014; c & d"), "a &#x2014; b &#X2014; c &amp; d");
  assert.equal(escapeAttr(5), "5");
});

test("runtimeScript defines window.oazaI18n with lang, t() and onChange()", () => {
  const i18n = runtimeApi({ "nights.many": "{n} nights" }, "en");
  assert.equal(i18n.lang, "en");
  assert.equal(i18n.t("nights.many", { n: 3 }), "3 nights");
  assert.equal(i18n.t("unknown.key"), "unknown.key");
  assert.equal(typeof i18n.onChange, "function");
});

test("t() replaces every occurrence of a placeholder", () => {
  const i18n = runtimeApi({ twice: "{n} and {n}" }, "en");
  assert.equal(i18n.t("twice", { n: 1 }), "1 and 1");
});

test("runtimeScript cannot close its own script tag", () => {
  const strings = { x: "</script><b>", y: "<!-- a -->" };
  assert.doesNotMatch(runtimeScript(strings, "en"), /<\/script>/);
  const i18n = runtimeApi(strings, "en");
  assert.equal(i18n.t("x"), "</script><b>");
  assert.equal(i18n.t("y"), "<!-- a -->");
});

test("runtimeScript refuses a language that does not exist", () => {
  assert.throws(() => runtimeScript({ x: "X" }, "de"), /Unknown language "de"/);
  assert.throws(() => runtimeScript({ x: "X" }, undefined), /Unknown language/);
});

const GUEST_STRINGS = { "guest.one": "{n} guest", "guest.many": "{n} guests", "guest.more": "{n} or more" };

test("guestOptionsHtml labels one guest, many guests and 10+", () => {
  const html = guestOptionsHtml(GUEST_STRINGS, "2");
  assert.match(html, /<option value="1">1 guest<\/option>/);
  assert.match(html, /<option value="2" selected>2 guests<\/option>/);
  assert.match(html, /<option value="9">9 guests<\/option>/);
  assert.match(html, /<option value="10\+">10\+ or more<\/option>/);
  assert.equal(html.match(/<option/g).length, 10);
});

test("guestOptionsHtml selects the same option for 2 and \"2\"", () => {
  assert.equal(guestOptionsHtml(GUEST_STRINGS, 2), guestOptionsHtml(GUEST_STRINGS, "2"));
  assert.match(guestOptionsHtml(GUEST_STRINGS, 2), /<option value="2" selected>/);
  assert.equal(guestOptionsHtml(GUEST_STRINGS, 2).match(/selected/g).length, 1);
});

test("guestOptionsHtml names the string it is missing", () => {
  assert.throws(() => guestOptionsHtml({ "guest.one": "{n} guest", "guest.many": "{n} guests" }, "2"), /"guest\.more"/);
  assert.throws(() => guestOptionsHtml({ "guest.many": "{n} guests", "guest.more": "{n}+" }, "2"), /"guest\.one"/);
});
