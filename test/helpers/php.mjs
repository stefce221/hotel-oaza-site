/* Starts PHP's built-in server on src/ for the HTTP tests, with a throw-away private folder and config. */
import { spawn } from "node:child_process";
import { mkdirSync, mkdtempSync, writeFileSync } from "node:fs";
import http from "node:http";
import { tmpdir } from "node:os";
import path from "node:path";

export const PHP = process.env.PHP_BIN || "php";

/**
 * Complete cPay payment settings for tests: the merchant of the specification's first sample and its public test
 * key, with return addresses on a host that does not exist. Pass as `makePrivateDir({ cpay: TEST_CPAY })`, or
 * spread it to change one setting.
 */
export const TEST_CPAY = Object.freeze({
  merchant_id: "1000000003",
  merchant_name: "Bookstore",
  key: "TEST_PASS",
  ok_url: "https://www.oazahotel.test/cpay/ok.php",
  fail_url: "https://www.oazahotel.test/cpay/fail.php",
  max_amount: 300000,
});

/** Text as a PHP string in single quotes: there only the backslash and the single quote need escaping. */
function phpString(text) {
  return `'${String(text).replace(/\\/g, "\\\\").replace(/'/g, "\\'")}'`;
}

/** One cPay setting as PHP code: text quoted and escaped, a number bare. Anything else is a mistake in the test. */
function phpSetting(name, value) {
  if (typeof value === "string") return phpString(value);
  if (typeof value === "number" && Number.isFinite(value)) return String(value);
  throw new TypeError(`cPay setting "${name}" must be text or a number`);
}

/** The config's 'cpay' line, or nothing when the test asks for neither push addresses nor payment settings. */
function cpaySection(pushIps, settings) {
  const entries = [
    ...(pushIps ? [`'push_ips' => [${pushIps.map(phpString).join(", ")}]`] : []),
    ...Object.entries(settings ?? {}).map(([name, value]) => `${phpString(name)} => ${phpSetting(name, value)}`),
  ];
  return entries.length > 0 ? `\n    'cpay' => [${entries.join(", ")}],` : "";
}

/**
 * A private folder with config.php (file transport). Paths use forward slashes, which PHP accepts on Windows.
 * `cpayPushIps` adds the config's cpay.push_ips list (the addresses cPay's own pushes come from).
 * `cpay` adds further cPay settings (see TEST_CPAY): text is written as a PHP string, a number as it is.
 */
export function makePrivateDir({ cpayPushIps = null, cpay = null } = {}) {
  const cpayLine = cpaySection(cpayPushIps, cpay); /* first, so a mistake in the test leaves no folder behind */
  const dir = mkdtempSync(path.join(tmpdir(), "oaza-php-")).replace(/\\/g, "/");
  const config = `${dir}/config.php`;
  mkdirSync(`${dir}/mail`);
  writeFileSync(
    config,
    `<?php
return [
    'private_dir' => ${phpString(dir)},
    'mail' => [
        'transport' => 'file',
        'file' => ${phpString(`${dir}/mail/sent.jsonl`)},
        'from' => 'web@oazahotel.com.mk',
        'from_name' => 'Hotel Oaza website',
        'to' => 'info@oazahotel.com.mk',
    ],
    'rate' => ['limit' => 5, 'window' => 3600, 'salt' => 'test-salt'],${cpayLine}
];
`,
  );
  return { dir, config };
}

/** One HTTP request; resolves with status, headers and body text. */
export function request(port, method, urlPath, { body = "", headers = {} } = {}) {
  return new Promise((resolve, reject) => {
    const req = http.request(
      { host: "127.0.0.1", port, method, path: urlPath, headers: { "Content-Length": Buffer.byteLength(body), ...headers } },
      (res) => {
        let text = "";
        res.setEncoding("utf8");
        res.on("data", (chunk) => (text += chunk));
        res.on("end", () => resolve({ status: res.statusCode, headers: res.headers, body: text }));
      },
    );
    req.on("error", reject);
    req.end(body);
  });
}

/** Starts `php -S 127.0.0.1:<port> -t src` (PHP errors go to `errorLog` when given); resolves with an async stop function. */
export async function startPhp(port, configPath, { errorLog = null } = {}) {
  /* Windows lets a second server share a busy port, so look first: an answer now means a stale server. */
  const stale = await request(port, "GET", "/").then(() => true, () => false);
  if (stale) throw new Error(`Something already answers on port ${port}: a stale server would be tested instead. Stop it and rerun.`);
  const logArgs = errorLog ? ["-d", `error_log="${errorLog}"`] : [];
  const child = spawn(PHP, [...logArgs, "-S", `127.0.0.1:${port}`, "-t", "src"], {
    env: { ...process.env, OAZA_CONFIG: configPath },
    stdio: "ignore",
  });
  let startError = null;
  child.on("error", (error) => (startError = error));
  const stop = () =>
    new Promise((resolve) => {
      if (child.exitCode !== null || startError) return resolve();
      child.once("exit", resolve);
      child.kill();
    });
  for (let attempt = 0; attempt < 50; attempt += 1) {
    if (startError) throw new Error(`Cannot start ${PHP}: ${startError.message}. Install PHP 8 (Task 25) or set PHP_BIN.`);
    try {
      await request(port, "GET", "/");
      await new Promise((resolve) => setTimeout(resolve, 150)); /* a child that cannot bind the port exits within moments */
      if (child.exitCode !== null) {
        throw new Error(`PHP exited (code ${child.exitCode}) but something still answers on port ${port}: a stale server is running there. Stop it and rerun.`);
      }
      return stop;
    } catch {
      await new Promise((resolve) => setTimeout(resolve, 100));
    }
  }
  await stop();
  throw new Error(`PHP's server did not start on port ${port}`);
}
