/* Remove retired keys from the converted dictionaries, in both languages at once.
   node scripts/i18n-drop.mjs nav.dining nav.events          → src/i18n/{en,mk}/base.json
   node scripts/i18n-drop.mjs --runtime "mail.*" status.sent  → src/i18n/runtime/base.json */
import { readFileSync, writeFileSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const matchesPattern = (pattern, key) => (pattern.endsWith(".*") ? key.startsWith(pattern.slice(0, -1)) : key === pattern);

/** @returns {{ table: Record<string, string>, removed: string[] }} a copy without the matching keys */
export function dropKeys(table, patterns) {
  const matches = (key) => patterns.some((p) => matchesPattern(p, key));
  const removed = Object.keys(table).filter(matches);
  return { table: Object.fromEntries(Object.entries(table).filter(([key]) => !matches(key))), removed };
}

/** Drop the patterns from every named table. Throws, before anything is written, if a pattern matches nothing in some table.
 *  @param {Record<string, Record<string, string>>} tables label → table
 *  @returns {{ tables: Record<string, Record<string, string>>, lines: string[] }} */
export function planDrop(tables, patterns) {
  const result = {};
  const lines = [];
  for (const [label, table] of Object.entries(tables)) {
    const { table: kept, removed } = dropKeys(table, patterns);
    const missing = patterns.filter((p) => !removed.some((key) => matchesPattern(p, key)));
    if (missing.length) throw new Error(`${label}: no key matches ${missing.join(", ")}`);
    result[label] = kept;
    lines.push(`${label}: removed ${removed.join(", ")}`);
  }
  return { tables: result, lines };
}

const readJson = (file) => JSON.parse(readFileSync(file, "utf8"));
const writeJson = (file, data) => writeFileSync(file, `${JSON.stringify(data, null, 2)}
`);

/* Compare as file paths: import.meta.url percent-encodes spaces, and this project's folder name has them. */
if (process.argv[1] && path.resolve(fileURLToPath(import.meta.url)) === path.resolve(process.argv[1])) {
  const args = process.argv.slice(2);
  const runtime = args[0] === "--runtime";
  const patterns = runtime ? args.slice(1) : args;
  if (!patterns.length) throw new Error("Name at least one key or prefix.*");

  if (runtime) {
    const file = "src/i18n/runtime/base.json";
    const plan = planDrop(Object.fromEntries(Object.entries(readJson(file)).map(([lang, table]) => [`runtime ${lang}`, table])), patterns);
    writeJson(file, Object.fromEntries(Object.entries(plan.tables).map(([label, table]) => [label.replace("runtime ", ""), table])));
    plan.lines.forEach((line) => console.log(line));
  } else {
    const langs = ["en", "mk"];
    const plan = planDrop(Object.fromEntries(langs.map((lang) => [lang, readJson(`src/i18n/${lang}/base.json`)])), patterns);
    langs.forEach((lang) => writeJson(`src/i18n/${lang}/base.json`, plan.tables[lang]));
    plan.lines.forEach((line) => console.log(line));
  }
}
