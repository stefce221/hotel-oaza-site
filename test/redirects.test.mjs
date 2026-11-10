import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { pageKeys, pageUrl } from "../config/pages.js";

/* The old sites' addresses come from the Internet Archive (2010 to 2023). Apache is not run here:
   the RedirectMatch lines of src/.htaccess are read and applied the way Apache would apply them. */
const htaccess = readFileSync(new URL("../src/.htaccess", import.meta.url), "utf8");
const rules = [...htaccess.matchAll(/^RedirectMatch 301 "([^"]+)" "([^"]+)"\s*$/gm)].map(([, from, to]) => ({
  from: new RegExp(from),
  to,
}));

/** Where the server rules send an address, or null when no rule matches it. */
const redirectFor = (path) => rules.find((rule) => rule.from.test(path))?.to ?? null;

const pages = pageKeys().flatMap((key) => ["mk", "en"].map((lang) => pageUrl(key, lang)));

test("the old site's addresses lead to the matching new page", () => {
  const expected = {
    "/about-us": "/",
    "/blog": "/",
    "/gallery": "/",
    "/splash": "/",
    "/popup-5": "/",
    "/read-more-2": "/",
    "/index.htm": "/",
    "/index_mak.htm": "/",
    "/contacts": "/kontakt/",
    "/contacts/": "/kontakt/",
    "/reservations": "/kontakt/?type=stay",
    "/rezervacija.htm": "/kontakt/?type=stay",
    "/privacy-policy": "/privatnost/",
  };
  for (const [from, to] of Object.entries(expected)) {
    assert.equal(redirectFor(from), to, from);
  }
});

test("every redirect ends on a page that exists", () => {
  assert.ok(rules.length > 0, "the redirect rules were found");
  for (const { to } of rules) {
    assert.ok(pages.includes(to.split("?")[0]), to);
  }
});

test("every redirect line of .htaccess is one the checks here read", () => {
  const written = htaccess.split(/\r?\n/).filter((line) => /^\s*RedirectMatch\s/.test(line) && !/^\s*RedirectMatch\s+404\s/.test(line));
  assert.equal(rules.length, written.length, "a RedirectMatch line is not written as: RedirectMatch 301 \"from\" \"to\"");
});

test("no redirect catches an address of the new site", () => {
  const live = [
    ...pages,
    "/index.html",
    "/en/index.html",
    "/404.html",
    "/api/enquiry.php",
    "/cpay/ok.php",
    "/cpay/fail.php",
    "/assets/css/site.css",
    "/images/exterior-1440.webp",
    "/sitemap.xml",
    "/robots.txt",
    "/.well-known/acme-challenge/token",
  ];
  for (const path of live) {
    assert.equal(redirectFor(path), null, path);
  }
});

test("an old address that only looks like a redirected one is left alone", () => {
  for (const path of ["/about-us-2", "/blog/post", "/contacts.php", "/popup-", "/index.html", "/en/contacts"]) {
    assert.equal(redirectFor(path), null, path);
  }
});
