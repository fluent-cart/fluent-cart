import {beforeEach, describe, expect, it, vi} from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {renderToString} from 'vue/server-renderer';
import {createSSRApp, h} from 'vue';

/**
 * VariationSelector renders a product variant's price. `item_price` is in CENTS
 * and formatNumber() takes cents, so the component must pass the value straight
 * through.
 *
 * It used to read `formatNumber(variant.item_price * 100, true)`. That was
 * correct only while ProductRoute.formatPricing pre-divided variants to dollars;
 * PR 2615 moved the product editor to cents end to end and removed the division,
 * which left this component rendering 100x too high in the Downloadable Files
 * variant picker and the Add Upgrade Path modal.
 */

// formatNumber is the assertion surface — the unit it is handed IS the contract.
// The real one reaches CurrencyFormatter and therefore AppConfig/window, neither
// of which exists in the Node test environment.
const formatNumber = vi.fn((amount) => `formatted:${amount}`);

vi.mock('@/Bits/productService', () => ({
    formatNumber: (...args) => formatNumber(...args),
}));

vi.mock('@/utils/variantLabel', () => ({
    getVariantLabel: (variant) => variant.variant_title || '',
}));

const VariationSelector = (await import('@/Bits/Components/VariationSelector.vue')).default;

const render = (variant) => renderToString(
    createSSRApp({render: () => h(VariationSelector, {variant})})
);

describe('VariationSelector price units', () => {
    beforeEach(() => {
        formatNumber.mockClear();
    });

    it('hands formatNumber the stored cents unchanged', async () => {
        await render({id: 1, variant_title: 'Large', item_price: 1999});

        expect(formatNumber).toHaveBeenCalledWith(1999, true);
    });

    it('does not rescale a price by 100', async () => {
        await render({id: 1, variant_title: 'Large', item_price: 1999});

        const [amount] = formatNumber.mock.calls[0];
        expect(amount).not.toBe(199900);
    });

    it('renders the formatted price into the markup', async () => {
        const html = await render({id: 1, variant_title: 'Large', item_price: 129900});

        expect(html).toContain('formatted:129900');
        expect(html).not.toContain('formatted:12990000');
    });

    it('passes a zero price through as zero', async () => {
        await render({id: 1, variant_title: 'Free', item_price: 0});

        expect(formatNumber).toHaveBeenCalledWith(0, true);
    });

    it('keeps the unit for a subscription variant that also shows its interval', async () => {
        const html = await render({
            id: 1,
            variant_title: 'Yearly',
            item_price: 4999,
            other_info: {repeat_interval: 'yearly'},
        });

        expect(formatNumber).toHaveBeenCalledWith(4999, true);
        expect(html).toContain('Yearly');
    });

    // Belt and braces: the render assertions above go green if someone deletes the
    // price span entirely. This pins the expression itself.
    it('has no cents-to-cents rescale left in the template', () => {
        const source = fs.readFileSync(
            path.resolve('resources/admin/Bits/Components/VariationSelector.vue'),
            'utf8'
        );
        const template = source.slice(source.indexOf('<template>'));

        expect(template).toContain('formatNumber(variant.item_price, true)');
        expect(template).not.toMatch(/formatNumber\(\s*variant\.item_price\s*\*/);
    });
});
