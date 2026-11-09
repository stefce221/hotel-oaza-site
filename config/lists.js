/* Hotel Oaza — pick entries of a data map in a page-specific order (e.g. halls). */

/**
 * @param {Record<string, object>} map
 * @param {string[]} keys
 * @returns {object[]} copies of the entries, each with its `key`
 */
export function inOrder(map, keys) {
  return keys.map((key) => {
    if (!map[key]) throw new Error(`Unknown entry "${key}"`);
    return { key, ...map[key] };
  });
}
