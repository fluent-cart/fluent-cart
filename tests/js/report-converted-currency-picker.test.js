import {describe, expect, it, vi} from 'vitest';

/**
 * The currency picker offers "All currencies (in base)" only where the
 * current report converts; elsewhere it lists the real currencies only.
 */

const reportFilter = vi.hoisted(() => ({
    data: {currencies: {USD: '$', GBP: '£', __all__: '$'}, baseCurrency: 'USD', convertedExcludedOrders: 0},
    converted: true,
    routes: null,
    route: '',
    supportsConvertedCurrency() {
        return this.routes ? this.routes.includes(this.route) : this.converted;
    },
}));
vi.mock('@/Models/Reports/ReportFilterModel', () => ({default: reportFilter}));
vi.mock('@/utils/Storage', () => ({default: {get: (key, fallback = '') => fallback, set: () => {}, remove: () => {}}}));
vi.mock('@/utils/http/Rest', () => ({default: {get: vi.fn(() => new Promise(() => {}))}}));
vi.mock('@/utils/translator/Translator', () => ({default: (text) => text}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {get: () => 'USD', onShopUpdate: () => {}}}));
vi.mock('vue-router', () => ({useRoute: () => ({name: ''})}));

import useFilterState from '@/Bits/Components/FilterDropdown/FilterState';

const values = () => useFilterState().currencies.map((option) => option.value);

describe('the currency picker', () => {
    it('offers All currencies on a report that converts', () => {
        reportFilter.converted = true;

        expect(values()).toEqual(['USD', 'GBP', '__all__']);
    });

    it('hides All currencies on a report that does not', () => {
        reportFilter.converted = false;

        expect(values()).toEqual(['USD', 'GBP']);
    });

    it.each([
        ['subscriptions-retention', ['USD', 'GBP', '__all__']],
        ['future_renewals', ['USD', 'GBP', '__all__']],
        ['reports_subscriptions', ['USD', 'GBP']],
        ['subscriptions-cohorts', ['USD', 'GBP']],
    ])('on %s lists %j', async (route, expected) => {
        // The real route list from the model, not a copy
        const {CONVERTED_CURRENCY_ROUTES} = await vi.importActual('@/Models/Reports/ReportFilterModel');
        reportFilter.routes = CONVERTED_CURRENCY_ROUTES;
        reportFilter.route = route;

        try {
            expect(values()).toEqual(expected);
        } finally {
            reportFilter.routes = null;
        }
    });
});
