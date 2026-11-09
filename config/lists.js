/* Hotel Oaza — pick entries of a data map in a page-specific order (e.g. halls). */

/**
 * @param {Record<string, object>} map
 * @param {string[]} keys
 * @returns {object[]} copies of the entries, each with its `key`
 */
export function inOrder(map, keys) {
  if (!Array.isArray(keys)) {
    throw new Error("inOrder needs a list of keys, e.g. hallOrder in the page's front matter");
  }
  return keys.map((key) => {
    if (!map[key]) throw new Error(`Unknown entry "${key}"`);
    return { key, ...map[key] };
  });
}
