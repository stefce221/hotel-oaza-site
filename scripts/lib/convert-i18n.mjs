/* One-off conversion of today's index.html: data-i18n text → {% t %} shortcodes, page regions → include files. */
import vm from "node:vm";
import { parse } from "parse5";

const SVG_NS = "http://www.w3.org/2000/svg";
const KEY_RE = /^[\w.-]+$/;

/** Every element under `node`, depth-first in source order. */
export function* elements(node) {
  for (const child of node.childNodes ?? []) {
    if (!child.tagName) continue;
    yield child;
    yield* elements(child.tagName === "template" ? child.content : child);
  }
}

export function attr(el, name) {
  return el.attrs.find((a) => a.name === name)?.value;
}

export const byId = (id) => (el) => attr(el, "id") === id;

export const byClass = (tag, cls) => (el) =>
  el.tagName === tag && (attr(el, "class") ?? "").split(/\s+/).includes(cls);

export function findElement(root, match) {
  for (const el of elements(root)) if (match(el)) return el;
  throw new Error("Element not found");
}

/** Source offsets of an element, end tag included. */
export function rangeOf(el) {
  return { start: el.sourceCodeLocation.startOffset, end: el.sourceCodeLocation.endOffset };
}

export function assertNoTemplateSyntax(html) {
  const hit = html.match(/\{\{|\{%|\{#/);
  if (hit) throw new Error(`Source contains Nunjucks syntax "${hit[0]}" at offset ${hit.index}`);
}

/** Start of the whitespace run before `offset`, so a removed attribute takes its leading space with it. */
function leadingSpace(html, offset) {
  let i = offset;
  while (i > 0 && /\s/.test(html[i - 1])) i -= 1;
  return i;
}

/** The attribute value exactly as written in the source (entities kept). */
function rawValue(html, loc) {
  const text = html.slice(loc.startOffset, loc.endOffset);
  const m = text.match(/^[^=]+=\s*(["'])([\s\S]*)\1$/);
  if (!m) throw new Error(`Attribute without quotes: ${text}`);
  return m[2];
}

function checkKey(key) {
  if (!KEY_RE.test(key)) throw new Error(`Unexpected characters in key "${key}"`);
  return key;
}

function selectedOption(select) {
  const option = select.childNodes.find((n) => n.tagName === "option" && attr(n, "selected") !== undefined);
  return option ? attr(option, "value") : "2";
}

/**
 * Plan the edits that turn data-i18n / data-i18n-attr into shortcodes. Offsets refer to `html`.
 * @param {string} html
 * @returns {{ doc: object, edits: {start: number, end: number, text: string}[], en: Map<string, string>,
 *             conflicts: {key: string, first: string, second: string}[] }}
 */
export function planConversion(html) {
  assertNoTemplateSyntax(html);
  const doc = parse(html, { sourceCodeLocationInfo: true });
  const edits = [];
  const en = new Map();
  const conflicts = [];
  const contentRanges = [];

  const record = (key, value) => {
    if (!en.has(key)) en.set(key, value);
    else if (en.get(key) !== value) conflicts.push({ key, first: en.get(key), second: value });
  };
  const dropAttr = (loc) => edits.push({ start: leadingSpace(html, loc.startOffset), end: loc.endOffset, text: "" });

  for (const el of elements(doc)) {
    const loc = el.sourceCodeLocation;

    const spec = attr(el, "data-i18n-attr");
    if (spec !== undefined) {
      for (const pair of spec.split(",")) {
        const [name, rawKey] = pair.split(":").map((s) => s.trim());
        const key = checkKey(rawKey);
        const attrLoc = loc.attrs[name];
        if (!attrLoc) throw new Error(`data-i18n-attr names a missing attribute "${name}" (${key})`);
        record(key, rawValue(html, attrLoc));
        edits.push({ start: attrLoc.startOffset, end: attrLoc.endOffset, text: `${name}="{% ta '${key}' %}"` });
      }
      dropAttr(loc.attrs["data-i18n-attr"]);
    }

    const rawKey = attr(el, "data-i18n");
    if (rawKey !== undefined) {
      const key = checkKey(rawKey);
      if (!loc.endTag) throw new Error(`data-i18n="${key}" on <${el.tagName}> has no end tag`);
      const start = loc.startTag.endOffset;
      const end = loc.endTag.startOffset;
      const outer = contentRanges.find((r) => start >= r.start && end <= r.end);
      if (outer) throw new Error(`Nested data-i18n "${key}" inside "${outer.key}"`);
      contentRanges.push({ start, end, key });
      record(key, html.slice(start, end));
      const shortcode = el.namespaceURI === SVG_NS ? "ta" : "t";
      edits.push({ start, end, text: `{% ${shortcode} '${key}' %}` });
      dropAttr(loc.attrs["data-i18n"]);
    }

    if (el.tagName === "select" && attr(el, "data-guests") !== undefined) {
      edits.push({ start: loc.startTag.endOffset, end: loc.endTag.startOffset, text: `{% guestOptions '${selectedOption(el)}' %}` });
    }
  }
  return { doc, edits, en, conflicts };
}

/** Apply the edits that lie inside [from, to) and return that slice of `html`. */
export function applyEdits(html, edits, from = 0, to = html.length) {
  const inside = [];
  for (const e of edits) {
    const within = e.start >= from && e.end <= to;
    if (!within && e.start < to && e.end > from) throw new Error(`Edit ${e.start}-${e.end} crosses region ${from}-${to}`);
    if (within) inside.push(e);
  }
  inside.sort((a, b) => a.start - b.start);
  let out = "";
  let pos = from;
  for (const e of inside) {
    if (e.start < pos) throw new Error(`Overlapping edits at offset ${e.start}`);
    out += html.slice(pos, e.start) + e.text;
    pos = e.end;
  }
  return out + html.slice(pos, to);
}

/** Today's markup uses page-relative "images/…" and "assets/…"; inner pages need "/images/…". */
export function rootRelative(fragment) {
  return fragment.replace(/(["\s,])((?:images|assets)\/)/g, "$1/$2");
}

/**
 * Render [start, end) with edits applied, each hole ({start, end, file}) replaced by an include tag.
 * @param {string} html
 * @param {{start: number, end: number, text: string}[]} edits
 * @param {number} start
 * @param {number} end
 * @param {{start: number, end: number, file: string}[]} [holes]
 */
export function renderRegion(html, edits, start, end, holes = []) {
  let out = "";
  let pos = start;
  for (const hole of [...holes].sort((a, b) => a.start - b.start)) {
    if (hole.start < pos || hole.end > end) throw new Error(`Hole ${hole.file} lies outside its region`);
    out += applyEdits(html, edits, pos, hole.start) + `{% include "${hole.file}" %}`;
    pos = hole.end;
  }
  return rootRelative(out + applyEdits(html, edits, pos, end));
}

/** Read the MK and RUNTIME tables out of today's i18n.js (both end with "\n  };"). */
export function extractTables(src) {
  const slice = (marker) => {
    const start = src.indexOf(marker);
    if (start < 0) throw new Error(`"${marker}" not found`);
    const end = src.indexOf("\n  };", start);
    if (end < 0) throw new Error(`End of "${marker}" not found`);
    return src.slice(start, end + "\n  };".length);
  };
  const sandbox = {};
  vm.runInNewContext(
    `${slice("var MK = {")}\n${slice("var RUNTIME = {")}\nthis.out = JSON.stringify({ MK: MK, RUNTIME: RUNTIME });`,
    sandbox,
  );
  return JSON.parse(sandbox.out);
}
