import {afterEach, describe, expect, it} from 'vitest';
import {convertToTemplateCard, convertedCardTemplate} from '@/BlockEditor/ProductCard/productCardDefaults';

describe('converting an older Product Card', () => {
    afterEach(() => {
        delete globalThis.window;
    });

    it('turns it into a template card on the Classic layout and leaves its product settings alone', () => {
        // Only the switch is set; setAttributes keeps product_id, query_type, price_format and card_width.
        expect(convertToTemplateCard()).toEqual({template_version: 1, card_layout: 'classic'});
    });

    it('builds the converted card from the layout it switches to', () => {
        const classic = [['fluent-cart/product-image'], ['fluent-cart/product-title'], ['fluent-cart/shopapp-product-buttons']];
        globalThis.window = {fluent_cart_product_card_data: {card_presets: {classic: {template: classic}}}};

        expect(convertedCardTemplate()).toEqual(classic);
        expect(Object.keys(window.fluent_cart_product_card_data.card_presets)).toContain(convertToTemplateCard().card_layout);
    });
});
