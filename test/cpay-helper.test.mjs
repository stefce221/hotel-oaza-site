/* The JavaScript cPay helper against the worked examples of cPay's specification (v3.3). The PHP code is checked
   against the same examples in test/php/checksum.test.php, so each side can check the other in later tests. */
import { test } from "node:test";
import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { TEST_KEY, checksumHeader, cpayChecksum, formBody, returnNotice } from "./helpers/cpay.mjs";

const SAMPLE_ONE = [
  ["PaymentOKURL", "https://bookstore/ok.html"],
  ["PaymentFailURL", "https://bookstore/fail.html"],
  ["AmountToPay", "12300"],
  ["AmountCurrency", "MKD"],
  ["PayToMerchant", "1000000003"],
  ["Details1", "purchase of books"],
  ["Details2", "Order 25467"],
  ["MerchantName", "Bookstore"],
];
const SAMPLE_ONE_HEADER =
  "08PaymentOKURL,PaymentFailURL,AmountToPay,AmountCurrency,PayToMerchant,Details1,Details2,MerchantName,025027005003010017011009";

const SAMPLE_TWO = [
  ["PaymentOKURL", "www.OKUrl.com.mk"],
  ["PaymentFailURL", "www.FailUrl.com.mk"],
  ["AmountToPay", "100"],
  ["AmountCurrency", "MKD"],
  ["PayToMerchant", "1234567890"],
  ["Details1", "Detali 1"],
  ["Details2", "123"],
  ["MerchantName", "ImeNaTrgovecot"],
  ["FirstName", "Petar"],
  ["LastName", "Petrevski"],
  ["Telephone", "38977777777"],
  ["Email", "petarp@gmail.com"],
  ["Zip", "1000"],
  ["Address", "KJP 1/2"],
  ["City", "Skopje"],
  ["Country", "807"],
  ["OriginalAmount", "10"],
  ["OriginalCurrency", "EUR"],
];
const SAMPLE_TWO_NAMES =
  "PaymentOKURL,PaymentFailURL,AmountToPay,AmountCurrency,PayToMerchant,Details1,Details2,MerchantName," +
  "FirstName,LastName,Telephone,Email,Zip,Address,City,Country,OriginalAmount,OriginalCurrency,";
const SAMPLE_TWO_LENGTHS = "016018003003010008003014005009011016004007006003002003";
const RETURN_HEADER =
  "19PaymentFailURL,PaymentOKURL,AmountToPay,AmountCurrency,PayToMerchant,Details1,Details2,MerchantName," +
  "FirstName,LastName,Telephone,Email,Zip,Address,City,Country,OriginalAmount,OriginalCurrency,cPayPaymentRef," +
  "018016003003010008003014005009011016004007006003002003006";

/* The order of the merchant's redirect template: CPAY_REQUEST_ORDER in src/cpay/lib/checksum.php. */
const REQUEST_ORDER = ["AmountToPay", "PayToMerchant", "MerchantName", "AmountCurrency", "Details1", "Details2", "PaymentOKURL", "PaymentFailURL"];

test("the test key is the specification's public one", () => {
  assert.equal(TEST_KEY, "TEST_PASS");
});

test("sample 1 of the specification (8 parameters)", () => {
  assert.equal(checksumHeader(SAMPLE_ONE), SAMPLE_ONE_HEADER);
  assert.deepEqual(cpayChecksum(SAMPLE_ONE, TEST_KEY), { header: SAMPLE_ONE_HEADER, checksum: "34F2872495067872C7D11C4D0F6A3DE2" });
});

test("sample 2 of the specification (18 parameters)", () => {
  const header = `18${SAMPLE_TWO_NAMES}${SAMPLE_TWO_LENGTHS}`;
  assert.equal(checksumHeader(SAMPLE_TWO), header);
  assert.deepEqual(cpayChecksum(SAMPLE_TWO, TEST_KEY), { header, checksum: "1AEB4E68DCF02D51C54A269EC26D94DB" });
});

