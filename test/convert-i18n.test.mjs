import { test } from "node:test";
import assert from "node:assert/strict";
import {
  applyEdits,
  assertNoTemplateSyntax,
  byClass,
  byId,
  extractTables,
  findElement,
  planConversion,
  rangeOf,
  renderRegion,
} from "../scripts/lib/convert-i18n.mjs";

const HTML = [
  "<!doctype html><html><head><title>T</title></head><body>",
  '<a class="skip-link" href="#main" data-i18n="skip">Skip</a>',
  '<header id="header"><a class="brand" href="#top" aria-label="Home" data-i18n-attr="aria-label:brand.home">Logo</a></header>',
  '<main id="main"><section class="s" id="s">',
  '  <h2 class="h2" id="s-title" data-i18n="s.title" data-reveal>Rest, <em>simply</em></h2>',
  '  <img src="images/a-640.webp" srcset="images/a-640.webp 640w, images/a-960.webp 960w"',
  '       alt="A &amp; B" data-i18n-attr="alt:s.alt">',
  '  <svg><text data-i18n="s.badge">Oaza · Štip</text></svg>',
  '  <select name="guests" data-guests="10"><option value="1">1 guest</option><option value="2" selected>2 guests</option></select>',
  '  <div class="box"><p data-i18n="s.title">Rest, <em>simply</em></p></div>',
  "</section></main></body></html>",
].join("\n");

test("planConversion collects the English text of contents and attributes", () => {
  const { en, conflicts } = planConversion(HTML);
  assert.equal(en.get("s.title"), "Rest, <em>simply</em>");
  assert.equal(en.get("s.alt"), "A &amp; B");
  assert.equal(en.get("brand.home"), "Home");
  assert.equal(en.get("s.badge"), "Oaza · Štip");
  assert.deepEqual(conflicts, []);
});

test("a region renders with shortcodes in place of data-i18n", () => {
  const plan = planConversion(HTML);
  const { start, end } = rangeOf(findElement(plan.doc, byId("s")));
  const out = renderRegion(HTML, plan.edits, start, end);
  assert.match(out, /<h2 class="h2" id="s-title" data-reveal>\{% t 's\.title' %\}<\/h2>/);
  assert.match(out, /alt="\{% ta 's\.alt' %\}">/);
  assert.match(out, /<text>\{% ta 's\.badge' %\}<\/text>/);
  assert.match(out, /<select name="guests" data-guests="10">\{% guestOptions '2' %\}<\/select>/);
  assert.match(out, /src="\/images\/a-640\.webp" srcset="\/images\/a-640\.webp 640w, \/images\/a-960\.webp 960w"/);
  assert.doesNotMatch(out, /data-i18n/);
});

test("holes become include tags and render on their own", () => {
  const plan = planConversion(HTML);
  const section = rangeOf(findElement(plan.doc, byId("s")));
  const box = rangeOf(findElement(plan.doc, byClass("div", "box")));
  const out = renderRegion(HTML, plan.edits, section.start, section.end, [{ ...box, file: "partials/box.njk" }]);
  assert.match(out, /\{% include "partials\/box\.njk" %\}/);
  assert.doesNotMatch(out, /class="box"/);
  assert.equal(renderRegion(HTML, plan.edits, box.start, box.end), `<div class="box"><p>{% t 's.title' %}</p></div>`);
});

test("the same key with different English text is reported", () => {
  const html = '<body><p data-i18n="k">One</p><p data-i18n="k">Two</p></body>';
  assert.deepEqual(planConversion(html).conflicts, [{ key: "k", first: "One", second: "Two" }]);
});

test("nested data-i18n is refused", () => {
  assert.throws(
    () => planConversion('<body><p data-i18n="a">x <span data-i18n="b">y</span></p></body>'),
    /Nested data-i18n "b" inside "a"/,
  );
});

test("an edit that crosses a region boundary is refused", () => {
  assert.throws(() => applyEdits("abcdefgh", [{ start: 2, end: 6, text: "" }], 4, 8), /crosses/);
});

test("source text that Nunjucks would read as a tag is refused", () => {
  assert.throws(() => assertNoTemplateSyntax("<p>{{ x }}</p>"), /Nunjucks syntax/);
  assert.throws(() => assertNoTemplateSyntax("<p>{# x #}</p>"), /Nunjucks syntax/);
  assert.doesNotThrow(() => assertNoTemplateSyntax('<svg><use href="#i-arrow"/></svg>'));
});

test("extractTables reads MK and RUNTIME out of i18n.js", () => {
  const src = [
    "(function () {",
    "  var MK = {",
    '    "a": "А",',
    '    "menu.open": "Отвори мени"',
    "  };",
    "",
    "  var RUNTIME = {",
    '    en: { "x": "X", "menu.open": "Open menu" },',
    '    mk: { "x": "Х", "menu.open": MK["menu.open"] }',
    "  };",
    "})();",
  ].join("\n");
  const { MK, RUNTIME } = extractTables(src);
  assert.equal(MK.a, "А");
  assert.equal(RUNTIME.mk["menu.open"], "Отвори мени");
  assert.equal(RUNTIME.en.x, "X");
});
