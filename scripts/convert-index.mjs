/* One-off: convert today's page (saved in _backup/old-site) into includes and dictionaries.
   Run once from the project root: node scripts/convert-index.mjs */
import { mkdirSync, readFileSync, writeFileSync } from "node:fs";
import path from "node:path";
import { attr, byClass, byId, extractTables, findElement, planConversion, rangeOf, renderRegion } from "./lib/convert-i18n.mjs";

const OLD = "_backup/old-site";
const INCLUDES = "src/_includes";
const I18N = "src/i18n";

const sprite = (el) => el.tagName === "svg" && attr(el, "width") === "0";

/* Each region becomes one include; holes inside it become their own includes, so later tasks rewrite small files. */
const REGIONS = [
  { file: "partials/icons.njk", match: sprite },
  {
    file: "partials/header.njk",
    match: byId("header"),
    through: byId("menu"),
    holes: [
      { file: "partials/nav.njk", match: byClass("nav", "nav") },
      { file: "partials/lang.njk", match: byClass("div", "lang") },
      { file: "partials/menu-list.njk", match: byClass("ul", "menu__list") },
    ],
  },
  { file: "sections/hero.njk", match: byId("top") },
  { file: "sections/intro.njk", match: byId("about") },
  { file: "sections/rooms.njk", match: byId("rooms") },
  { file: "sections/dining.njk", match: byId("dining") },
  { file: "sections/events.njk", match: byId("events"), holes: [{ file: "partials/events-halls.njk", match: byClass("div", "halls") }] },
  { file: "sections/aquabella.njk", match: byId("aquabella") },
  {
    file: "sections/stip.njk",
    match: byId("stip"),
    holes: [
      { file: "partials/stip-top.njk", match: byClass("div", "location__top") },
      { file: "partials/location-bottom.njk", match: byClass("div", "location__bottom") },
    ],
  },
  {
    file: "sections/contact.njk",
    match: byId("contact"),
    holes: [
      { file: "partials/contact-details.njk", match: byClass("dl", "contact__details") },
      { file: "partials/enquiry-form.njk", match: byId("enquiry") },
    ],
  },
  {
    file: "partials/footer.njk",
    match: byClass("footer", "site-footer"),
    holes: [
      { file: "partials/footer-top.njk", match: byClass("div", "footer__top") },
      { file: "partials/footer-bottom.njk", match: byClass("div", "footer__bottom") },
    ],
  },
  { file: "partials/action-bar.njk", match: byClass("nav", "action-bar") },
];

const read = (file) => readFileSync(path.join(OLD, file), "utf8").replace(/\r\n/g, "\n");

function write(file, text) {
  mkdirSync(path.dirname(file), { recursive: true });
  writeFileSync(file, text);
  console.log(`wrote ${file}`);
}

const html = read("index.html");
const { MK, RUNTIME } = extractTables(read("assets/js/i18n.js"));
const plan = planConversion(html);
if (plan.conflicts.length) throw new Error(`Conflicting English text:\n${JSON.stringify(plan.conflicts, null, 2)}`);

const covered = [rangeOf(findElement(plan.doc, byClass("a", "skip-link")))];
for (const region of REGIONS) {
  const first = rangeOf(findElement(plan.doc, region.match));
  const last = region.through ? rangeOf(findElement(plan.doc, region.through)) : first;
  const holes = (region.holes ?? []).map((h) => ({ ...rangeOf(findElement(plan.doc, h.match)), file: h.file }));
  write(path.join(INCLUDES, region.file), `${renderRegion(html, plan.edits, first.start, last.end, holes)}\n`);
  for (const hole of holes) write(path.join(INCLUDES, hole.file), `${renderRegion(html, plan.edits, hole.start, hole.end)}\n`);
  covered.push({ start: first.start, end: last.end });
}
const stray = plan.edits.filter((e) => !covered.some((r) => e.start >= r.start && e.end <= r.end));
if (stray.length) throw new Error(`${stray.length} edit(s) fall outside every region: ${JSON.stringify(stray)}`);

/* <title> and the meta description become page.home.*; MK has them as meta.title / meta.description. */
const title = findElement(plan.doc, (el) => el.tagName === "title").sourceCodeLocation;
const desc = findElement(plan.doc, (el) => el.tagName === "meta" && attr(el, "name") === "description");
const en = {
  "page.home.title": html.slice(title.startTag.endOffset, title.endTag.startOffset),
  "page.home.description": attr(desc, "content"),
  ...Object.fromEntries(plan.en),
};
const MK_SOURCE = { "page.home.title": "meta.title", "page.home.description": "meta.description" };

/* Where MK has no text, today's site shows the English in Macedonian too, so copy it to keep the page identical. */
const mk = {};
const copied = [];
for (const key of Object.keys(en)) {
  const value = MK[MK_SOURCE[key] ?? key];
  if (typeof value === "string") mk[key] = value;
  else {
    mk[key] = en[key];
    copied.push(key);
  }
}
const unused = Object.keys(MK).filter((k) => !(k in en) && !Object.values(MK_SOURCE).includes(k));

write(path.join(I18N, "en/base.json"), `${JSON.stringify(en, null, 2)}\n`);
write(path.join(I18N, "mk/base.json"), `${JSON.stringify(mk, null, 2)}\n`);
write(path.join(I18N, "runtime/base.json"), `${JSON.stringify(RUNTIME, null, 2)}\n`);

console.log(`keys: ${Object.keys(en).length} en, ${Object.keys(mk).length} mk`);
console.log(`MK keys the page does not use: ${unused.join(", ") || "none"}`);
console.log(`MK text copied from English: ${copied.join(", ") || "none"}`);
