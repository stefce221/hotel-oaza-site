/* Hotel Oaza — page addresses per language, from src/_data/pages.json (also global data `pages`). */
import { readFileSync } from "node:fs";

const PAGES = JSON.parse(readFileSync(new URL("../src/_data/pages.json", import.meta.url), "utf8"));

/** @returns {string[]} every page key, in pages.json order */
export function pageKeys() {
  return Object.keys(PAGES.urls);
}

/**
 * @param {string} key page key, e.g. "rooms"
 * @param {"mk"|"en"} lang
 * @returns {string} root-relative URL without the path prefix
 */
export function pageUrl(key, lang) {
  const urls = PAGES.urls[key];
  if (!urls) throw new Error(`Unknown page "${key}"`);
  if (!urls[lang]) throw new Error(`No ${lang} URL for page "${key}"`);
  return urls[lang];
}

export function otherLang(lang) {
  return lang === "mk" ? "en" : "mk";
}

/**
 * Contact page URL with a prefill query (type, room, arrival, …); empty values are left out.
 * @param {"mk"|"en"} lang
 * @param {Record<string, string|undefined>} [params]
 */
export function contactUrl(lang, params = {}) {
  const query = new URLSearchParams(Object.entries(params).filter(([, value]) => value)).toString();
  return pageUrl("contact", lang) + (query ? `?${query}` : "");
}
