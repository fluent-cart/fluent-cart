// As on the storefront: a product with options shows View Options until the card's swatches pin a variant.
// Simple and sold-out products show their own button instead.
export const showsViewOptions = (product, buttonState) => !!product
    && product.detail?.variation_type !== 'simple'
    && product.detail?.stock_availability !== 'out-of-stock'
    && !buttonState;
