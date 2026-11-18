// Each related product starts as a Product Card, so the card layouts work here as in Product Loop.
export const PRODUCT_TEMPLATE = [
    ['fluent-cart/product-card', {template_version: 1, card_width: '100%'}],
];

// Older related cards are built from these blocks directly; they stay allowed so those cards keep editing.
export const ALLOWED_BLOCKS = [
    'fluent-cart/product-card',
    'fluent-cart/shopapp-product-image',
    'fluent-cart/shopapp-product-title',
    'fluent-cart/product-rating',
    'fluent-cart/shopapp-product-price',
    'fluent-cart/product-swatches',
    'fluent-cart/product-quick-view',
    'fluent-cart/shopapp-product-buttons',
];

// The blocks the Display Elements toggles hide. The renderer only hides them as the loop's own
// children, so the toggles mean nothing for a loop that holds a Product Card.
const DISPLAY_ELEMENT_BLOCKS = [
    'fluent-cart/shopapp-product-image',
    'fluent-cart/shopapp-product-title',
    'fluent-cart/shopapp-product-price',
    'fluent-cart/shopapp-product-buttons',
];

export const usesDisplayElements = (childNames) => childNames.some(name => DISPLAY_ELEMENT_BLOCKS.includes(name));

// A Related Products block may hold more than one loop; the toggles still apply to each of them.
export const anyLoopUsesDisplayElements = (blocks) => blocks.some(block =>
    block.name === 'fluent-cart/product-template' && usesDisplayElements((block.innerBlocks || []).map(child => child.name)));
