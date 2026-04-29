/**
 * Column-to-field mapping for link mode sync.
 *
 * Each column has getter/setter pairs for 'product' and/or 'variant' row types.
 * If a column doesn't apply to a row type, it's omitted and silently skipped.
 */

const BASE_COLUMNS = {
    title: {
        product: { get: p => p.post_title, set: (p, v) => p.post_title = v },
        variant: { get: v => v.variation_title, set: (v, val) => v.variation_title = val },
    },
    sku: {
        product: { get: p => p.variants?.[0]?.sku, set: (p, v) => { if (p.variants?.[0]) p.variants[0].sku = v; } },
        variant: { get: v => v.sku, set: (v, val) => v.sku = val },
    },
    status: {
        product: { get: p => p.post_status, set: (p, v) => p.post_status = v },
    },
    description: {
        product: { get: p => p.post_content, set: (p, v) => p.post_content = v },
    },
    short_desc: {
        product: { get: p => p.post_excerpt, set: (p, v) => p.post_excerpt = v },
    },
    product_type: {
        product: { get: p => p.detail?.fulfillment_type, set: (p, v) => { if (p.detail) p.detail.fulfillment_type = v; } },
    },
    pricing_type: {
        product: { get: p => p.detail?.variation_type, set: (p, v) => { if (p.detail) p.detail.variation_type = v; } },
    },
    payment_type: {
        product: { get: p => p.variants?.[0]?.other_info?.payment_type, set: (p, v) => { if (p.variants?.[0]?.other_info) p.variants[0].other_info.payment_type = v; } },
        variant: { get: v => v.other_info?.payment_type, set: (v, val) => { if (v.other_info) v.other_info.payment_type = val; } },
    },
    interval: {
        product: { get: p => p.variants?.[0]?.other_info?.repeat_interval, set: (p, v) => { if (p.variants?.[0]?.other_info) p.variants[0].other_info.repeat_interval = v; } },
        variant: { get: v => v.other_info?.repeat_interval, set: (v, val) => { if (v.other_info) v.other_info.repeat_interval = val; } },
    },
    trial_days: {
        product: { get: p => p.variants?.[0]?.other_info?.trial_days, set: (p, v) => { if (p.variants?.[0]?.other_info) p.variants[0].other_info.trial_days = v; } },
        variant: { get: v => v.other_info?.trial_days, set: (v, val) => { if (v.other_info) v.other_info.trial_days = val; } },
    },
    price: {
        product: { get: p => p.variants?.[0]?.item_price, set: (p, v) => { if (p.variants?.[0]) p.variants[0].item_price = v; } },
        variant: { get: v => v.item_price, set: (v, val) => v.item_price = val },
    },
    compare_price: {
        product: { get: p => p.variants?.[0]?.compare_price, set: (p, v) => { if (p.variants?.[0]) p.variants[0].compare_price = v; } },
        variant: { get: v => v.compare_price, set: (v, val) => v.compare_price = val },
    },
    manage_stock: {
        product: { get: p => p.detail?.manage_stock, set: (p, v) => { if (p.detail) p.detail.manage_stock = v; } },
    },
    categories: {
        product: { get: p => p.categories, set: (p, v) => p.categories = [...v] },
    },
};

export const LINK_COLUMNS_INSERT = {
    ...BASE_COLUMNS,
    stock: {
        product: { get: p => p.detail?.total_stock, set: (p, v) => { if (p.detail) p.detail.total_stock = v; } },
        variant: { get: v => v.total_stock, set: (v, val) => v.total_stock = val },
    },
};

export const LINK_COLUMNS_EDIT = {
    ...BASE_COLUMNS,
    stock: {
        product: { get: p => p.variants?.[0]?.available, set: (p, v) => { if (p.variants?.[0]) p.variants[0].available = v; } },
        variant: { get: v => v.available, set: (v, val) => v.available = val },
    },
};

/**
 * Sync a field value across all linked (selected) rows.
 *
 * @param {string} columnKey   - Key in the columns map (e.g. 'title', 'price')
 * @param {*} value            - The new value to set
 * @param {string} sourceKey   - The row key that originated the change (skipped)
 * @param {Set<string>} selectedKeys - Currently selected row keys
 * @param {Function} getRowByKey - (key) => { type: 'product'|'variant', data, product } | null
 * @param {Function|null} markDirtyFn - Optional (product) => void for dirty tracking
 * @param {Object} columns     - Column definition map (LINK_COLUMNS_INSERT or LINK_COLUMNS_EDIT)
 */
export function syncLinkedField(columnKey, value, sourceKey, selectedKeys, getRowByKey, markDirtyFn, columns) {
    const col = columns[columnKey];
    if (!col) return;

    for (const key of selectedKeys) {
        if (key === sourceKey) continue;
        const row = getRowByKey(key);
        if (!row) continue;

        const accessor = col[row.type];
        if (!accessor) continue;

        accessor.set(row.data, value);
        if (markDirtyFn) markDirtyFn(row.product);
    }
}
