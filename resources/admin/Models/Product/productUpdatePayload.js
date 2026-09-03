/**
 * Save-payload staging helpers shared by ProductEditModel.
 *
 * The editor is change-tracked: edits are staged in data.product_changes
 * (often alongside optimistic updates to the loaded product object), and
 * Rest.js ships product_changes through JSON.stringify. The functions here
 * sit between that change record and the wire — normalizing values so the
 * intended change survives serialization, and rejoining sparse change records
 * with their stored variations so each request carries the complete row the
 * backend validates.
 */

/**
 * Builds the `variants` payload the product-level Update button posts to
 * POST products/{postId}/pricing for a non-simple (advanced / simple_variations)
 * product.
 *
 * ProductEditModel.onChangePricing() and updatePricingOtherValue() record only
 * `{id, <the field the merchant touched>}` into data.product_changes.variants,
 * indexed by row position and therefore sparse. ProductUpdateRequest, on the
 * other hand, validates each row as a whole variant — post_id and
 * variation_title are required and fulfillment_type must be physical|digital —
 * so a sparse row has to be reunited with the variation it came from before it
 * can be sent.
 *
 * The merge stays deliberately narrow. ProductVariation batchUpdate emits
 * `ELSE <column>` for every row that omits a column, i.e. an absent key keeps the
 * stored value, which is what makes a partial write safe. Rewriting stock columns
 * from the editor's snapshot on an unrelated price edit would undo any purchase
 * that landed while the merchant had the page open, so only fields the merchant
 * actually changed are sent alongside the identity fields.
 */

/**
 * Identity plus the fields ProductUpdateRequest requires on every variant row.
 * Always sent — taken from the change record when present, otherwise from the
 * stored variation.
 */
export const VARIANT_IDENTITY_FIELDS = [
    'id',
    'post_id',
    'variation_title',
    'fulfillment_type',
];

/**
 * The rest of the columns the pricing endpoint accepts on a variant row. Sent
 * only when the change record carries them.
 */
export const VARIANT_MUTABLE_FIELDS = [
    'sku',
    'item_price',
    'compare_price',
    'item_cost',
    'manage_cost',
    'shipping_class',
    'other_info',
    'manage_stock',
    'serial_index',
    'total_stock',
    'available',
    'committed',
    'on_hold',
    'stock_status',
    'sold_individually',
    'media',
    'downloadable',
    'payment_type',
];

/**
 * Stock the pricing form must never write.
 *
 * These are a ledger the inventory endpoints own
 * (PUT products/{id}/update-inventory/{variantId}), and the pricing form does
 * not even render them for a simple product. They still reach the change record
 * because updatePricingOtherValue() stages the live variation object wholesale
 * for `simple` — so an `other_info` edit would post whatever counts the editor
 * happened to load, reverting any purchase that landed while the page was open.
 * Omitting them keeps the stored values, which is the same narrow-merge
 * guarantee the rest of this module provides.
 */
export const VARIANT_STOCK_LEDGER_FIELDS = [
    'total_stock',
    'available',
    'committed',
    'on_hold',
    'manage_stock',
];

function indexById(variants) {
    const index = new Map();

    (Array.isArray(variants) ? variants : []).forEach((variant) => {
        if (variant && variant.id !== undefined && variant.id !== null) {
            index.set(String(variant.id), variant);
        }
    });

    return index;
}

/**
 * @param {Array}  changedVariants Sparse change records from product_changes.variants.
 * @param {Array}  loadedVariants  The variations currently loaded in the editor.
 * @param {number} productId       Fallback for post_id on a variation that lacks one.
 * @returns {Array} Dense list of variant rows ready to post.
 */
