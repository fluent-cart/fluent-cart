// Each slide starts as a Product Card, so the card layouts work here as in Product Loop.
export const CAROUSEL_TEMPLATE = [
    [
        'fluent-cart/product-carousel-loop',
        {
            className: 'fluent-cart-product-carousel-loop',
            layout: {type: 'constrained'},
            metadata: {name: 'Product Carousel Loop'},
        },
        [
            ['fluent-cart/product-card', {template_version: 1, card_width: '100%'}],
        ],
    ],
    ['fluent-cart/product-carousel-controls'],
    ['fluent-cart/product-carousel-pagination'],
];
