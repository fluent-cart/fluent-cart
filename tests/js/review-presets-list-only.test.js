import {afterEach, beforeEach, describe, expect, it} from 'vitest';
import {presetForBlocks} from '@/BlockEditor/ProductReviews/Components/reviewPresets';

/**
 * In All products and Selected products (two or more) mode, the Product Reviews
 * block builds each preset without Write a Review or the form. The picker must
 * still recognise it, so switching back restores the same layout.
 */
const list = (viewMode) => ['fluent-cart/product-review-list', {viewMode}, [
    ['fluent-cart/review-item', {}, [['fluent-cart/review-item-content', {}]]],
]];
const summary = (withButton = true) => ['fluent-cart/product-review-summary-group', {}, [
    ['fluent-cart/product-review-summary', {}],
    ...(withButton ? [['fluent-cart/write-a-review-button', {}]] : []),
]];

// Summary beside the list, and a call-to-action column that holds only the button.
const PRESETS = {
    classic: {label: 'Classic', category: 'list', template: [
        ['core/columns', {}, [
            ['core/column', {width: '320px'}, [summary()]],
            ['core/column', {}, [list('list')]],
        ]],
    ]},
    strip: {label: 'Photo Strip', category: 'photo', template: [
        ['core/columns', {}, [
            ['core/column', {width: '320px'}, [
                ['fluent-cart/product-review-summary-group', {}, [['fluent-cart/write-a-review-button', {}]]],
            ]],
            ['core/column', {}, [list('slider')]],
        ]],
    ]},
};

const toBlock = ([name, attributes, innerBlocks = []]) => ({
    name,
    attributes: {...attributes},
    innerBlocks: innerBlocks.map(toBlock),
});

describe('review presets for several products', () => {
    beforeEach(() => {
        globalThis.window = {fluent_cart_product_reviews_data: {presets: PRESETS}};
    });

    afterEach(() => {
        delete globalThis.window;
    });

    it('recognises the full layouts', () => {
        expect(presetForBlocks(PRESETS.classic.template.map(toBlock))).toBe('classic');
        expect(presetForBlocks(PRESETS.strip.template.map(toBlock))).toBe('strip');
    });

    it('recognises a preset built without Write a Review', () => {
        const classicWithoutButton = [['core/columns', {}, [
            ['core/column', {width: '320px'}, [summary(false)]],
            ['core/column', {}, [list('list')]],
        ]]];

        expect(presetForBlocks(classicWithoutButton.map(toBlock), true)).toBe('classic');
        // The strip's column held only the button, so only its list is left.
        expect(presetForBlocks([toBlock(list('slider'))], true)).toBe('strip');
        // Several products still recognise a layout that kept the button.
        expect(presetForBlocks(PRESETS.classic.template.map(toBlock), true)).toBe('classic');
    });

    it('keeps an existing single-product layout without the button as custom', () => {
        const classicWithoutButton = [['core/columns', {}, [
            ['core/column', {width: '320px'}, [summary(false)]],
            ['core/column', {}, [list('list')]],
        ]]];

        expect(presetForBlocks(classicWithoutButton.map(toBlock))).toBe('custom');
        expect(presetForBlocks([toBlock(list('slider'))], false)).toBe('custom');
    });

    it('treats an edited section as custom', () => {
        const paragraph = toBlock(['core/paragraph', {content: 'Note'}]);

        expect(presetForBlocks([paragraph, toBlock(list('slider'))])).toBe('custom');
        expect(presetForBlocks([toBlock(list('masonry'))])).toBe('custom');
    });
});
