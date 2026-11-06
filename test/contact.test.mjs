import { after, test } from "node:test";
import assert from "node:assert/strict";
import { mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { loadSite, telHref, viberHref, whatsappHref } from "../config/contact.js";
import { pageKeys } from "../config/pages.js";

const site = loadSite();
const wa = (text) => `https://wa.me/38970310300?text=${encodeURIComponent(text)}`;
const tempDirs = [];

after(() => {
  for (const dir of tempDirs) rmSync(dir, { recursive: true, force: true });
});

/** Write a site.json that is the real one with `phone` replaced, and return its path. */
function siteFileWithPhone(phone) {
  const dir = mkdtempSync(path.join(tmpdir(), "oaza-site-"));
  tempDirs.push(dir);
  const file = path.join(dir, "site.json");
  writeFileSync(file, JSON.stringify({ ...site, phone }));
  return file;
}

test("call and Viber links use +389 70 310 300", () => {
  assert.equal(telHref(site), "tel:+38970310300");
  assert.equal(viberHref(site), "viber://chat?number=%2B38970310300");
});

test("the phone number shown on the page agrees with the one that is dialled", () => {
  const { e164, display, local } = site.phone;
  assert.ok(e164.startsWith("+389"), "a North Macedonian number");
  assert.equal(display.replace(/\s/g, ""), e164);
  assert.equal(local.replace(/\s/g, ""), "0" + e164.slice("+389".length));
});

test("the WhatsApp message follows the page", () => {
  assert.equal(whatsappHref(site, "weddings", "mk"), wa("Здраво, ве контактирам за свадба или прослава."));
  assert.equal(whatsappHref(site, "conferences", "en"), wa("Hello, I'm getting in touch about a seminar or conference."));
  assert.equal(whatsappHref(site, "rooms", "mk"), wa("Здраво, би сакал/а да резервирам соба."));
  assert.equal(whatsappHref(site, "home", "en"), wa("Hello, I'd like to book a room."));
  assert.equal(whatsappHref(site, "stip", "en"), wa("Hello, I have a question for Hotel Oaza."));
  assert.equal(whatsappHref(site, "404", "mk"), wa("Здраво, имам прашање за Хотел Оаза."));
});

test("every page and language opens a wa.me chat with the message of its group", () => {
  const { groups, messages } = site.whatsapp;
  const reached = new Set();
  for (const pageKey of [...pageKeys(), "404"]) {
    const group = groups[pageKey] ?? "default";
    reached.add(group);
    for (const lang of ["mk", "en"]) {
      const url = new URL(whatsappHref(site, pageKey, lang));
      assert.equal(url.origin, "https://wa.me", `${pageKey} (${lang})`);
      assert.equal(url.pathname, "/38970310300", `${pageKey} (${lang})`);
      assert.equal(url.searchParams.get("text"), messages[group][lang], `${pageKey} (${lang})`);
    }
  }
  assert.deepEqual([...reached].sort(), Object.keys(messages).sort());
});

test("the operator lines carry the company and its tax number", () => {
  assert.match(site.operator.mk, /ЕДБ 4029018520675/);
  assert.match(site.operator.en, /Tax no\. 4029018520675/);
});

test("loadSite refuses a phone number that is not in E.164 form", () => {
  const bad = ["38970310300", "+0123456789", "+389 70 310 300", "+1234567", "+1234567890123456", "tel:+38970310300", ""];
  for (const e164 of bad) {
    const file = siteFileWithPhone({ ...site.phone, e164 });
    assert.throws(() => loadSite(file), /E\.164/, JSON.stringify(e164));
  }
  assert.throws(() => loadSite(siteFileWithPhone({ display: "x", local: "y" })), /E\.164/);
  assert.throws(() => loadSite(siteFileWithPhone({ ...site.phone, e164: 38970310300 })), /E\.164/);
});

test("loadSite accepts the shortest and longest E.164 numbers", () => {
  for (const e164 of ["+12345678", "+123456789012345"]) {
    assert.equal(loadSite(siteFileWithPhone({ ...site.phone, e164 })).phone.e164, e164);
  }
});
