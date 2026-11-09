import { test } from "node:test";
import assert from "node:assert/strict";
import { findMalformedMarkers, findMarkers, highlightMarkers } from "../config/markers.js";

test("findMarkers lists what each marker asks for", () => {
  assert.deepEqual(findMarkers("a [[HOTEL: deposit rules]] b [[HOTEL:  refund timing ]]"), ["deposit rules", "refund timing"]);
  assert.deepEqual(findMarkers("no markers"), []);
});

test("findMarkers can be called repeatedly on the same text", () => {
  const text = "[[HOTEL: x]]";
  assert.deepEqual(findMarkers(text), ["x"]);
  assert.deepEqual(findMarkers(text), ["x"]);
});

test("highlightMarkers wraps markers in text", () => {
  assert.equal(
    highlightMarkers("<p>Pay [[HOTEL: deposit]] now</p>"),
    '<p>Pay <mark class="hotel-marker">[[HOTEL: deposit]]</mark> now</p>',
  );
});

test("highlightMarkers leaves attributes, titles and scripts alone", () => {
  const html = '<title>[[HOTEL: a]]</title><a title="[[HOTEL: b]]">x</a><script>var s="[[HOTEL: c]]";</script>';
  assert.equal(highlightMarkers(html), html);
});

test("highlightMarkers returns pages without markers unchanged", () => {
  assert.equal(highlightMarkers("<p>Hello</p>"), "<p>Hello</p>");
});

test("findMalformedMarkers catches mistyped and URL-encoded markers", () => {
  assert.equal(findMalformedMarkers("[[Hotel: x]]").length, 1);
  assert.equal(findMalformedMarkers("[[ HOTEL: x]]").length, 1);
  assert.equal(findMalformedMarkers("[[HOTEL: see [note] ]]").length, 1);
  assert.equal(findMalformedMarkers('<a href="https://wa.me/1?text=%5B%5BHOTEL%3A%20phone%5D%5D">x</a>').length, 1);
  assert.equal(findMalformedMarkers("%5B%5B%20hotel%3A").length, 1);
});

test("findMalformedMarkers leaves exact markers and ordinary text alone", () => {
  assert.deepEqual(findMalformedMarkers("a [[HOTEL: deposit]] b"), []);
  assert.deepEqual(findMalformedMarkers("see [[1]] and [hotel] and the hotel"), []);
});

test("findMalformedMarkers returns a readable snippet", () => {
  assert.equal(findMalformedMarkers("x [[Hotel: phone]] y")[0], "[[Hotel: phone]] y");
});
