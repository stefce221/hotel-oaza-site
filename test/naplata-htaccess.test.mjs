import { test } from "node:test";
import assert from "node:assert/strict";
import { readdirSync, readFileSync } from "node:fs";

/* Apache is not run here: src/naplata/.htaccess is read, and its FilesMatch pattern is tried the way Apache would. */
const read = (file) => readFileSync(new URL(`../src/${file}`, import.meta.url), "utf8").replace(/\r\n/g, "\n");
const htaccess = read("naplata/.htaccess");
const lines = htaccess.split("\n").map((line) => line.trim());
/* Design section 5, in this order. */
const EXPECTED = [
  "Options -Indexes",
  "DirectoryIndex index.php",
  "ErrorDocument 401 default",
  "AuthType Basic",
  'AuthName "Hotel Oaza"',
  'AuthUserFile "/home/oazahote/.htpasswds/public_html/naplata/passwd"',
  "Require valid-user",
  "<If \"%{HTTPS} != 'on'\">",
  "Require all denied",
  "</If>",
  '<FilesMatch "^(?!(?:index|start)\\.php$|naplata$).+">',
  "Require all denied",
  "</FilesMatch>",
];

test("the staff folder's .htaccess carries every line of the design, in order, and no other directive", () => {
  const directives = lines.filter((line) => line !== "" && !line.startsWith("#"));
  assert.deepEqual(directives, EXPECTED);
});

test("nothing in it grants access: a grant inside FilesMatch would replace the password", () => {
  assert.doesNotMatch(htaccess, /Require\s+all\s+granted/i);
  assert.doesNotMatch(htaccess, /Satisfy\s+any/i);
  assert.equal(lines.filter((line) => /^Require\b/i.test(line)).length, 3, "the password, and the two refusals");
  assert.equal(lines.indexOf("Require valid-user") < lines.indexOf("<If \"%{HTTPS} != 'on'\">"), true, "the password is asked at folder level");
});

test("every file other than index.php and start.php is refused, and the folder's own name is not", () => {
  const patterns = [...htaccess.matchAll(/^\s*<FilesMatch "([^"]+)">\s*$/gm)].map(([, pattern]) => pattern);
  assert.equal(patterns.length, 1, "one FilesMatch");
  const refused = new RegExp(patterns[0]);
  for (const name of [".user.ini", ".htaccess", "x.php", "index.php.bak", "start.phps", "index.phpx", "xindex.php", "INDEX.PHP", "index.html", "start.php~", "passwd",
    "naplata.php", "naplatax", "xnaplata", "naplata~", "NAPLATA"]) {
    assert.equal(refused.test(name), true, `${name} is refused`);
  }
  /* A request for /naplata (no slash) is matched against the folder's name; refused, it never reaches mod_dir's redirect to /naplata/. */
  for (const name of ["index.php", "start.php", "naplata", ""]) {
    assert.equal(refused.test(name), false, `${JSON.stringify(name)} is left to the password alone`);
  }
});

test("the folder holds the two entry points and the two server files, and PHP shows no warnings there", () => {
  assert.deepEqual(readdirSync(new URL("../src/naplata/", import.meta.url)).sort(), [".htaccess", ".user.ini", "index.php", "start.php"]);
  assert.equal(read("naplata/.user.ini"), read("cpay/.user.ini"), "the same PHP settings as the cPay folder");
  assert.match(read("naplata/.user.ini"), /^display_errors = Off$/m);
});

test("the entry points only call the library, which the cPay folder closes to the web", () => {
  for (const [file, call] of [["index.php", "staff_index();"], ["start.php", "staff_start();"]]) {
    const code = read(`naplata/${file}`).replace(/\/\*[\s\S]*?\*\//g, "").split("\n").map((line) => line.trim()).filter(Boolean);
    assert.deepEqual(code, ["<?php", "declare(strict_types=1);", "require dirname(__DIR__) . '/cpay/lib/staff-app.php';", call], file);
  }
  const closed = read("cpay/.htaccess");
  assert.match(closed, /<FilesMatch "\.\*">\s*Require all denied\s*<\/FilesMatch>/);
  assert.match(closed, /<FilesMatch "\^\(ok\|fail\)\\\.php\$">/, "only ok.php and fail.php are opened again");
});
