/* Builds /assets/css/site.css: tokens → base → sections → fx → additions. */
import { bundleCss, CSS_BUNDLE_URL } from "../../../config/assets.js";

export default class SiteCss {
  data() {
    return { permalink: CSS_BUNDLE_URL, eleventyExcludeFromCollections: true };
  }

  render() {
    return bundleCss();
  }
}
