/* Hotel Oaza — [[HOTEL: …]] markers: facts only the hotel can supply. */
const MARKER_RE = /\[\[HOTEL:\s*([^\]]+?)\s*\]\]/g;
/* Loose on purpose: any case, spaces after the brackets, and the URL-encoded form (e.g. inside a WhatsApp link). */
const LOOSE_MARKER_RE = /\[\[\s*hotel|%5B%5B(?:%20|\s)*hotel/gi;
const SNIPPET_LENGTH = 60;
const RAW_TEXT = /^<(\/?)(script|style|title|textarea)\b/i;

/** @returns {string[]} what each marker in `text` asks for */
export function findMarkers(text) {
  return Array.from(text.matchAll(MARKER_RE), (m) => m[1]);
}

/**
 * Places that look like a marker but findMarkers does not list: wrong case, stray spaces,
 * a "]" inside the text, or URL-encoded. Used by the live build so none slips through.
 * @returns {string[]} a short snippet starting at each such place
 */
export function findMalformedMarkers(text) {
  const exact = new Set(Array.from(text.matchAll(MARKER_RE), (m) => m.index));
  return Array.from(text.matchAll(LOOSE_MARKER_RE))
    .filter((m) => !exact.has(m.index))
    .map((m) => text.slice(m.index, m.index + SNIPPET_LENGTH).replace(/\s+/g, " ").trim());
}

/** Wrap markers in page text (never in tags, attributes, titles or scripts) in <mark class="hotel-marker">. */
export function highlightMarkers(html) {
  if (!html.includes("[[HOTEL:")) return html;
  let insideRaw = false;
  return html
    .split(/(<[^>]*>)/)
    .map((part, i) => {
      if (i % 2 === 1) {
        const tag = part.match(RAW_TEXT);
        if (tag) insideRaw = !tag[1];
        return part;
      }
      return insideRaw ? part : part.replace(MARKER_RE, (marker) => `<mark class="hotel-marker">${marker}</mark>`);
    })
    .join("");
}
