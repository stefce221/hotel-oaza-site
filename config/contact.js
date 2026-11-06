/* Hotel Oaza — phone, Viber and WhatsApp links from src/_data/site.json (also global data `site`). */
import { readFileSync } from "node:fs";

export function loadSite(file = new URL("../src/_data/site.json", import.meta.url)) {
  return JSON.parse(readFileSync(file, "utf8"));
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
  return `https://wa.me/${site.phone.e164.slice(1)}?text=${encodeURIComponent(text)}`;
}
