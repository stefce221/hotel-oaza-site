/* Hotel Oaza — page text: dictionaries per language, the translator, and helpers used by shortcodes. */
import { existsSync, readdirSync, readFileSync } from "node:fs";
import path from "node:path";

export const LANGS = ["mk", "en"];
export const GUEST_VALUES = ["1", "2", "3", "4", "5", "6", "7", "8", "9", "10+"];

function jsonFiles(dir) {
  return existsSync(dir) ? readdirSync(dir).filter((f) => f.endsWith(".json")).sort() : [];
}

const isObject = (value) => value !== null && typeof value === "object" && !Array.isArray(value);

/** Parse a JSON file (a leading byte order mark is ignored); parse errors name `label`. */
function readJson(file, label) {
  const text = readFileSync(file, "utf8").replace(/^\uFEFF/, "");
  try {
    return JSON.parse(text);
  } catch (err) {
    throw new Error(`${label}: ${err.message}`);
  }
}

/** A text table must be a plain object whose values are all strings. */
function assertTextTable(table, label) {
  if (!isObject(table)) throw new Error(`${label}: must be an object of texts`);
  for (const [key, value] of Object.entries(table)) {
    if (typeof value !== "string") throw new Error(`${label}: "${key}" must be a string`);
  }
  return table;
}

/** Merge `into` with `entries`, refusing a key that is already there. */
function mergeInto(into, entries, where) {
  for (const [key, value] of Object.entries(entries)) {
    if (key in into) throw new Error(`Duplicate key "${key}" in ${where}`);
    into[key] = value;
  }
}

/**
 * Read src/i18n/<lang>/*.json into one table per language.
 * @param {string} dir
 * @returns {Record<string, Record<string, string>>}
 */
export function loadDictionaries(dir) {
  const dicts = {};
  for (const lang of LANGS) {
    const table = {};
    for (const file of jsonFiles(path.join(dir, lang))) {
      const label = `${lang}/${file}`;
      mergeInto(table, assertTextTable(readJson(path.join(dir, lang, file), label), label), label);
    }
    dicts[lang] = table;
  }
  return dicts;
}

/**
 * Read src/i18n/runtime/*.json (each `{ en: {...}, mk: {...} }`) into one table per language.
 * @param {string} dir
 * @returns {Record<string, Record<string, string>>}
 */
export function loadRuntime(dir) {
  const runtime = Object.fromEntries(LANGS.map((lang) => [lang, {}]));
  for (const file of jsonFiles(path.join(dir, "runtime"))) {
    const label = `runtime/${file}`;
    const data = readJson(path.join(dir, "runtime", file), label);
    if (!isObject(data)) throw new Error(`${label}: must be an object with one table per language`);
    for (const lang of Object.keys(data)) {
      if (!LANGS.includes(lang)) throw new Error(`${label}: unknown language "${lang}"`);
    }
    for (const lang of LANGS) {
      const where = `${label} (${lang})`;
      mergeInto(runtime[lang], assertTextTable(lang in data ? data[lang] : {}, where), where);
    }
  }
  return runtime;
}

/**
 * @param {Record<string, Record<string, string>>} dicts
 * @returns {(lang: string, key: string) => string}
 */
export function createTranslator(dicts) {
  return function t(lang, key) {
    const table = dicts[lang];
    if (!table) throw new Error(`Unknown language "${lang}"`);
    const value = table[key];
    if (typeof value !== "string") throw new Error(`Missing ${lang} text for "${key}"`);
    return value;
  };
}

/** Keys present in one language but not the other, as "<lang that lacks it>: <key>". */
export function missingKeys(dicts) {
  const out = [];
  for (const lang of LANGS) {
    for (const other of LANGS) {
      if (other === lang) continue;
      for (const key of Object.keys(dicts[lang])) if (!(key in dicts[other])) out.push(`${other}: ${key}`);
    }
  }
  return out;
}

/** English pages live under /en/; everything else (including /404.html) is Macedonian. */
export function langFromUrl(url) {
  return typeof url === "string" && url.startsWith("/en/") ? "en" : "mk";
}

const ENTITY = /^&(?:[a-zA-Z][a-zA-Z0-9]*|#\d+|#[xX][0-9a-fA-F]+);/;

/** Escape text for a double-quoted attribute; entities already in the text (from today's markup) are kept. */
export function escapeAttr(value) {
  const text = String(value);
  let out = "";
  for (let i = 0; i < text.length; i += 1) {
    const ch = text[i];
    if (ch === "&") out += ENTITY.test(text.slice(i)) ? "&" : "&amp;";
    else if (ch === '"') out += "&quot;";
    else if (ch === "<") out += "&lt;";
    else if (ch === ">") out += "&gt;";
    else out += ch;
  }
  return out;
}

/** Inline script that gives main.js and fx.js today's window.oazaI18n API (lang, t, onChange) for one language. */
export function runtimeScript(strings, lang) {
  if (!LANGS.includes(lang)) throw new Error(`Unknown language "${lang}"`);
  const json = JSON.stringify(strings).replace(/</g, "\\u003c");
  return (
    "window.oazaI18n=(function(s){return{lang:" +
    JSON.stringify(lang) +
    ',t:function(k,v){var r=s[k]||k;if(v){Object.keys(v).forEach(function(n){r=r.split("{"+n+"}").join(v[n]);});}return r;},' +
    "onChange:function(){}};})(" +
    json +
    ");"
  );
}

/** The 1…10+ guest options, labelled with the runtime guest.one / guest.many / guest.more strings. */
export function guestOptionsHtml(strings, selected) {
  return GUEST_VALUES.map((value) => {
    const key = value.includes("+") ? "guest.more" : value === "1" ? "guest.one" : "guest.many";
    if (typeof strings[key] !== "string") throw new Error(`Missing runtime string "${key}" for the guest options`);
    const label = strings[key].split("{n}").join(value);
    return `<option value="${value}"${value === String(selected) ? " selected" : ""}>${label}</option>`;
  }).join("");
}
