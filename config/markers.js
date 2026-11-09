/* Hotel Oaza — [[HOTEL: …]] markers: facts only the hotel can supply. */
const MARKER_RE = /\[\[HOTEL:\s*([^\]]+?)\s*\]\]/g;
const RAW_TEXT = /^<(\/?)(script|style|title|textarea)\b/i;

/** @returns {string[]} what each marker in `text` asks for */
export function findMarkers(text) {
  return Array.from(text.matchAll(MARKER_RE), (m) => m[1]);
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