export function buildVariantUpdatePayload(changedVariants, loadedVariants, productId) {
    if (!Array.isArray(changedVariants)) {
        return [];
    }

    const storedById = indexById(loadedVariants);

    // filter() skips the holes a sparse, position-indexed change array leaves behind.
    return changedVariants.filter(Boolean).filter((changed) => {
        // Drop a record naming a variation the editor no longer holds. Regenerating
        // the option config replaces product.variants wholesale
        // (AdvancedVariationConfig) without clearing product_changes, and every
        // reloader() path does the same, so an edit staged before that survives
        // pointing at a combination that no longer exists. Its id matches nothing,
        // the row comes out as {id, post_id} with no title or fulfilment type, and
        // the next save is rejected — naming a row the merchant can no longer see.
        //
        // A record with no id is a different thing: the simple form stages one for
        // a product that has no variation yet, and that row is built from scratch.
        if (changed.id === undefined || changed.id === null) {
            return true;
        }

        return storedById.has(String(changed.id));
    }).map((changed) => {
        const stored = storedById.get(String(changed.id)) || {};
        const row = {};

        VARIANT_IDENTITY_FIELDS.forEach((field) => {
            const value = changed[field] !== undefined ? changed[field] : stored[field];
            if (value !== undefined) {
                row[field] = value;
            }
        });

        if (row.post_id === undefined || row.post_id === null) {
            row.post_id = productId;
        }

        VARIANT_MUTABLE_FIELDS.forEach((field) => {
            if (changed[field] === undefined) {
                return;
            }

            if (field === 'other_info') {
                // The column is rewritten wholesale server-side and the description
                // branch records it sparsely, so replace would drop payment_type,
                // tax_class and the rest.
                row.other_info = {
                    ...(stored.other_info || {}),
                    ...(changed.other_info || {}),
                };
                return;
            }

            row[field] = changed[field];
        });

        return row;
    });
}

/**
 * Build the single variant row a SIMPLE product must post.
 *
 * A simple product sends its row on every save, because the product-level fields
 * (title, status, fulfilment) travel in the same request and the endpoint
 * validates the variant alongside them. Two things make it different from the
 * advanced path:
 *
 * - There may be no change record at all — the merchant edited only the title —
 *   yet a row still has to go out. Identity is taken from the stored variation.
 * - `other_info` is `required|array` for simple products alone
 *   (ProductRequest / ProductUpdateRequest apply those rules inside their
 *   `variation_type === 'simple'` branch), and ProductUpdateRequest's
 *   beforeValidation no longer fills it, so the row must always carry it. It is
 *   seeded from the stored variation and merged, never rebuilt from defaults, so
 *   a title edit cannot overwrite a subscription's interval or setup fee.
 *
 * @param {Array}  changedVariants Sparse change records from product_changes.variants.
 * @param {Array}  loadedVariants  The variations currently loaded in the editor.
 * @param {number} productId       Fallback for post_id.
 * @returns {Array} A one-row payload.
 */
export function buildSimpleVariantUpdatePayload(changedVariants, loadedVariants, productId) {
    const loaded = Array.isArray(loadedVariants) ? loadedVariants : [];
    const records = Array.isArray(changedVariants) ? changedVariants.filter(Boolean) : [];
    const record = {...(records[0] || {})};

    // Seed from the variation the change record actually names, not from row 0.
    // `variation_type = simple` does not guarantee a single variation — products
    // exist with the simple type and several rows — and grafting row 0's
    // other_info onto a different row would move one variation's billing settings
    // onto another.
    const stored = (record.id !== undefined
        ? loaded.find(variant => variant && String(variant.id) === String(record.id))
        : null) || loaded[0] || {};

    if (record.id === undefined) {
        record.id = stored.id;
    }

    if (record.other_info === undefined) {
        record.other_info = stored.other_info;
    }

    VARIANT_STOCK_LEDGER_FIELDS.forEach((field) => {
        delete record[field];
    });

    return buildVariantUpdatePayload([record], loaded, productId);
}

/**
 * Normalize a default-variant selection before it is staged into
 * product_changes.detail.default_variation_id.
 *
 * Clearing the Default Variant el-select (ProductStatus.vue) emits
 * `undefined`. Rest.js sends product_changes through JSON.stringify, which
 * drops any object key whose value is `undefined` — the request would arrive
 * with no default_variation_id key in `detail` at all. ProductDetailResource
 * treats an absent key as "not supplied" and preserves the existing default,
 * so a clear silently failed to persist.
 *
 * The backend already distinguishes "key absent" (preserve) from "key present
 * and empty" (clear to NULL) — see ProductDetailResource::update(). Mapping
 * every nullish/empty clear signal to `null` here keeps the key present after
 * serialization without touching that backend contract.
 *
 * @param {string|number|null|undefined} value
 * @returns {string|number|null}
 */
export function normalizeDefaultVariationIdForSave(value) {
    if (value === undefined || value === null || value === '') {
        return null;
    }

    return value;
}
