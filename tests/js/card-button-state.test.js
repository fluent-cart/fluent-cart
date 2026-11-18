import {describe, expect, it} from 'vitest';
import {showsViewOptions} from '@/BlockEditor/ShopApp/InnerBlocks/cardButtonState';

const product = (detail) => ({ID: 1, detail: {variation_type: 'advanced_variations', stock_availability: 'in-stock', ...detail}});

describe('the card button the editor previews', () => {
    it('is View Options for a product with options until the swatches pin a variant', () => {
        expect(showsViewOptions(product(), null)).toBe(true);
        expect(showsViewOptions(product(), {label: 'Add To Cart', mode: 'add'})).toBe(false);
    });

    it('is never View Options for a simple or sold-out product, or before the product loads', () => {
        expect(showsViewOptions(product({variation_type: 'simple'}), null)).toBe(false);
        expect(showsViewOptions(product({stock_availability: 'out-of-stock'}), null)).toBe(false);
        expect(showsViewOptions(null, null)).toBe(false);
    });
});
