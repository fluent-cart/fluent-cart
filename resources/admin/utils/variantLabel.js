/**
 * Build a human-readable label for an attribute-variant. When the variant
 * carries an `attr_map` (advanced variations), each relation's hydrated
 * `title` is joined with " / " — e.g. "Red / Cotton" — so option lists and
 * collapsed select tags surface the combination instead of the parent
 * product title (which is identical across all variants).
 *
 * Falls back to `variation_title` for simple variants where no `attr_map`
 * exists, and finally to an empty string so callers can safely render.
 */
/**
 * Optional `groupOrder` is an array of group_ids in the merchant-configured
 * order (typically from `product.detail.other_info.attribute_config`). When
 * provided, attr_map rows are sorted to match — so the label leads with the
 * same group the variant table groups by, e.g. "Single Site / 4 GB" instead
 * of the server's arbitrary attr_map order. Unknown groups go to the end.
 * Falls back to ascending group_id when no order is supplied.
 */
export const getVariantLabel = (variant, groupOrder = null) => {
    if (!variant) return '';
    const map = Array.isArray(variant.attr_map) ? variant.attr_map : [];
    if (map.length) {
        const orderIds = Array.isArray(groupOrder)
            ? groupOrder.map(id => parseInt(id, 10))
            : null;
        const indexOf = (rel) => {
            const gid = parseInt(rel && rel.group_id, 10);
            if (orderIds) {
                const idx = orderIds.indexOf(gid);
                return idx === -1 ? Number.MAX_SAFE_INTEGER : idx;
            }
            return gid || 0;
        };
        const ordered = [...map].sort((a, b) => indexOf(a) - indexOf(b));
        const parts = ordered
            .map(rel => (rel && rel.title) ? String(rel.title).trim() : '')
            .filter(Boolean);
        if (parts.length) return parts.join(' / ');
    }
    return variant.variation_title || '';
};

/**
 * Pull the merchant-configured group order from a product payload, in the
 * shape `getVariantLabel(variant, groupOrder)` expects.
 */
export const getProductGroupOrder = (product) => {
    const config = product?.detail?.other_info?.attribute_config;
    if (!Array.isArray(config)) return null;
    return config
        .map(attr => parseInt(attr && attr.group_id, 10))
        .filter(id => !Number.isNaN(id));
};