test("sample 3 of the specification: the notice cPay sends back for sample 2", () => {
  const notice = returnNotice(SAMPLE_TWO, TEST_KEY, { cPayPaymentRef: "123456" });
  assert.ok(notice.ReturnCheckSumHeader.startsWith("19PaymentFailURL,PaymentOKURL,"), "the first two names are swapped");
  assert.ok(
    notice.ReturnCheckSumHeader.endsWith("OriginalCurrency,cPayPaymentRef,018016003003010008003014005009011016004007006003002003006"),
    "cPayPaymentRef is appended, then the lengths",
  );
  assert.equal(notice.ReturnCheckSumHeader, RETURN_HEADER);
  assert.equal(notice.ReturnCheckSum, "97F4E18E88A48D4BAA1742164A3AFD8B");
  assert.equal(notice.cPayPaymentRef, "123456");
  for (const [name, value] of SAMPLE_TWO) assert.equal(notice[name], value, name);
});

test("returnNotice adds empty fields that are not signed, and never replaces a field of the request", () => {
  const notice = returnNotice(SAMPLE_ONE, TEST_KEY, { cPayPaymentRef: "123456" });
  assert.equal(notice.ServiceID, "");
  assert.equal(notice.Details3, "");
  assert.equal(notice.FirstName, "");
  for (const name of ["ServiceID", "Details3", "FirstName"]) {
    assert.ok(!notice.ReturnCheckSumHeader.includes(`${name},`), `${name} is not signed`);
  }
  assert.equal(returnNotice(SAMPLE_TWO, TEST_KEY).FirstName, "Petar", "a FirstName of the request stays");
  assert.deepEqual(
    Object.keys(notice).sort(),
    [...SAMPLE_ONE.map(([name]) => name), "cPayPaymentRef", "ServiceID", "Details3", "FirstName", "ReturnCheckSumHeader", "ReturnCheckSum"].sort(),
  );
});

test("returnNotice without a cPay reference signs only the request's fields", () => {
  const notice = returnNotice(SAMPLE_ONE, TEST_KEY);
  assert.ok(!("cPayPaymentRef" in notice));
  assert.equal(
    notice.ReturnCheckSumHeader,
    "08PaymentFailURL,PaymentOKURL,AmountToPay,AmountCurrency,PayToMerchant,Details1,Details2,MerchantName,027025005003010017011009",
  );
  const values = "https://bookstore/fail.htmlhttps://bookstore/ok.html12300MKD1000000003purchase of booksOrder 25467Bookstore";
  const expected = createHash("md5").update(`${notice.ReturnCheckSumHeader}${values}TEST_PASS`, "utf8").digest("hex").toUpperCase();
  assert.equal(notice.ReturnCheckSum, expected);
});

test("returnNotice echoes the request's own checksum fields without signing them", () => {
  const request = cpayChecksum(SAMPLE_ONE, TEST_KEY);
  const posted = [...SAMPLE_ONE, ["CheckSumHeader", request.header], ["CheckSum", request.checksum]];
  const notice = returnNotice(posted, TEST_KEY, { cPayPaymentRef: "123456" });
  const plain = returnNotice(SAMPLE_ONE, TEST_KEY, { cPayPaymentRef: "123456" });
  assert.equal(notice.CheckSum, request.checksum);
  assert.equal(notice.CheckSumHeader, request.header);
  assert.equal(notice.ReturnCheckSumHeader, plain.ReturnCheckSumHeader);
  assert.equal(notice.ReturnCheckSum, plain.ReturnCheckSum);
});

test("returnNotice signs with the key it is given and leaves its input alone", () => {
  const pairs = SAMPLE_ONE.map((pair) => [...pair]);
  const notice = returnNotice(pairs, "another key", { cPayPaymentRef: 123456 });
  assert.deepEqual(pairs, SAMPLE_ONE);
  assert.equal(notice.cPayPaymentRef, "123456", "a number is sent as text");
  assert.notEqual(notice.ReturnCheckSum, returnNotice(SAMPLE_ONE, TEST_KEY, { cPayPaymentRef: "123456" }).ReturnCheckSum);
  assert.ok(!Object.values(notice).some((value) => value.includes("another key")), "the key is in no field");
});

