import { test } from "node:test";
import assert from "node:assert/strict";
import { extractRefs, headIssues, indexingIssues, isExternal, missingFragments, missingServerFiles, refToFile } from "../scripts/lib/site-checks.mjs";

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

test("refToFile reports a stray % instead of throwing", () => {
  assert.equal(refToFile("/sobi%/", "/"), false);
  assert.equal(refToFile("/a%20b/", "/"), "a b/index.html");
});

const NOINDEX = '<head><meta name="robots" content="noindex"></head>';
const PLAIN = "<head><title>x</title></head>";
const LIVE_ROBOTS = "User-agent: *\nAllow: /\n\nSitemap: https://example.mk/sitemap.xml";
const BLOCK_ROBOTS = "User-agent: *\nDisallow: /";

test("indexingIssues: a clean live build has none, and 404 may carry noindex", () => {
  const pages = { "index.html": PLAIN, "en/index.html": PLAIN, "404.html": NOINDEX };
  assert.deepEqual(indexingIssues("live", pages, LIVE_ROBOTS), []);
});

test("indexingIssues: live flags a stray noindex and names the file", () => {
  const issues = indexingIssues("live", { "index.html": PLAIN, "sobi/index.html": NOINDEX }, LIVE_ROBOTS);
  assert.equal(issues.length, 1);
  assert.match(issues[0], /sobi\/index\.html/);
  assert.match(issues[0], /noindex/);
});

test("indexingIssues: live robots.txt needs Allow and Sitemap and no Disallow: /", () => {
  const noSitemap = indexingIssues("live", { "index.html": PLAIN }, "User-agent: *\nAllow: /");
  assert.equal(noSitemap.length, 1);
  assert.match(noSitemap[0], /robots\.txt.*Sitemap/);
  const blocked = indexingIssues("live", { "index.html": PLAIN }, `${LIVE_ROBOTS}\nDisallow: /`);
  assert.match(blocked.join("\n"), /robots\.txt.*Disallow/);
  const noAllow = indexingIssues("live", { "index.html": PLAIN }, "Sitemap: https://example.mk/s.xml");
  assert.match(noAllow.join("\n"), /robots\.txt.*Allow/);
});

test("indexingIssues: dev and preview need noindex on every page and Disallow: /", () => {
  for (const env of ["dev", "preview"]) {
    assert.deepEqual(indexingIssues(env, { "index.html": NOINDEX, "404.html": NOINDEX }, BLOCK_ROBOTS), []);
    const issues = indexingIssues(env, { "index.html": NOINDEX, "en/index.html": PLAIN }, LIVE_ROBOTS);
    assert.equal(issues.length, 2);
    assert.match(issues[0], /en\/index\.html/);
    assert.match(issues[1], /robots\.txt.*Disallow/);
  }
});

test("missingServerFiles lists the live server files that are absent", () => {
  const all = [".htaccess", "api/.htaccess", "api/.user.ini", "api/enquiry.php", "cpay/.htaccess", "cpay/.user.ini", "cpay/ok.php", "cpay/fail.php"];
  assert.deepEqual(missingServerFiles(new Set(all)), []);
  assert.deepEqual(missingServerFiles(all.filter((file) => file !== "api/.user.ini" && file !== "cpay/fail.php")), ["api/.user.ini", "cpay/fail.php"]);
  assert.equal(missingServerFiles([]).length, 8);
});
