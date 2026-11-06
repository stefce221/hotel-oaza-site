import { test } from "node:test";
import assert from "node:assert/strict";
import { loadSite, telHref, viberHref, whatsappHref } from "../config/contact.js";

const site = loadSite();
const wa = (text) => `https://wa.me/38970310300?text=${encodeURIComponent(text)}`;

test("call and Viber links use +389 70 310 300", () => {
  assert.equal(telHref(site), "tel:+38970310300");
  assert.equal(viberHref(site), "viber://chat?number=%2B38970310300");
});

test("the WhatsApp message follows the page", () => {
  assert.equal(whatsappHref(site, "weddings", "mk"), wa("Здраво, ве контактирам за свадба или прослава."));
  assert.equal(whatsappHref(site, "conferences", "en"), wa("Hello, I'm getting in touch about a seminar or conference."));
  assert.equal(whatsappHref(site, "rooms", "mk"), wa("Здраво, би сакал/а да резервирам соба."));
  assert.equal(whatsappHref(site, "home", "en"), wa("Hello, I'd like to book a room."));
  assert.equal(whatsappHref(site, "stip", "en"), wa("Hello, I have a question for Hotel Oaza."));
  assert.equal(whatsappHref(site, "404", "mk"), wa("Здраво, имам прашање за Хотел Оаза."));
});

test("the operator lines carry the company and its tax number", () => {
  assert.match(site.operator.mk, /ЕДБ 4029018520675/);
  assert.match(site.operator.en, /Tax no\. 4029018520675/);
});
