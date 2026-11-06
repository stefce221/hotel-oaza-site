/* Hotel Oaza — phone, Viber and WhatsApp links from src/_data/site.json (also global data `site`). */
import { readFileSync } from "node:fs";

const E164 = /^\+[1-9]\d{7,14}$/;

/** Read site.json; the phone number must be in E.164 form (+, then 8 to 15 digits) so the links work. */
export function loadSite(file = new URL("../src/_data/site.json", import.meta.url)) {
  const site = JSON.parse(readFileSync(file, "utf8"));
  const e164 = site.phone?.e164;
  if (typeof e164 !== "string" || !E164.test(e164)) {
    throw new Error(`site.json: phone.e164 must be an E.164 number such as +38970310300, got ${JSON.stringify(e164)}`);
  }
  return site;
}

export function telHref(site) {
  return `tel:${site.phone.e164}`;
}

/** Opens a Viber chat; Viber has no reliable direct-call link. */
export function viberHref(site) {
  return `viber://chat?number=${encodeURIComponent(site.phone.e164)}`;
}

/** wa.me chat with the ready message for this page: weddings, conferences, stay (home, rooms) or default. */
export function whatsappHref(site, pageKey, lang) {
  const group = site.whatsapp.groups[pageKey] ?? "default";
  const text = site.whatsapp.messages[group]?.[lang];
  if (!text) throw new Error(`No ${lang} WhatsApp message for "${group}"`);
  return `https://wa.me/${site.phone.e164.replace(/^\+/, "")}?text=${encodeURIComponent(text)}`;
}
