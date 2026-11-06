/* Remove retired keys from the converted dictionaries, in both languages at once.
   node scripts/i18n-drop.mjs nav.dining nav.events          → src/i18n/{en,mk}/base.json
   node scripts/i18n-drop.mjs --runtime "mail.*" status.sent  → src/i18n/runtime/base.json */
import { readFileSync, writeFileSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

/** @returns {{ table: Record<string, string>, removed: string[] }} a copy without the matching keys */
export function dropKeys(table, patterns) {
  const matches = (key) => patterns.some((p) => (p.endsWith(".*") ? key.startsWith(p.slice(0, -1)) : key === p));
  const removed = Object.keys(table).filter(matches);
  return { table: Object.fromEntries(Object.entries(table).filter(([key]) => !matches(key))), removed };
}

function rewrite(file, update) {
  const data = JSON.parse(readFileSync(file, "utf8"));
  writeFileSync(file, `${JSON.stringify(update(data), null, 2)}\n`);
}

/* Compare as file paths: import.meta.url percent-encodes spaces, and this project's folder name has them. */
if (path.resolve(fileURLToPath(import.meta.url)) === path.resolve(process.argv[1])) {
  const args = process.argv.slice(2);
  const runtime = args[0] === "--runtime";
  const patterns = runtime ? args.slice(1) : args;
  if (!patterns.length) throw new Error("Name at least one key or prefix.*");

  const report = (where, removed) => {
    const missing = patterns.filter((p) => !removed.some((k) => (p.endsWith(".*") ? k.startsWith(p.slice(0, -1)) : k === p)));
    if (missing.length) throw new Error(`${where}: no key matches ${missing.join(", ")}`);
    console.log(`${where}: removed ${removed.join(", ")}`);
  };

  if (runtime) {
    rewrite("src/i18n/runtime/base.json", (data) =>
      Object.fromEntries(
        Object.entries(data).map(([lang, table]) => {
          const result = dropKeys(table, patterns);
          report(`runtime ${lang}`, result.removed);
          return [lang, result.table];
        }),
      ),
    );
  } else {
    for (const lang of ["en", "mk"]) {
      rewrite(`src/i18n/${lang}/base.json`, (data) => {
        const result = dropKeys(data, patterns);
        report(lang, result.removed);
        return result.table;
      });
    }
  }
}
