/**
 * Next picker selection after a row is ticked or unticked.
 *
 * Default multi-select keeps to one product (pricing table and similar blocks
 * read the variations of a single product); `allowMultipleProducts` lifts that.
 */
export const resolveVariationSelection = (prev, variant, checked, {isMultiple = true, allowMultipleProducts = false} = {}) => {
    const variantId = String(variant.id);
    const productId = String(variant.post_id || variant.product_id);

    // Single select mode: only one item at a time (used by SKU block etc.)
    if (!isMultiple) {
        return checked ? {[variantId]: variant} : {};
    }

    const next = {...prev};

    if (allowMultipleProducts) {
        if (checked) {
            next[variantId] = variant;
        } else {
            delete next[variantId];
        }
        return next;
    }

    const selectedProductIds = Object.values(prev).map(v =>
        String(v.post_id || v.product_id)
    );

    const activeProductId = selectedProductIds[0] || null;

    // PARENT CLICK (single select)
    if (!variant.is_child) {
        return checked ? {[variantId]: variant} : {};
    }

    // CHILD CLICK (multi select)
    if (activeProductId && activeProductId !== productId) {
        return checked ? {[variantId]: variant} : {};
    }

    if (checked) {
        next[variantId] = variant;
    } else {
        delete next[variantId];
    }

    return next;
};
