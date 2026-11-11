/* cPay's checksum in JavaScript, for the tests. Written separately from src/cpay/lib/checksum.php so that the two
   check each other; both are checked against the worked examples of cPay's specification (v3.3). */
import { createHash } from "node:crypto";

/** The specification's public test key. The hotel's own key never appears in this repository. */
export const TEST_KEY = "TEST_PASS";

const NAME = /^[A-Za-z0-9_]+$/;
const MOST_PARAMETERS = 99; /* the header counts them in two digits */
const MOST_CHARACTERS = 999; /* and each value's length in three */
/* Fields cPay posts back empty and does not sign. */
const UNSIGNED_EMPTY = ["ServiceID", "Details3", "FirstName"];
/* The request's own checksum fields: cPay echoes them, and they are never among the signed parameters. */
const REQUEST_CHECKSUM_FIELDS = ["CheckSumHeader", "CheckSum"];

function refuse(reason) {
  throw new Error(`cPay test helper: cannot be signed: ${reason}`);
}

/** The length cPay's header gives a value: its code points, so a letter outside ASCII counts once. */
function signedLength(name, value) {
  if (typeof value !== "string" || value === "" || !value.isWellFormed()) refuse(`${name} needs a value of well-formed text`);
  const length = Array.from(value).length;
  if (length > MOST_CHARACTERS) refuse(`${name} has ${length} characters`);
  return length;
}

/** The names of the pairs, after checking that they are pairs with names a header can carry, each once. */
function signedNames(pairs) {
  if (!Array.isArray(pairs) || pairs.length < 1 || pairs.length > MOST_PARAMETERS) refuse("a header names 1 to 99 parameters");
  const names = pairs.map((pair) => (Array.isArray(pair) ? pair[0] : undefined));
  for (const name of names) {
    if (typeof name !== "string" || !NAME.test(name)) refuse(`the name ${JSON.stringify(name)}`);
  }
  if (new Set(names).size !== names.length) refuse("a parameter named twice");
  return names;
}

/**
 * Two-digit count, each name followed by a comma, then one three-digit length per value.
 * @param {[string, string][]} pairs [[name, value], …] in the order they are signed; lengths count code points
 */
export function checksumHeader(pairs) {
  const names = signedNames(pairs);
  const lengths = pairs.map(([name, value]) => String(signedLength(name, value)).padStart(3, "0"));
  return `${String(pairs.length).padStart(2, "0")}${names.map((name) => `${name},`).join("")}${lengths.join("")}`;
}

/**
 * @param {[string, string][]} pairs
 * @returns {{ header: string, checksum: string }} the checksum is the upper-case MD5 of header, values and key (UTF-8)
 */
export function cpayChecksum(pairs, key) {
  if (typeof key !== "string" || key === "") throw new Error("cPay test helper: a checksum needs a key");
  const header = checksumHeader(pairs);
  const values = pairs.map(([, value]) => value).join("");
  return { header, checksum: createHash("md5").update(`${header}${values}${key}`, "utf8").digest("hex").toUpperCase() };
}

/**
 * The fields cPay sends back for a request: the request's fields, cPayPaymentRef when given, a few empty unsigned
 * fields (ServiceID, Details3, FirstName; never in place of a field of the request), ReturnCheckSumHeader (first two
 * names swapped, cPayPaymentRef appended) and ReturnCheckSum. A CheckSumHeader or CheckSum among the request's
 * pairs is echoed and not signed, as cPay does.
 * @param {[string, string][]} requestPairs the request's parameters in the order of its header
 * @returns {Record<string, string>}
 */
export function returnNotice(requestPairs, key, { cPayPaymentRef = null } = {}) {
  if (!Array.isArray(requestPairs)) refuse("the request is a list of pairs");
  const isEcho = ([name]) => REQUEST_CHECKSUM_FIELDS.includes(name);
  const request = requestPairs.filter((pair) => !isEcho(pair));
  const [first, second, ...rest] = request;
  const swapped = second === undefined ? request : [second, first, ...rest];
  const added = cPayPaymentRef === null ? [] : [["cPayPaymentRef", String(cPayPaymentRef)]];
  const { header, checksum } = cpayChecksum([...swapped, ...added], key);
  const posted = Object.fromEntries([...requestPairs, ...added]);
  const empty = UNSIGNED_EMPTY.filter((name) => !Object.hasOwn(posted, name)).map((name) => [name, ""]);
  return { ...posted, ...Object.fromEntries(empty), ReturnCheckSumHeader: header, ReturnCheckSum: checksum };
}

/** The fields as a form body (application/x-www-form-urlencoded): an object, or pairs to keep an order or repeat a name. */
export function formBody(fields) {
  return new URLSearchParams(Array.isArray(fields) ? fields : Object.entries(fields)).toString();
}
