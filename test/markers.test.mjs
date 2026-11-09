import { test } from "node:test";
import assert from "node:assert/strict";
import { findMarkers, highlightMarkers } from "../config/markers.js";

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
