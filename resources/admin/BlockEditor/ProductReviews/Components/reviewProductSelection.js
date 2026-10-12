// Mirrors ProductReviewService::MAX_PRODUCT_FILTER_IDS; the server drops the rest.
export const MAX_SELECTED_PRODUCTS = 100;

/**
 * "Selected products" is one editor option over two saved modes: one product
 * saves as custom (the single-product block), more as multiple. Existing custom
 * blocks need no migration and keep working on older plugin versions.
 */
export const SELECTED_PRODUCTS_SOURCE = 'selected';

export const productSourceOf = (queryType) => {
    if (queryType === 'custom' || queryType === 'multiple') {
        return SELECTED_PRODUCTS_SOURCE;
    }

    return queryType === 'all' ? 'all' : 'default';
};

const toProductIds = (values) => {
    const productIds = [];

    (Array.isArray(values) ? values : []).forEach((value) => {
        const productId = parseInt(value, 10);
        if (productId > 0 && !productIds.includes(productId)) {
            productIds.push(productId);
        }
    });

    return productIds.slice(0, MAX_SELECTED_PRODUCTS);
};

/**
 * The products a block in Selected products mode has picked, in saved order.
 */
export const selectedProductIdsOf = (attributes) => {
    if (attributes.query_type === 'custom') {
        return toProductIds([attributes.product_id]);
    }

    return toProductIds(attributes.product_ids);
};

/**
 * Attributes that save a selection: one product as custom, otherwise multiple.
 * An empty selection stays multiple, which renders nothing rather than every review.
 */
export const attributesForSelection = (values) => {
    const productIds = toProductIds(values);

    if (productIds.length === 1) {
        return {query_type: 'custom', product_id: String(productIds[0]), product_ids: productIds};
    }

    return {query_type: 'multiple', product_ids: productIds};
};

/**
 * Attributes for switching the product source, keeping a saved selection.
 */
export const attributesForSource = (source, attributes) => {
    if (source !== SELECTED_PRODUCTS_SOURCE) {
        return {query_type: source};
    }

    const savedProductIds = toProductIds(attributes.product_ids);

    return attributesForSelection(savedProductIds.length ? savedProductIds : [attributes.product_id]);
};

/**
 * What the picker opens with: every saved id, as its loaded product or a
 * placeholder. Built from the saved ids, so opening it before the titles load (or
 * after that fails) and confirming cannot drop products picked earlier.
 */
export const modalSelectionFor = (productIds, loadedProducts) => {
    const loadedById = {};
    (Array.isArray(loadedProducts) ? loadedProducts : []).forEach((product) => {
        loadedById[String(product.ID)] = product;
    });

    return toProductIds(productIds).map((productId) => (
        loadedById[String(productId)] || {ID: productId, isPlaceholder: true}
    ));
};
