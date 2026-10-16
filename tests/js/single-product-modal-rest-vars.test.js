import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';

/**
 * product-card.js builds a SingleProductModal on window load, and
 * loadProductCardAssets() never localizes fluentCartRestVars — another script
 * on the page has to. In the Elementor editor nothing did, so the constructor
 * threw "can't access property rest, window.fluentCartRestVars is undefined".
 */

beforeEach(() => {
    vi.resetModules();
    vi.stubGlobal('window', {
        addEventListener: vi.fn(),
        dispatchEvent: vi.fn(),
    });
    vi.stubGlobal('document', {
        addEventListener: vi.fn(),
        querySelector: () => null,
    });
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('SingleProductModal without fluentCartRestVars', () => {

    it('constructs when the page never localized fluentCartRestVars', async () => {
        const {default: SingleProductModal} = await import('../../resources/public/product-page/SingleProductModal.js');

        expect(() => new SingleProductModal()).not.toThrow();
    });

    it('still binds its open, close and Escape listeners', async () => {
        const {default: SingleProductModal} = await import('../../resources/public/product-page/SingleProductModal.js');

        new SingleProductModal();

        const events = document.addEventListener.mock.calls.map(([name]) => name);
        expect(events.filter((name) => name === 'click')).toHaveLength(2);
        expect(events).toContain('keydown');
    });
});
