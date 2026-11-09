/* Hotel Oaza — pure helpers for npm run check. */

const URL_ATTR = /\s(?:href|src|action)="([^"]*)"/g;
const SRCSET_ATTR = /\s(?:srcset|imagesrcset)="([^"]*)"/g;

/** Every URL a page refers to (href, src, action, srcset), without in-page anchors. */
export function extractRefs(html) {
  const refs = [];
  for (const m of html.matchAll(URL_ATTR)) refs.push(m[1]);
  for (const m of html.matchAll(SRCSET_ATTR)) {
    for (const part of m[1].split(",")) refs.push(part.trim().split(/\s+/)[0]);
  }
  return refs.filter((ref) => ref && !ref.startsWith("#"));
}

/** In-page anchors (href="#x", including SVG <use>) whose id="x" is missing from the same page. */
export function missingFragments(html) {
  const ids = new Set(Array.from(html.matchAll(/\sid="([^"]+)"/g), (m) => m[1]));
  const missing = new Set();
  for (const m of html.matchAll(/\shref="#([^"]+)"/g)) if (!ids.has(m[1])) missing.add(m[1]);
  return [...missing];
}

export function isExternal(ref) {
  return /^(?:[a-z][a-z0-9+.-]*:|\/\/)/i.test(ref);
}

/** "/prefix/sobi/?q" → "sobi/index.html"; null when the URL is outside the path prefix. */
export function refToFile(ref, prefix) {
  const clean = ref.split(/[?#]/)[0];
  if (!clean.startsWith(prefix)) return null;
  const rel = decodeURIComponent(clean.slice(prefix.length));
  return rel === "" || rel.endsWith("/") ? `${rel}index.html` : rel;
}

/** What a page's head and headings lack: lang, title, description, exactly one h1, hreflang links. */
export function headIssues(html, { notFound = false } = {}) {
  const issues = [];
  if (!/<html[^>]*\slang="(?:mk|en)"/.test(html)) issues.push("lang");
  if (!/<title>[^<]+<\/title>/.test(html)) issues.push("title");
  if (!/<meta name="description" content="[^"]+"/.test(html)) issues.push("description");
  const h1 = (html.match(/<h1[\s>]/g) || []).length;
  if (h1 !== 1) issues.push(`h1 ×${h1}`);
  if (!notFound && !/<link rel="alternate" hreflang="x-default"/.test(html)) issues.push("hreflang");
  return issues;
}
