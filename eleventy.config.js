/* Hotel Oaza — Eleventy config: bilingual shortcodes, asset hashes, path prefix for the GitHub Pages preview. */
import { mkdirSync, writeFileSync } from "node:fs";
import path from "node:path";
import { HtmlBasePlugin } from "@11ty/eleventy";
import { assetUrl } from "./config/assets.js";
import { readBuildEnv } from "./config/build-env.js";
import { loadSite, telHref, viberHref, whatsappHref } from "./config/contact.js";
import {
  createTranslator,
  escapeAttr,
  guestOptionsHtml,
  langFromUrl,
  loadDictionaries,
  loadRuntime,
  runtimeScript,
} from "./config/i18n.js";
import { contactUrl, pageUrl, reloadPages } from "./config/pages.js";

/* Checked once, so a typo or a Git Bash-mangled PATH_PREFIX stops the build with a clear message. */
const { env: ENV, pathPrefix: PATH_PREFIX } = readBuildEnv(process.env); // env: dev | preview | live
const I18N_DIR = "src/i18n";

export default function (eleventyConfig) {
  let site = loadSite();
  let runtime = loadRuntime(I18N_DIR);
  let t = createTranslator(loadDictionaries(I18N_DIR));

  /* Re-read the dictionaries, site facts and page addresses on every (watch) build. */
  eleventyConfig.on("eleventy.before", () => {
    site = loadSite();
    reloadPages();
    runtime = loadRuntime(I18N_DIR);
    t = createTranslator(loadDictionaries(I18N_DIR));
  });
  /* npm run check reads this to know which build it is looking at. */
  eleventyConfig.on("eleventy.after", ({ dir }) => {
    mkdirSync(dir.output, { recursive: true }); /* --dryrun writes nothing else, so _site may not exist */
    writeFileSync(path.join(dir.output, ".oaza-build.json"), `${JSON.stringify({ env: ENV, pathPrefix: PATH_PREFIX })}\n`);
  });

  eleventyConfig.addPlugin(HtmlBasePlugin);
  eleventyConfig.addWatchTarget(I18N_DIR);
  eleventyConfig.addWatchTarget("src/assets/css/");
  eleventyConfig.addPassthroughCopy({
    "src/images": "images",
    "src/assets/img": "assets/img",
    "src/assets/js": "assets/js",
  });

  /* The page language comes from its URL (/en/… = English); a second argument overrides it (404 page). */
  const langOf = (page, lang) => lang || langFromUrl(page.url);

  eleventyConfig.addShortcode("t", function (key, lang) {
    return t(langOf(this.page, lang), key);
  });
  eleventyConfig.addShortcode("ta", function (key, lang) {
    return escapeAttr(t(langOf(this.page, lang), key));
  });
  eleventyConfig.addShortcode("guestOptions", function (selected = "2") {
    return guestOptionsHtml(runtime[langOf(this.page)], selected);
  });
  eleventyConfig.addShortcode("i18nScript", function () {
    const lang = langOf(this.page);
    return runtimeScript(runtime[lang], lang);
  });
  eleventyConfig.addShortcode("asset", (urlPath) => assetUrl(urlPath));
  eleventyConfig.addShortcode("telHref", () => telHref(site));
  eleventyConfig.addShortcode("viberHref", () => viberHref(site));
  eleventyConfig.addShortcode("waHref", function (pageKey) {
    return escapeAttr(whatsappHref(site, pageKey, langOf(this.page)));
  });
  eleventyConfig.addShortcode("contactHref", function (type, room) {
    return escapeAttr(contactUrl(langOf(this.page), { type, room }));
  });

  eleventyConfig.addFilter("pageUrl", (key, lang) => pageUrl(key, lang));
  /* HtmlBasePlugin prefixes src/srcset/href; imagesrcset on the preload link needs this. */
  eleventyConfig.addFilter("srcsetBase", (srcset) =>
    srcset
      .split(",")
      .map((part) => PATH_PREFIX.replace(/\/$/, "") + part.trim())
      .join(", "),
  );

  return {
    dir: { input: "src", output: "_site", includes: "_includes", data: "_data" },
    pathPrefix: PATH_PREFIX,
    templateFormats: ["njk", "11ty.js"],
    htmlTemplateEngine: "njk",
  };
}
