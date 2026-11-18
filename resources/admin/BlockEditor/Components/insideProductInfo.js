// The attributes to change so a title or image block records whether a product container holds it,
// or null when they already say so. Outside a container the block picks its own product.
export const insideProductInfoChange = (attributes, inside) => {
    const wanted = {inside_product_info: inside ? 'yes' : 'no', ...(!inside ? {query_type: 'custom'} : {})};
    const change = Object.fromEntries(Object.entries(wanted).filter(([key, value]) => attributes[key] !== value));
    return Object.keys(change).length ? change : null;
};

// Applies that change without adding an Undo step: the block records where it sits, the merchant changed nothing.
export const syncInsideProductInfo = (attributes, setAttributes, inside) => {
    const change = insideProductInfoChange(attributes, inside);
    if (!change) return;
    wp.data.dispatch('core/block-editor').__unstableMarkNextChangeAsNotPersistent?.();
    setAttributes(change);
};
