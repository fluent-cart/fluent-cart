import {describe, expect, it} from 'vitest';
import {resolveVariationSelection} from '@/BlockEditor/Components/ProductPicker/variationSelection';

/**
 * The shared variation picker keeps a multi-select to one product by default
 * (pricing table and similar blocks). The checkout product selector opts in
 * to picking variations across products.
 */

const shirtSmall = {id: 11, post_id: 1, is_child: true};
const shirtLarge = {id: 12, post_id: 1, is_child: true};
const mugBlue = {id: 21, post_id: 2, is_child: true};
const ebook = {id: 31, post_id: 3, is_child: false};

const pick = (steps, options) => steps.reduce(
    (selection, [variant, checked]) => resolveVariationSelection(selection, variant, checked, options),
    {}
);

describe('across products (allowMultipleProducts)', () => {
    const options = {isMultiple: true, allowMultipleProducts: true};

    it('keeps variations from different products together', () => {
        const selection = pick([[shirtSmall, true], [mugBlue, true], [ebook, true]], options);

        expect(Object.keys(selection)).toEqual(['11', '21', '31']);
    });

    it('unticking one row leaves the rest', () => {
        const selection = pick([[shirtSmall, true], [mugBlue, true], [ebook, true], [mugBlue, false]], options);

        expect(Object.keys(selection)).toEqual(['11', '31']);
    });
});

describe('default multi-select stays on one product', () => {
    const options = {isMultiple: true};

    it('keeps several variations of the same product', () => {
        expect(Object.keys(pick([[shirtSmall, true], [shirtLarge, true]], options))).toEqual(['11', '12']);
    });

    it('a variation of another product replaces the selection', () => {
        expect(Object.keys(pick([[shirtSmall, true], [mugBlue, true]], options))).toEqual(['21']);
    });

    it('a simple product replaces the selection', () => {
        expect(Object.keys(pick([[shirtSmall, true], [ebook, true]], options))).toEqual(['31']);
    });
});

describe('single select', () => {
    it('holds one item even when cross-product picking is allowed', () => {
        const selection = pick([[shirtSmall, true], [mugBlue, true]], {isMultiple: false, allowMultipleProducts: true});

        expect(Object.keys(selection)).toEqual(['21']);
    });
});
