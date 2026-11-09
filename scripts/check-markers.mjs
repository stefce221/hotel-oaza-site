/* Fails the live build while any [[HOTEL: …]] marker is left in _site; lists each one,
   and flags text that looks like a marker but is mistyped or URL-encoded. */
import { readdirSync, readFileSync } from "node:fs";
import path from "node:path";
import { findMalformedMarkers, findMarkers } from "../config/markers.js";

const SITE = "_site";
const SCANNED = /\.(?:html|xml|txt|json)$/;
const SKIPPED = ".oaza-build.json";
const found = [];
const malformed = [];
for (const entry of readdirSync(SITE, { recursive: true })) {
  const rel = String(entry).split(path.sep).join("/");
  if (!SCANNED.test(rel) || path.basename(rel) === SKIPPED) continue;
  const text = readFileSync(path.join(SITE, rel), "utf8");
  for (const marker of findMarkers(text)) found.push(`${rel}: ${marker}`);
  for (const snippet of findMalformedMarkers(text)) malformed.push(`malformed marker in ${rel}: ${snippet}`);
}

if (found.length || malformed.length) {
  const count = found.length + malformed.length;
  const lines = [...found, ...malformed].join("\n");
  console.error(`The live build still has ${count} hotel marker(s). Fill them in first:\n${lines}`);
  process.exit(1);
}
console.log("No hotel markers left.");
