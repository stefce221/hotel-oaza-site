import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { contactUrl, otherLang, pageKeys, pageUrl } from "../config/pages.js";

test("pageUrl gives each page's address per language", () => {
  assert.equal(pageUrl("home", "mk"), "/");
  assert.equal(pageUrl("home", "en"), "/en/");
  assert.equal(pageUrl("weddings", "mk"), "/svadbi/");
  assert.equal(pageUrl("conferences", "en"), "/en/conferences/");
  assert.equal(pageUrl("payments", "mk"), "/plakjanje/");
});

test("pageUrl refuses unknown pages and languages", () => {
  assert.throws(() => pageUrl("spa", "mk"), /Unknown page "spa"/);
  assert.throws(() => pageUrl("home", "de"), /No de URL for page "home"/);
});

test("all 11 pages have an MK address and an EN one under /en/", () => {
  assert.equal(pageKeys().length, 11);
  for (const key of pageKeys()) {
    assert.ok(pageUrl(key, "en").startsWith("/en/"), key);
    assert.ok(!pageUrl(key, "mk").startsWith("/en/"), key);
  }
});

test("otherLang swaps mk and en", () => {
  assert.equal(otherLang("mk"), "en");
  assert.equal(otherLang("en"), "mk");
});

test("contactUrl adds only the values it is given", () => {
  assert.equal(contactUrl("mk"), "/kontakt/");
  assert.equal(contactUrl("en", { type: "stay" }), "/en/contact/?type=stay");
  assert.equal(contactUrl("mk", { type: "stay", room: "twin" }), "/kontakt/?type=stay&room=twin");
  assert.equal(contactUrl("mk", { type: "stay", room: undefined }), "/kontakt/?type=stay");
});

const PAGES = JSON.parse(readFileSync(new URL("../src/_data/pages.json", import.meta.url), "utf8"));

test("every page address is a clean root-relative path with a trailing slash", () => {
  for (const key of pageKeys()) {
    for (const lang of ["mk", "en"]) assert.match(pageUrl(key, lang), /^\/(?:[a-z0-9-]+\/)*$/, `${key} (${lang})`);
  }
});

test("no two pages share an address", () => {
  const urls = pageKeys().flatMap((key) => ["mk", "en"].map((lang) => pageUrl(key, lang)));
  assert.equal(new Set(urls).size, urls.length);
});

test("every page named in a menu list exists", () => {
  const menus = Object.entries(PAGES).filter(([name]) => name !== "urls");
  for (const name of ["nav", "explore", "legal"]) assert.ok(name in PAGES, `pages.json has a "${name}" menu`);
  for (const [name, keys] of menus) {
    assert.ok(Array.isArray(keys) && keys.length > 0, `${name} is a list`);
    assert.equal(new Set(keys).size, keys.length, `${name} lists a page twice`);
    for (const key of keys) assert.ok(pageKeys().includes(key), `${name}: unknown page "${key}"`);
  }
});

test("contactUrl encodes values so they survive a round trip", () => {
  assert.equal(contactUrl("en", { guests: "10+" }), "/en/contact/?guests=10%2B");
  const url = new URL(contactUrl("en", { guests: "10+" }), "http://x");
  assert.equal(url.pathname, "/en/contact/");
  assert.equal(url.searchParams.get("guests"), "10+");
});