test("lengths count code points, not bytes or UTF-16 units", () => {
  assert.equal(checksumHeader([["Details1", "Тёст Strîñğ"]]), "01Details1,011");
  assert.equal(checksumHeader([["Details1", "\u{1F600}"]]), "01Details1,001");
  const expected = createHash("md5").update(Buffer.from("01Details1,004Тёст" + "TEST_PASS", "utf8")).digest("hex").toUpperCase();
  assert.equal(cpayChecksum([["Details1", "Тёст"]], TEST_KEY).checksum, expected, "the bytes hashed are UTF-8");
});

test("the header for the eight request parameters in the request order", () => {
  const byName = new Map(SAMPLE_ONE);
  const pairs = REQUEST_ORDER.map((name) => [name, byName.get(name)]);
  assert.equal(
    checksumHeader(pairs),
    "08AmountToPay,PayToMerchant,MerchantName,AmountCurrency,Details1,Details2,PaymentOKURL,PaymentFailURL,005010009003017011025027",
  );
});

test("a header counts at most 99 parameters and 999 characters", () => {
  const many = (count) => Array.from({ length: count }, (_, i) => [`P${i + 1}`, "x"]);
  assert.ok(checksumHeader(many(99)).startsWith("99P1,P2,"));
  assert.equal(checksumHeader([["Details1", "ж".repeat(999)]]), "01Details1,999");
  assert.throws(() => checksumHeader(many(100)), /cannot be signed/);
  assert.throws(() => checksumHeader([["Details1", "ж".repeat(1000)]]), /cannot be signed/);
  assert.throws(() => checksumHeader([]), /cannot be signed/);
});

test("checksumHeader refuses what cannot be signed", () => {
  const bad = {
    "an empty name": [["", "x"]],
    "a name with a comma": [["A,B", "x"]],
    "a name with a space": [["Details 1", "x"]],
    "a Cyrillic name": [["Име", "x"]],
    "a name that ends with a line break": [["Details1\n", "x"]],
    "a number as value": [["AmountToPay", 12300]],
    "no value": [["Details1"]],
    "null as value": [["Details1", null]],
    "an empty value": [["Details1", ""]],
    "half a surrogate pair": [["Details1", "\uD83D"]],
    "an object instead of pairs": { Details1: "x" },
    "a name twice": [["Details1", "x"], ["Details1", "y"]],
  };
  for (const [label, pairs] of Object.entries(bad)) {
    assert.throws(() => checksumHeader(pairs), /cannot be signed/, label);
    assert.throws(() => cpayChecksum(pairs, TEST_KEY), /cannot be signed/, `cpayChecksum, ${label}`);
  }
});

test("cpayChecksum needs a key", () => {
  assert.throws(() => cpayChecksum(SAMPLE_ONE, ""), /key/);
  assert.throws(() => cpayChecksum(SAMPLE_ONE), /key/);
  assert.throws(() => returnNotice(SAMPLE_ONE, ""), /key/);
});

test("formBody encodes fields the way a browser form does", () => {
  assert.equal(formBody({ Details1: "booking 4567890123", AmountToPay: "2180200" }), "Details1=booking+4567890123&AmountToPay=2180200");
  assert.equal(formBody([["a", "1"], ["a", "2"]]), "a=1&a=2", "pairs keep their order and may repeat a name");
  assert.equal(formBody({}), "");
  const fields = { url: "https://www.oazahotel.test/cpay/ok.php?a=1&b=2", text: "Тёст + 100%", empty: "", "odd name&=": "x" };
  const body = formBody(fields);
  assert.match(body, /^[A-Za-z0-9%+&=._*-]+$/, "only characters a form body may carry");
  assert.deepEqual(Object.fromEntries(new URLSearchParams(body)), fields);
});

test("formBody carries a whole notice", () => {
  const notice = returnNotice(SAMPLE_TWO, TEST_KEY, { cPayPaymentRef: "123456" });
  assert.deepEqual(Object.fromEntries(new URLSearchParams(formBody(notice))), notice);
});
