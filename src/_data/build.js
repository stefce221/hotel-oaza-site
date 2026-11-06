/* Which build this is: dev (npm start), preview (GitHub Pages) or live (MKhost). */
export default {
  env: process.env.OAZA_ENV || "dev",
  pathPrefix: process.env.PATH_PREFIX || "/",
};
