import { test } from "node:test";
import assert from "node:assert/strict";
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
