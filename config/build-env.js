/* Hotel Oaza — which build this is (OAZA_ENV) and where it is served from (PATH_PREFIX), checked once at startup. */

const ENVS = ["dev", "preview", "live"];
const PATH_PREFIX_PATTERN = /^\/([\w.-]+\/)*$/;

/**
 * @param {Record<string, string|undefined>} [vars] defaults to process.env
 * @returns {{ env: "dev"|"preview"|"live", pathPrefix: string }}
 */
export function readBuildEnv(vars = process.env) {
  const env = vars.OAZA_ENV || "dev";
  const pathPrefix = vars.PATH_PREFIX || "/";
  if (!ENVS.includes(env)) {
    throw new Error(`OAZA_ENV must be one of ${ENVS.join(", ")}, got ${JSON.stringify(env)}`);
  }
  if (!PATH_PREFIX_PATTERN.test(pathPrefix)) {
    throw new Error(
      `PATH_PREFIX must look like "/" or "/hotel-oaza-site/" (slash on both ends), got ${JSON.stringify(pathPrefix)}. ` +
        `Git Bash rewrites a value that starts with "/" into a Windows path when it is set inline; use npm run build:preview, or set MSYS_NO_PATHCONV=1.`,
    );
  }
  return { env, pathPrefix };
}
