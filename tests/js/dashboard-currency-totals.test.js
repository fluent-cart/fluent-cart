import {describe, expect, it, vi} from 'vitest';
import {createSSRApp, h} from 'vue';
import {renderToString} from 'vue/server-renderer';

const rest = vi.hoisted(() => ({get: vi.fn()}));
vi.mock('@/utils/http/Rest', () => ({default: rest}));
vi.mock('@/utils/Notify', () => ({default: {error: vi.fn()}}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/DynamicTemplates/DynamicTemplates.vue', () => ({default: {render: () => null}}));
vi.mock('@/utils/translator/Translator', () => ({
    default: (text, value) => text.replace('%s', value),
    translateNumber: value => value,
}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {
    get: (key, fallback) => ({
        currency_signs: {USD: '$', GBP: '£'},
        currency_decimals: {USD: 2, GBP: 2},
        'shop.currency': 'USD',
        'shop.currency_sign': '$',
        'shop.currency_position': 'before',
        shop: {currency: 'USD', decimal_separator: 'dot'},
        wp_locale: 'en_US',
    }[key] ?? fallback),
    onShopUpdate: () => {},
}}));

import StatsWidget from '@/Pages/Dashboard/Components/StatsWidget.vue';

const renderStats = async (stats) => {
    rest.get.mockResolvedValue({stats});
    const app = createSSRApp({render: () => h(StatsWidget)});
    app.mixin({
        async serverPrefetch() {
            const state = this.$?.setupState;
            if (state?.fetchStat) {
                state.fetchStat();
                await new Promise(resolve => setTimeout(resolve, 0));
            }
        },
    });
    return renderToString(app);
};

describe('dashboard converted money', () => {
    it('labels the totals with the API currency instead of the ambient shop currency', async () => {
        const html = await renderStats([
            {title: 'Revenue', current_count: 33, has_currency: true, currency: 'GBP', excluded_orders: 0, url: '#revenue'},
            {title: 'Refund', current_count: 4, has_currency: true, currency: 'GBP', excluded_orders: 0, url: '#refunds'},
        ]);

        expect(rest.get).toHaveBeenCalledWith('dashboard/stats');
        expect(html).toContain('£33');
        expect(html).toContain('£4');
        expect(html).toContain('All currencies in GBP');
        expect(html).not.toContain('without an exchange rate');
    });

    it('shows when orders were excluded from money totals', async () => {
        const html = await renderStats([
            {title: 'Revenue', current_count: 0, has_currency: true, currency: 'USD', excluded_orders: 2, url: '#revenue'},
        ]);

        expect(html).toContain('$0');
        expect(html).toContain('2 orders without an exchange rate left out');
    });

    it('keeps order counts as counts', async () => {
        const html = await renderStats([{title: 'Orders', current_count: 4, url: '#orders'}]);

        expect(html).toContain('4');
        expect(html).not.toContain('$4');
        expect(html).not.toContain('All currencies in');
    });
});
