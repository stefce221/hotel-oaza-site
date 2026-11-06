import { test } from "node:test";
import assert from "node:assert/strict";
import { readBuildEnv } from "../config/build-env.js";

test("readBuildEnv defaults to a dev build at the site root", () => {
  assert.deepEqual(readBuildEnv({}), { env: "dev", pathPrefix: "/" });
  assert.deepEqual(readBuildEnv({ OAZA_ENV: "", PATH_PREFIX: "" }), { env: "dev", pathPrefix: "/" });
});

test("readBuildEnv accepts the three build kinds and a folder prefix", () => {
  for (const env of ["dev", "preview", "live"]) assert.equal(readBuildEnv({ OAZA_ENV: env }).env, env);
  assert.deepEqual(readBuildEnv({ OAZA_ENV: "preview", PATH_PREFIX: "/hotel-oaza-site/" }), {
    env: "preview",
    pathPrefix: "/hotel-oaza-site/",
  });
  assert.equal(readBuildEnv({ PATH_PREFIX: "/a/b.c_d/" }).pathPrefix, "/a/b.c_d/");
});

test("readBuildEnv rejects an unknown OAZA_ENV", () => {
  assert.throws(() => readBuildEnv({ OAZA_ENV: "production" }), /OAZA_ENV must be one of dev, preview, live, got "production"/);
});

test("readBuildEnv rejects a PATH_PREFIX that is not /folder/ shaped", () => {
  for (const bad of ["hotel-oaza-site/", "/hotel-oaza-site", "/two words/", "//", "/a//b/", "/a?b/"]) {
    assert.throws(() => readBuildEnv({ PATH_PREFIX: bad }), /PATH_PREFIX must look like/, bad);
  }
});

test("readBuildEnv explains the Git Bash path rewrite", () => {
  assert.throws(
    () => readBuildEnv({ PATH_PREFIX: "C:/Program Files/Git/hotel-oaza-site/" }),
    /PATH_PREFIX must look like .*C:\/Program Files\/Git\/hotel-oaza-site\/.*npm run build:preview/s,
  );
});
