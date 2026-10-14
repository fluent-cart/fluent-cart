import {beforeEach, describe, expect, it, vi} from 'vitest';

/**
 * "All currencies (in base)" exists only on the reports that convert every
 * order (or subscription) to the base currency. The subscriptions overview,
 * cohorts, overview and customer reports do not: there an "All currencies"
 * selection must become the base currency before the page requests data, so
 * the picker never claims a consolidated view the numbers do not have.
 */

const stored = vi.hoisted(() => ({
    'report-filter': {currency: '__all__'},
    'report-filter_currencies': {USD: '$', GBP: '£', __all__: '$'},
}));
vi.mock('@/utils/Storage', () => ({
    default: {
        get: (key, fallback = '') => (key in stored ? stored[key] : fallback),
        set: () => {},
        remove: () => {},
    },
}));
vi.mock('@/utils/http/Rest', () => ({default: {get: vi.fn()}}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {get: () => 'USD'}}));
const filterState = vi.hoisted(() => ({data: {selectedFilters: {}, currency: ''}}));
vi.mock('@/Bits/Components/FilterDropdown/FilterState', () => ({default: () => filterState}));
vi.mock('@/utils/translator/Translator', () => ({default: (text) => text}));

import Rest from '@/utils/http/Rest';
import reportFilter from '@/Models/Reports/ReportFilterModel';

const currencies = {USD: '$', GBP: '£', __all__: '$'};

describe('the All currencies selection on reports without a converted mode', () => {
    beforeEach(() => {
        reportFilter.data.currencies = {...currencies};
        reportFilter.data.baseCurrency = 'USD';
        reportFilter.data.currency = '__all__';
        filterState.data.selectedFilters = {currency: {label: 'Currency', value: '__all__', type: 'single'}};
        filterState.data.currency = '__all__';
        Rest.get.mockResolvedValue({
            currencies: {...currencies},
            default_currency: 'GBP',
            base_currency: 'USD',
            min_date: '2026-09-01 00:00:00',
            storeMode: 'test',
        });
    });

    it('is kept on a report that converts', () => {
        reportFilter.setReportRoute('reports_sales');

        expect(reportFilter.supportsConvertedCurrency()).toBe(true);
        expect(reportFilter.applicableFilters.params.currency).toBe('__all__');
    });

    it('becomes the base currency when navigating to a subscription report', () => {
        reportFilter.setReportRoute('reports_sales');
        reportFilter.setReportRoute('reports_subscriptions');

        expect(reportFilter.supportsConvertedCurrency()).toBe(false);
        expect(reportFilter.applicableFilters.params.currency).toBe('USD');
        expect(reportFilter.data.currencySign).toBe('$');
        expect(filterState.data.selectedFilters.currency.value).toBe('USD');
        expect(filterState.data.currency).toBe('USD');
    });

    it('becomes the base currency when a stored selection opens a subscription report', async () => {
        reportFilter.setReportRoute('subscriptions-cohorts');
        reportFilter.data.currency = '__all__';

        await reportFilter.fetchReportMeta();

        expect(reportFilter.applicableFilters.params.currency).toBe('USD');
    });

    it('falls back to the busiest currency when the base has no orders in range', async () => {
        Rest.get.mockResolvedValue({
            currencies: {GBP: '£', EUR: '€', __all__: '$'},
            default_currency: 'GBP',
            base_currency: 'USD',
            min_date: '2026-09-01 00:00:00',
            storeMode: 'test',
        });
        reportFilter.setReportRoute('reports_subscriptions');
        reportFilter.data.currency = '__all__';

        await reportFilter.fetchReportMeta();

        expect(reportFilter.applicableFilters.params.currency).toBe('GBP');
    });
});

describe('the subscription reports that convert every subscription', () => {
    beforeEach(() => {
        reportFilter.data.currencies = {...currencies};
        reportFilter.data.baseCurrency = 'USD';
        filterState.data.selectedFilters = {currency: {label: 'Currency', value: '__all__', type: 'single'}};
        filterState.data.currency = '__all__';
    });

    it.each(['subscriptions-retention', 'future_renewals'])('keep All currencies on %s', (route) => {
        reportFilter.setReportRoute('reports_sales');
        reportFilter.data.currency = '__all__';
        reportFilter.setReportRoute(route);

        expect(reportFilter.supportsConvertedCurrency()).toBe(true);
        expect(reportFilter.applicableFilters.params.currency).toBe('__all__');
    });

    it.each(['reports_subscriptions', 'subscriptions-cohorts'])('still read the base currency on %s', (route) => {
        reportFilter.data.currency = '__all__';
        reportFilter.setReportRoute(route);

        expect(reportFilter.supportsConvertedCurrency()).toBe(false);
        expect(reportFilter.applicableFilters.params.currency).toBe('USD');
    });
});
