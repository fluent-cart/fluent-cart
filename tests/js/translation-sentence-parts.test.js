import {beforeEach, describe, expect, it, vi} from 'vitest';

vi.mock('@/utils/Config/AppConfig', () => ({
    default: {get: (key, defaultValue) => defaultValue},
}));

const {splitPlaceholders} = await import('@/utils/translator/Translator');
const {_x: blockX} = await import('@/BlockEditor/BlockEditorTranslator');

describe('splitPlaceholders()', () => {
    it('keeps the text around each marker and reports the marker number', () => {
        expect(splitPlaceholders('Apply %1$s to %2$s on every purchase')).toEqual([
            {text: 'Apply '},
            {slot: 1},
            {text: ' to '},
            {slot: 2},
            {text: ' on every purchase'},
        ]);
    });

    it('follows the order the translator chose', () => {
        expect(splitPlaceholders('%2$s: %1$s anwenden')).toEqual([
            {slot: 2},
            {text: ': '},
            {slot: 1},
            {text: ' anwenden'},
        ]);
    });

    it('returns the whole string when there is no marker', () => {
        expect(splitPlaceholders('No markers')).toEqual([{text: 'No markers'}]);
    });
});

describe('block editor _x()', () => {
    beforeEach(() => {
        globalThis.window = {};
    });

    it('uses the contextual entry', () => {
        window.fluent_cart_block_translation = {
            'Card': 'Karte',
            'layout element\u0004Card': 'Kachel',
        };

        expect(blockX('Card', 'layout element')).toBe('Kachel');
    });

    it('falls back to the plain entry while the contextual one is untranslated', () => {
        window.fluent_cart_block_translation = {
            'Card': 'Karte',
            'layout element\u0004Card': 'Card',
        };

        expect(blockX('Card', 'layout element')).toBe('Karte');
    });

    it('returns the string when no map is loaded', () => {
        expect(blockX('Card', 'layout element')).toBe('Card');
    });

    it('substitutes placeholders after the context argument', () => {
        window.fluent_cart_block_translation = {
            'Pagination\u0004%1$s of %2$s': '%1$s von %2$s',
        };

        expect(blockX('%1$s of %2$s', 'Pagination', 3, 9)).toBe('3 von 9');
        expect(blockX('%s items', 'Cart', 4)).toBe('4 items');
    });

    it('substitutes placeholders when no map is loaded', () => {
        expect(blockX('%s items', 'Cart', 4)).toBe('4 items');
    });
});
