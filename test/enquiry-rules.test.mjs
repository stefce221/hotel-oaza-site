import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import vm from "node:vm";

/* The script is a browser IIFE that sets window.oazaEnquiryRules; run it in a sandbox. */
const sandbox = { window: {}, URLSearchParams };
vm.runInNewContext(readFileSync("src/assets/js/enquiry-rules.js", "utf8"), sandbox);
const rules = sandbox.window.oazaEnquiryRules;
/* Objects from the sandbox have another realm's prototypes; compare them as plain JSON. */
const plain = (value) => JSON.parse(JSON.stringify(value));

const TODAY = "2026-11-06";
const STAY = {
  type: "stay", arrival: "2026-12-01", departure: "2026-12-03", guests: "2", eventGuests: "30", room: "any",
  overnight: "no", name: "Test Guest", email: "guest@example.com", phone: "", company: "", message: "",
};

test("isIsoDate accepts real calendar dates only", () => {
  assert.equal(rules.isIsoDate("2026-11-06"), true);
  assert.equal(rules.isIsoDate("2026-02-30"), false);
  assert.equal(rules.isIsoDate("06.11.2026"), false);
  assert.equal(rules.isIsoDate(""), false);
});

test("addDays crosses months and years", () => {
  assert.equal(rules.addDays("2026-12-31", 1), "2027-01-01");
  assert.equal(rules.addDays("2026-03-01", -1), "2026-02-28");
});

test("parsePrefill keeps valid values", () => {
  const got = rules.parsePrefill("?type=stay&arrival=2026-12-01&departure=2026-12-03&guests=10%2B&room=twin", TODAY);
  assert.deepEqual(plain(got), { type: "stay", arrival: "2026-12-01", departure: "2026-12-03", guests: "10+", room: "twin" });
});

test("parsePrefill ignores unknown, past and malformed values", () => {
  assert.deepEqual(plain(rules.parsePrefill("?type=spa&arrival=2026-01-01&departure=2026-13-01&guests=11&room=suite", TODAY)), {});
  assert.deepEqual(plain(rules.parsePrefill("?arrival=2026-12-03&departure=2026-12-03", TODAY)), { arrival: "2026-12-03" });
  assert.deepEqual(plain(rules.parsePrefill("", TODAY)), {});
});

test("utmFrom keeps the three campaign fields, at most 100 characters each", () => {
  const got = plain(rules.utmFrom(`?utm_source=facebook&utm_medium=cpc&utm_campaign=${"x".repeat(150)}&gclid=1`));
  assert.equal(got.utm_source, "facebook");
  assert.equal(got.utm_medium, "cpc");
  assert.equal(got.utm_campaign.length, 100);
  assert.equal(Object.keys(got).length, 3);
  assert.deepEqual(plain(rules.utmFrom("?a=1")), {});
});

test("a complete stay passes", () => {
  assert.deepEqual(plain(rules.validate(STAY, TODAY)), {});
});

test("name and email are required, and email must look like an address", () => {
  assert.deepEqual(plain(rules.validate({ ...STAY, name: " ", email: "" }, TODAY)), { name: "required", email: "required" });
  assert.deepEqual(plain(rules.validate({ ...STAY, email: "guest@example" }, TODAY)), { email: "email" });
});

test("length limits count characters, Cyrillic included", () => {
  assert.deepEqual(plain(rules.validate({ ...STAY, name: "Ж".repeat(100) }, TODAY)), {});
  assert.deepEqual(plain(rules.validate({ ...STAY, name: "Ж".repeat(101) }, TODAY)), { name: "long" });
  assert.deepEqual(plain(rules.validate({ ...STAY, phone: "1".repeat(41), message: "м".repeat(2001) }, TODAY)), { phone: "long", message: "long" });
});

test("dates may not be in the past, and a stay's departure follows arrival", () => {
  assert.deepEqual(plain(rules.validate({ ...STAY, arrival: "2026-11-05" }, TODAY)), { arrival: "date" });
  assert.deepEqual(plain(rules.validate({ ...STAY, departure: "2026-12-01" }, TODAY)), { departure: "dates" });
  assert.deepEqual(plain(rules.validate({ ...STAY, departure: "not-a-date" }, TODAY)), { departure: "date" });
});

test("events ignore departure, guests and room but check the hall size", () => {
  const wedding = { ...STAY, type: "wedding", departure: "2026-11-01", guests: "x", room: "x" };
  assert.deepEqual(plain(rules.validate(wedding, TODAY)), {});
  assert.deepEqual(plain(rules.validate({ ...wedding, eventGuests: "40" }, TODAY)), { eventGuests: "choice" });
});

test("company and overnight are checked only for conferences", () => {
  const long = "c".repeat(151);
  assert.deepEqual(plain(rules.validate({ ...STAY, company: long, overnight: "x" }, TODAY)), {});
  assert.deepEqual(plain(rules.validate({ ...STAY, type: "conference", company: long, overnight: "x" }, TODAY)), {
    company: "long",
    overnight: "choice",
  });
});

test("values outside the form's lists are refused", () => {
  assert.deepEqual(plain(rules.validate({ ...STAY, type: "spa" }, TODAY)), { type: "choice" });
  assert.deepEqual(plain(rules.validate({ ...STAY, guests: "11", room: "suite" }, TODAY)), { guests: "choice", room: "choice" });
});

test("linkDatePair sets minimum dates and moves departure after arrival", () => {
  const input = () => ({ value: "", min: "", handlers: {}, addEventListener(type, fn) { this.handlers[type] = fn; } });
  const arrival = input();
  const departure = input();
  rules.linkDatePair(arrival, departure, TODAY);
  assert.equal(arrival.min, TODAY);
  assert.equal(departure.min, "2026-11-07");
  arrival.value = "2026-12-10";
  arrival.handlers.change();
  assert.equal(departure.min, "2026-12-11");
  assert.equal(departure.value, "2026-12-11");
});

test("linkDatePair puts the departure minimum back when the arrival is cleared", () => {
  const input = () => ({ value: "", min: "", handlers: {}, addEventListener(type, fn) { this.handlers[type] = fn; } });
  const arrival = input();
  const departure = input();
  rules.linkDatePair(arrival, departure, TODAY);
  arrival.value = "2026-12-10";
  arrival.handlers.change();
  arrival.value = "";
  arrival.handlers.change();
  assert.equal(departure.min, "2026-11-07");
});

test("todayIso is the date at the hotel (Europe/Skopje), like the server", () => {
  /* 23:30 UTC on 6 Nov is 18:30 on 6 Nov in New York but already 7 Nov in Štip. */
  class NewYorkDate extends Date {
    constructor(...args) { super(...(args.length ? args : ["2026-11-06T23:30:00Z"])); }
    getTimezoneOffset() { return 300; }
  }
  const box = { window: {}, URLSearchParams, Date: NewYorkDate };
  vm.runInNewContext(readFileSync("src/assets/js/enquiry-rules.js", "utf8"), box);
  assert.equal(box.window.oazaEnquiryRules.todayIso(), "2026-11-07");
});

test("todayIso always matches the ISO date pattern", () => {
  const box = { window: {}, URLSearchParams };
  vm.runInNewContext(readFileSync("src/assets/js/enquiry-rules.js", "utf8"), box);
  assert.match(box.window.oazaEnquiryRules.todayIso(), /^\d{4}-\d{2}-\d{2}$/);
});
