import { test } from "node:test";
import assert from "node:assert/strict";
import { extractRefs, headIssues, isExternal, missingFragments, refToFile } from "../scripts/lib/site-checks.mjs";

test("extractRefs collects href, src, action and srcset entries, skipping in-page anchors", () => {
  const html = [
    '<a href="/sobi/">x</a><a href="#main">skip</a>',
    '<img src="/images/a.webp" srcset="/images/a-640.webp 640w, /images/a-960.webp 960w">',
    '<link rel="preload" imagesrcset="/images/b-640.webp 640w">',
    '<form action="/kontakt/" method="get"></form>',
  ].join("");
  assert.deepEqual(extractRefs(html).sort(), [
    "/images/a-640.webp",
    "/images/a-960.webp",
    "/images/a.webp",
    "/images/b-640.webp",
    "/kontakt/",
    "/sobi/",
  ]);
});

test("missingFragments lists in-page anchors without a target", () => {
  const html = '<a href="#main">s</a><main id="main"></main><a href="#enquiry">e</a><svg><use href="#i-arrow"/></svg><symbol id="i-arrow"></symbol>';
  assert.deepEqual(missingFragments(html), ["enquiry"]);
  assert.deepEqual(missingFragments('<a href="#">top</a>'), []);
});

test("isExternal recognises other schemes and hosts", () => {
  for (const ref of ["https://x.mk/", "mailto:a@b.mk", "tel:+38970310300", "viber://chat?number=1", "//cdn.example/x.js"]) {
    assert.equal(isExternal(ref), true, ref);
  }
  assert.equal(isExternal("/sobi/"), false);
});

test("refToFile maps a URL to its file under _site, honouring the path prefix", () => {
  assert.equal(refToFile("/", "/"), "index.html");
  assert.equal(refToFile("/sobi/", "/"), "sobi/index.html");
  assert.equal(refToFile("/404.html", "/"), "404.html");
  assert.equal(refToFile("/hotel-oaza-site/assets/js/main.js?v=abc", "/hotel-oaza-site/"), "assets/js/main.js");
  assert.equal(refToFile("/sobi/", "/hotel-oaza-site/"), null);
});

const GOOD = [
  '<html lang="en"><head><title>T</title><meta name="description" content="D">',
  '<link rel="alternate" hreflang="x-default" href="https://www.oazahotel.com.mk/"></head>',
  "<body><h1>One</h1></body></html>",
].join("");

test("headIssues passes a complete page", () => {
  assert.deepEqual(headIssues(GOOD), []);
});

test("headIssues names what is missing", () => {
  assert.deepEqual(headIssues('<html><head><title></title></head><body><h1>a</h1><h1>b</h1></body></html>'), [
    "lang",
    "title",
    "description",
    "h1 ×2",
    "hreflang",
  ]);
});

test("the 404 page needs no hreflang", () => {
  assert.deepEqual(headIssues(GOOD.replace(/<link[^>]*>/, ""), { notFound: true }), []);
});
