/* Which build this is: dev (npm start), preview (GitHub Pages) or live (MKhost). Same check as the Eleventy config. */
import { readBuildEnv } from "../../config/build-env.js";

export default readBuildEnv(process.env);
