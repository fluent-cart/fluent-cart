import {describe, expect, it} from 'vitest';
import {syncAvailability} from '../../resources/public/product-card/product-attribute-swatches.js';

// Stand-ins for a card's swatch block: rows of swatch buttons, as the renderer prints them.
const swatch = (termId, label, pressed = false, soldOut = false) => {
    const attributes = new Map([['aria-pressed', pressed ? 'true' : 'false']]);
    if (soldOut) attributes.set('data-sold-out', '');
    return {
        dataset: {termId: String(termId), label},
        attributes,
        hasAttribute: (name) => attributes.has(name),
        getAttribute: (name) => attributes.get(name) ?? null,
        setAttribute: (name, value) => attributes.set(name, value),
        toggleAttribute: (name, on) => (on ? attributes.set(name, '') : attributes.delete(name)),
        matches: (selector) => selector.includes('aria-pressed="true"') && attributes.get('aria-pressed') === 'true',
    };
};
const row = (groupId, swatches) => ({
    dataset: {groupId: String(groupId)},
    querySelectorAll: () => swatches,
    querySelector: () => swatches.find((item) => item.attributes.get('aria-pressed') === 'true') || null,
});
const block = (rows, variants, display = 'cross') => ({
    dataset: {soldOutDisplay: display, soldOutLabel: '%s, sold out', unavailableLabel: '%s, not available', variants: JSON.stringify(variants)},
    querySelectorAll: () => rows,
});

// Shade 1 (Navy 11, Red 12) x Weight 2 (250g 21, 500g 22); only Navy-250g is sold out.
const variants = [
    {id: 2, terms: {1: 11, 2: 22}, in_stock: true},
    {id: 3, terms: {1: 12, 2: 21}, in_stock: true},
    {id: 4, terms: {1: 12, 2: 22}, in_stock: true},
    {id: 1, terms: {1: 11, 2: 21}, in_stock: false},
];

describe('Sold-out values in combination with the other rows', () => {
    it('marks 250g while Navy is picked, and Navy while 250g is picked', () => {
        const navy = swatch(11, 'Navy', true);
        const red = swatch(12, 'Red');
        const light = swatch(21, '250g');
        const heavy = swatch(22, '500g', true);
        const rows = block([row(1, [navy, red]), row(2, [light, heavy])], variants);

        syncAvailability(rows);
        expect(light.hasAttribute('data-unavailable')).toBe(true);
        expect(light.getAttribute('aria-label')).toBe('250g, sold out');
        expect([navy, red, heavy].some((item) => item.hasAttribute('data-unavailable'))).toBe(false);

        // Red picked: 250g comes back; Navy is fine with 500g.
        navy.attributes.set('aria-pressed', 'false');
        red.attributes.set('aria-pressed', 'true');
        syncAvailability(rows);
        expect(light.hasAttribute('data-unavailable')).toBe(false);
        expect(light.getAttribute('aria-label')).toBe('250g');

        // 250g picked: Navy-250g is the sold-out pair, so Navy is marked.
        heavy.attributes.set('aria-pressed', 'false');
        light.attributes.set('aria-pressed', 'true');
        syncAvailability(rows);
        expect(navy.hasAttribute('data-unavailable')).toBe(true);
        expect(red.hasAttribute('data-unavailable')).toBe(false);
    });

    it('says "not available" for a combination the product does not have', () => {
        // Grey 13 comes only with 500g, so with 250g picked it is missing, not sold out.
        const grey = swatch(13, 'Grey');
        const light = swatch(21, '250g', true);
        const rows = block([row(1, [swatch(12, 'Red', true), grey]), row(2, [light])], [...variants, {id: 6, terms: {1: 13, 2: 22}, in_stock: true}]);
        syncAvailability(rows);
        expect(grey.hasAttribute('data-unavailable')).toBe(true);
        expect(grey.getAttribute('aria-label')).toBe('Grey, not available');
    });

    it('leaves values sold out everywhere to the renderer, and does nothing with No change or Hide', () => {
        const xl = swatch(31, 'XL', false, true);
        const rows = block([row(3, [swatch(30, 'M', true), xl])], [{id: 5, terms: {3: 30}, in_stock: true}]);
        syncAvailability(rows);
        expect(xl.hasAttribute('data-unavailable')).toBe(false);

        const plain = swatch(21, '250g');
        syncAvailability({...block([row(2, [plain])], variants), dataset: {variants: JSON.stringify(variants)}});
        expect(plain.hasAttribute('data-unavailable')).toBe(false);
        expect(() => syncAvailability(null)).not.toThrow();

        // Hide removes values sold out everywhere; it does not strike through combinations.
        const light = swatch(21, '250g');
        syncAvailability(block([row(1, [swatch(11, 'Navy', true)]), row(2, [light])], variants, 'hide'));
        expect(light.hasAttribute('data-unavailable')).toBe(false);
    });
});
