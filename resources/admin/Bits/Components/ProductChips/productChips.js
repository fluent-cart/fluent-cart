import translate from "@/utils/translator/Translator";

/**
 * Turn picked variations into one chip per product.
 *
 * @param {Array<{key: string, productName: ?string, total: number, labels: Array<string>}>} groups
 *        `total` is the product's variation count (0 = unknown); empty `labels` = the whole product.
 * @return {Array<{key: string, text: string, variations: Array<string>}>}
 */
export function buildProductChips(groups) {
    return groups.map(({key, productName, total, labels}) => {
        if (!productName) {
            /* translators: %1$s: comma-separated ids of picked items whose product could not be found */
            return {key, text: translate('Unknown | %1$s', labels.join(', ')), variations: []};
        }

        if (!labels.length || (total && labels.length >= total)) {
            return {key, text: productName, variations: []};
        }

        if (!total) {
            return {key, text: translate('%1$s (%2$s picked)', productName, labels.length), variations: labels};
        }

        return {
            key,
            text: translate('%1$s (%2$s of %3$s)', productName, labels.length, total),
            variations: labels,
        };
    });
}

/**
 * How many chips fit on one row, leaving room for the "+N" chip when some are hidden.
 * Always shows at least one chip; its text truncates if even that is too wide.
 *
 * @param {Array<number>} widths  rendered width of each chip, in order
 * @param {number} plusWidth      width of the "+N" chip
 * @param {number} gap            space between chips
 * @param {number} available      row width
 * @param {number} max            upper bound (Infinity for none)
 * @return {number}
 */
export function fitChipCount(widths, plusWidth, gap, available, max = Infinity) {
    const total = Math.min(widths.length, max);
    let used = 0;

    for (let count = 0; count < total; count++) {
        used += widths[count] + (count ? gap : 0);
        const hidden = widths.length - (count + 1);
        const needed = used + (hidden ? gap + plusWidth : 0);

        if (needed > available) {
            return Math.max(1, count);
        }
    }

    return Math.max(widths.length ? 1 : 0, total);
}

/**
 * Group picked variation ids by product using a `{label, value, children}` product tree.
 * Ids missing from the tree land in one "Unknown" group.
 *
 * @param {Array<number|string>} ids
 * @param {Array<{label: string, value: number|string, children?: Array}>} tree
 */
export function groupIdsByTree(ids, tree) {
    const variationIndex = {};
    (tree || []).forEach(product => {
        const children = product.children || [];
        children.forEach(variation => {
            variationIndex[variation.value] = {product, label: variation.label};
        });
        // A product node without children is itself the pickable item.
        if (!children.length) {
            variationIndex[product.value] = {product, label: product.label};
        }
    });

    const groups = {};
    (ids || []).forEach(id => {
        const match = variationIndex[id];
        const key = match ? `id-${match.product.value}` : 'unknown';

        if (!groups[key]) {
            groups[key] = {
                key,
                productName: match ? match.product.label : null,
                total: match ? (match.product.children || []).length : 0,
                labels: [],
            };
        }
        groups[key].labels.push(match ? match.label : String(id));
    });

    return Object.values(groups).map(group => {
        // A childless product picked whole should read as just its name.
        return group.total === 0 && group.productName ? {...group, labels: []} : group;
    });
}
