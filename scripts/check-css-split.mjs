/* Joining src/assets/css/sections/*.css (in name order) must give back today's sections.css exactly. */
import { readdirSync, readFileSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { ORIGINAL_SHA256, sha256 } from "./lib/css-split.mjs";

export function cssSplitIntact(dir = "src/assets/css/sections") {
  const joined = readdirSync(dir)
    .filter((f) => f.endsWith(".css"))
    .sort()
    .map((f) => readFileSync(`${dir}/${f}`, "utf8"))
    .join("");
  return sha256(joined) === ORIGINAL_SHA256;
}

/* Compare as file paths: import.meta.url percent-encodes spaces, and this project's folder name has them. */
if (path.resolve(fileURLToPath(import.meta.url)) === path.resolve(process.argv[1])) {
  if (!cssSplitIntact()) {
    console.error("Section CSS no longer joins to the original sections.css. Put new rules in src/assets/css/additions/.");
    process.exit(1);
  }
  console.log(`Section CSS joins to the original (sha256 ${ORIGINAL_SHA256.slice(0, 12)}…)`);
}
