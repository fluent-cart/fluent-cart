/**
 * Merge a freshly loaded `{label, value, children}` product tree into the one seen so far.
 * A search replaces the picker's data, so chips read from this to keep names for hidden picks.
 *
 * @param {Array} known
 * @param {Array} incoming
 * @return {Array}
 */
export function mergeProductTree(known, incoming) {
    const merged = new Map();

    [...(known || []), ...(incoming || [])].forEach(product => {
        if (!product || product.value === undefined || product.value === null) {
            return;
        }

        const previous = merged.get(product.value);
        const children = new Map();

        [...(previous?.children || []), ...(product.children || [])].forEach(child => {
            children.set(child.value, child);
        });

        merged.set(product.value, {...product, children: [...children.values()]});
    });

    return [...merged.values()];
}

/**
 * Remember `value => label` for every option loaded so far.
 *
 * @param {Object} known
 * @param {Array<{value: number|string, label: string}>} options
 * @return {Object}
 */
export function mergeOptionLabels(known, options) {
    const labels = {...(known || {})};

    (options || []).forEach(option => {
        labels[option.value] = option.label;
    });

    return labels;
}

/**
 * One whole-item chip group per picked id, for buildProductChips().
 * An id with no known label yet shows the raw id, as el-select does.
 *
 * @param {Array<number|string>} ids
 * @param {Object} labels
 */
export function groupIdsByLabels(ids, labels) {
    return (ids || []).map(id => ({
        key: `id-${id}`,
        productName: (labels || {})[id] ?? String(id),
        total: 0,
        labels: [],
    }));
}
