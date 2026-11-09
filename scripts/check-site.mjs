/* npm run check — run after a build. Reads _site/.oaza-build.json to know the build's env and path prefix. */
import { existsSync, readdirSync, readFileSync } from "node:fs";
import path from "node:path";
import { LANGS, loadDictionaries, loadRuntime, missingKeys } from "../config/i18n.js";
import { findMarkers } from "../config/markers.js";
import { pageKeys, pageUrl } from "../config/pages.js";
import { cssSplitIntact } from "./check-css-split.mjs";
import { extractRefs, headIssues, indexingIssues, isExternal, missingFragments, refToFile } from "./lib/site-checks.mjs";

const SITE = "_site";
const problems = [];
const BUILD_INFO = path.join(SITE, ".oaza-build.json");
if (!existsSync(BUILD_INFO)) {
  console.error("check-site: no build found, run a build first");
  process.exit(1);
}
const { env, pathPrefix } = JSON.parse(readFileSync(BUILD_INFO, "utf8"));
const expected = process.argv.find((arg) => arg.startsWith("--expect="))?.slice("--expect=".length);
if (expected && expected !== env) {
  console.error(`check-site: expected a ${expected} build, found ${env}`);
  process.exit(1);
}
const pages = readdirSync(SITE, { recursive: true })
  .map((rel) => String(rel).split(path.sep).join("/"))
  .filter((rel) => rel.endsWith(".html"));

const pageHtml = Object.fromEntries(pages.map((rel) => [rel, readFileSync(path.join(SITE, rel), "utf8")]));
const robotsPath = path.join(SITE, "robots.txt");
if (!existsSync(robotsPath)) problems.push("robots.txt is missing");
for (const issue of indexingIssues(env, pageHtml, existsSync(robotsPath) ? readFileSync(robotsPath, "utf8") : "")) problems.push(issue);

let links = 0;
for (const [rel, html] of Object.entries(pageHtml)) {
  for (const issue of headIssues(html, { notFound: rel === "404.html" })) problems.push(`${rel}: head lacks ${issue}`);
  for (const id of missingFragments(html)) problems.push(`${rel}: in-page link #${id} has no target`);
  if (html.includes('id="enquiry"') && !/\/assets\/js\/enquiry\.js\?v=/.test(html)) problems.push(`${rel}: has the enquiry form but does not load enquiry.js`);
  for (const ref of extractRefs(html)) {
    if (isExternal(ref) || ref.startsWith("data:")) continue;
    links += 1;
    const file = refToFile(ref, pathPrefix);
    if (file === null) problems.push(`${rel}: ${ref} is outside ${pathPrefix}`);
    else if (file === false) problems.push(`${rel}: bad URL encoding in ${ref}`);
    /* The PHP endpoint ships only in the live build; the form shows its failure state elsewhere. */
    else if (file.startsWith("api/") && env !== "live") continue;
    else if (!existsSync(path.join(SITE, file))) problems.push(`${rel}: broken link ${ref}`);
  }
  if (env === "live") for (const marker of findMarkers(html)) problems.push(`${rel}: hotel marker "${marker}"`);
}

for (const key of pageKeys()) {
  for (const lang of LANGS) {
    const file = refToFile(pathPrefix + pageUrl(key, lang).slice(1), pathPrefix);
    if (!existsSync(path.join(SITE, file))) problems.push(`missing ${lang} page "${key}" (${file})`);
  }
}

for (const gap of missingKeys(loadDictionaries("src/i18n"))) problems.push(`dictionary: missing ${gap}`);
for (const gap of missingKeys(loadRuntime("src/i18n"))) problems.push(`runtime strings: missing ${gap}`);
if (!cssSplitIntact()) problems.push("section CSS no longer joins to the original sections.css");

if (problems.length) {
  console.error(`check-site (${env}): ${problems.length} problem(s)\n${problems.join("\n")}`);
  process.exit(1);
}
console.log(`check-site (${env}): ${pages.length} pages, ${links} internal links, all good`);
