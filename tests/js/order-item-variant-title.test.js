import {describe, expect, it} from 'vitest';
import {getOrderItemVariantTitle} from '@/utils/variantLabel';

/**
 * A simple product's variation title is its product title, so showing it under
 * the product name repeats the name (the admin create-order list did).
 */
describe('getOrderItemVariantTitle', () => {
    it('returns nothing for a simple product whose variation title is its product title', () => {
        const item = {post_title: 'Ceramic Mug', title: 'Ceramic Mug', variation_display_title: 'Ceramic Mug'};

        expect(getOrderItemVariantTitle(item)).toBe('');
    });

    it('keeps a real variation title', () => {
        const item = {post_title: 'Cotton T-Shirt', title: 'Large', variation_display_title: 'Large'};

        expect(getOrderItemVariantTitle(item)).toBe('Large');
    });

    it('keeps attribute lines on a simple product', () => {
        const item = {post_title: 'Ceramic Mug', title: 'Ceramic Mug', variation_display_title: 'Ceramic Mug | engraving: Hello'};

        expect(getOrderItemVariantTitle(item)).toBe('Ceramic Mug | engraving: Hello');
    });

    it('falls back to the raw title, and to nothing when both are empty', () => {
        expect(getOrderItemVariantTitle({post_title: 'Cotton T-Shirt', title: 'Large'})).toBe('Large');
        expect(getOrderItemVariantTitle({post_title: 'Cotton T-Shirt'})).toBe('');
        expect(getOrderItemVariantTitle(null)).toBe('');
    });

    it('ignores whitespace around the titles', () => {
        const item = {post_title: 'Ceramic Mug ', title: ' Ceramic Mug', variation_display_title: ''};

        expect(getOrderItemVariantTitle(item)).toBe('');
    });
});
