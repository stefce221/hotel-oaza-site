/* One-off: split src/assets/css/sections.css into src/assets/css/sections/NN-name.css. */
import { mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { fileFor, ORIGINAL_SHA256, sha256, splitCss } from "./lib/css-split.mjs";

const css = readFileSync("src/assets/css/sections.css", "utf8").replace(/\r\n/g, "\n");
if (sha256(css) !== ORIGINAL_SHA256) throw new Error("sections.css is not the original file");
const chunks = splitCss(css);
if (chunks.length !== 17) throw new Error(`Expected 17 sections, found ${chunks.length}`);
if (chunks.map((c) => c.text).join("") !== css) throw new Error("The pieces do not join back to the original");

mkdirSync("src/assets/css/sections", { recursive: true });
for (const chunk of chunks) {
  writeFileSync(`src/assets/css/sections/${fileFor(chunk.title)}`, chunk.text);
  console.log(`${fileFor(chunk.title)}  ← ${chunk.title}`);
}
