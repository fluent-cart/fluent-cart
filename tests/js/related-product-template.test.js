import {describe, expect, it} from 'vitest';
import {PRODUCT_TEMPLATE, ALLOWED_BLOCKS, usesDisplayElements, anyLoopUsesDisplayElements} from '@/BlockEditor/RelatedProduct/Components/productTemplate';

describe('related products card', () => {
    it('starts each related product with a Product Card that holds the card layouts', () => {
        expect(PRODUCT_TEMPLATE).toEqual([['fluent-cart/product-card', {template_version: 1, card_width: '100%'}]]);
    });
    it('accepts a Product Card and keeps the blocks older related cards are built from', () => {
        expect(ALLOWED_BLOCKS).toContain('fluent-cart/product-card');
        for (const name of ['fluent-cart/shopapp-product-image', 'fluent-cart/shopapp-product-title', 'fluent-cart/product-rating',
            'fluent-cart/shopapp-product-price', 'fluent-cart/product-swatches', 'fluent-cart/product-quick-view', 'fluent-cart/shopapp-product-buttons']) {
            expect(ALLOWED_BLOCKS).toContain(name);
        }
    });
    it('offers the Display Elements toggles only to a loop that holds the blocks they hide', () => {
        // The toggles hide these blocks by name, and only as the loop's own children.
        expect(usesDisplayElements(['fluent-cart/shopapp-product-image', 'fluent-cart/shopapp-product-title', 'fluent-cart/product-rating'])).toBe(true);
        expect(usesDisplayElements(['fluent-cart/shopapp-product-buttons'])).toBe(true);
        expect(usesDisplayElements(['fluent-cart/product-card'])).toBe(false);
        expect(usesDisplayElements([])).toBe(false);
    });
    it('offers the toggles when any loop in the block holds those blocks, not only the first', () => {
        const loop = (...names) => ({name: 'fluent-cart/product-template', innerBlocks: names.map(name => ({name}))});
        const heading = {name: 'core/heading', innerBlocks: []};
        expect(anyLoopUsesDisplayElements([heading, loop('fluent-cart/product-card'), loop('fluent-cart/shopapp-product-title')])).toBe(true);
        expect(anyLoopUsesDisplayElements([heading, loop('fluent-cart/product-card'), loop('fluent-cart/product-card')])).toBe(false);
        // A heading never counts, even if a block with a toggled name sat under it.
        expect(anyLoopUsesDisplayElements([{name: 'core/group', innerBlocks: [{name: 'fluent-cart/shopapp-product-title'}]}])).toBe(false);
    });
});
