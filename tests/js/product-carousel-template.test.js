import {describe, expect, it} from 'vitest';
import {CAROUSEL_TEMPLATE} from '@/BlockEditor/ProductCarousel/carouselTemplate';

describe('product carousel template', () => {
    it('starts its loop with a Product Card that holds the card layouts, then the controls and pagination', () => {
        expect(CAROUSEL_TEMPLATE.map(([name]) => name)).toEqual([
            'fluent-cart/product-carousel-loop', 'fluent-cart/product-carousel-controls', 'fluent-cart/product-carousel-pagination',
        ]);
        expect(CAROUSEL_TEMPLATE[0][2]).toEqual([['fluent-cart/product-card', {template_version: 1, card_width: '100%'}]]);
        expect(CAROUSEL_TEMPLATE[0][1].className).toBe('fluent-cart-product-carousel-loop');
    });
});
