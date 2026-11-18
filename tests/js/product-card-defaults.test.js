import {describe, expect, it} from 'vitest';
import {NEW_CARD_ATTRIBUTES} from '@/BlockEditor/ProductCard/productCardDefaults';

describe('a Product Card inserted from the inserter', () => {
    it('is a template card with a fixed width, so it does not span a whole page on its own', () => {
        expect(NEW_CARD_ATTRIBUTES).toEqual({template_version: 1, card_width: 300});
    });
});
