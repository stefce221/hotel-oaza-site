/* Hotel Oaza — Eleventy config (grows in Task 8). */
export default function (eleventyConfig) {
  eleventyConfig.addPassthroughCopy({
    "src/images": "images",
    "src/assets/img": "assets/img",
    "src/assets/js": "assets/js",
  });

  return {
    dir: { input: "src", output: "_site", includes: "_includes", data: "_data" },
    pathPrefix: process.env.PATH_PREFIX || "/",
    templateFormats: ["njk", "11ty.js"],
    htmlTemplateEngine: "njk",
  };
}
