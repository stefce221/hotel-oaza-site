/* Fails the live build while any [[HOTEL: …]] marker is left in _site; lists each one. */
import { readdirSync, readFileSync } from "node:fs";
import path from "node:path";
import { findMarkers } from "../config/markers.js";

const SITE = "_site";
const found = [];
for (const rel of readdirSync(SITE, { recursive: true })) {
  if (!String(rel).endsWith(".html")) continue;
  for (const marker of findMarkers(readFileSync(path.join(SITE, rel), "utf8"))) {
    found.push(`${String(rel).split(path.sep).join("/")}: ${marker}`);
  }
}

if (found.length) {
  console.error(`The live build still has ${found.length} hotel marker(s). Fill them in first:\n${found.join("\n")}`);
  process.exit(1);
}
console.log("No hotel markers left.");
