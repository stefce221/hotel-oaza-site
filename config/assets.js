/* Hotel Oaza — one CSS bundle in today's cascade order, and content-hashed asset URLs. */
import { createHash } from "node:crypto";
import { existsSync, readdirSync, readFileSync } from "node:fs";
import path from "node:path";

export const CSS_BUNDLE_URL = "/assets/css/site.css";
const CSS_DIR = "src/assets/css";

function cssIn(dir) {
  return existsSync(dir)
    ? readdirSync(dir)
        .filter((f) => f.endsWith(".css"))
        .sort()
        .map((f) => path.join(dir, f))
    : [];
}

/** tokens → base → sections 01…17 → fx (today's load order), then additions for new rules. */
export function cssFiles(root = CSS_DIR) {
  return [
    path.join(root, "tokens.css"),
    path.join(root, "base.css"),
    ...cssIn(path.join(root, "sections")),
    path.join(root, "fx.css"),
    ...cssIn(path.join(root, "additions")),
  ];
}

export function bundleCss(root = CSS_DIR) {
  return cssFiles(root)
    .map((file) => {
      const css = readFileSync(file, "utf8");
      if (/@import|@charset/.test(css)) throw new Error(`${file}: @import / @charset cannot be bundled`);
      return css;
    })
    .join("\n");
}

/** @param {string|Buffer} content */
export function contentHash(content) {
  return createHash("sha256").update(content).digest("hex").slice(0, 10);
}

/** "/assets/js/main.js" → "/assets/js/main.js?v=<hash>"; site.css is hashed from its sources. */
export function assetUrl(urlPath, siteSrc = "src") {
  const content =
    urlPath === CSS_BUNDLE_URL ? bundleCss(path.join(siteSrc, "assets/css")) : readFileSync(path.join(siteSrc, urlPath));
  return `${urlPath}?v=${contentHash(content)}`;
}
