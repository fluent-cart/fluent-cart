import {beforeEach, describe, expect, it, vi} from 'vitest';

/**
 * Report pages render only after fetchReportMeta() has settled
 * (ReportsView), so their first request already carries the effective
 * currency. That needs fetchReportMeta() to return its request: a stored
 * EUR choice with only USD orders in range must be USD once it resolves —
 * before any report asks for data, not after the charts loaded EUR.
 */

const stored = vi.hoisted(() => ({
    'report-filter': {currency: 'EUR', startDate: '2026-09-01 00:00:00', endDate: '2026-09-30 23:59:59'},
    'report-filter_currencies': {EUR: '€', USD: '$'},
}));

vi.mock('@/utils/Storage', () => ({
    default: {
        get: (key, fallback = '') => (key in stored ? stored[key] : fallback),
        set: () => {},
        remove: () => {},
    },
}));
vi.mock('@/utils/http/Rest', () => ({default: {get: vi.fn()}}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {get: () => 'USD', onShopUpdate: () => {}}}));
const filterState = vi.hoisted(() => ({data: {selectedFilters: {}, currency: ''}}));
vi.mock('@/Bits/Components/FilterDropdown/FilterState', () => ({default: () => filterState}));
vi.mock('@/utils/translator/Translator', () => ({default: (text) => text}));

import Rest from '@/utils/http/Rest';
import reportFilter from '@/Models/Reports/ReportFilterModel';

describe('report currency is resolved before reports request data', () => {
    beforeEach(() => {
        reportFilter.data.currency = 'EUR';
        Rest.get.mockResolvedValue({
            currencies: {USD: '$'},
            default_currency: 'USD',
            min_date: '2026-01-01 00:00:00',
            storeMode: 'test',
        });
    });

    it('returns the metadata request so the page can wait for it', async () => {
        const pending = reportFilter.fetchReportMeta();

        expect(pending).toBeInstanceOf(Promise);
        await pending;

        expect(reportFilter.data.currency).toBe('USD');
        expect(reportFilter.data.currencySign).toBe('$');
        expect(reportFilter.applicableFilters.params.currency).toBe('USD');
    });

    it('still settles when the metadata request fails', async () => {
        Rest.get.mockRejectedValue(new Error('offline'));

        await expect(reportFilter.fetchReportMeta()).resolves.toBeUndefined();
        expect(reportFilter.data.currency).toBe('EUR');
    });
});

const meta = (currencies, extra = {}) => ({
    currencies,
    default_currency: Object.keys(currencies)[0],
    min_date: '2026-09-20 00:00:00',
    storeMode: 'test',
    ...extra,
});

const deferred = () => {
    let resolve;
    const promise = new Promise((done) => (resolve = done));
    return {promise, resolve};
};

describe('a filter change refreshes the currencies for the new range', () => {
    beforeEach(() => {
        reportFilter.data.currency = 'USD';
        reportFilter.data.currencies = {USD: '$'};
        reportFilter.data.dateRange = ['2026-01-01 00:00:00', '2026-09-30 23:59:59'];
        filterState.data.selectedFilters = {};
        reportFilter.removeListener('test');
    });

    it('resolves the currency before the reports reload, keeping the chosen dates', async () => {
        Rest.get.mockResolvedValue(meta({EUR: '€'}));
        const seen = [];
        reportFilter.addListener('test', (filters) => seen.push(filters.params.currency));

        await reportFilter.onFilterChange();

        expect(seen).toEqual(['EUR']);
        expect(reportFilter.data.currencies).toEqual({EUR: '€'});
        expect(reportFilter.data.dateRange[0]).toBe('2026-01-01 00:00:00');
    });

    it('lets only the latest range apply and reload the reports', async () => {
        const older = deferred();
        const newer = deferred();
        Rest.get.mockReturnValueOnce(older.promise).mockReturnValueOnce(newer.promise);
        const seen = [];
        reportFilter.addListener('test', (filters) => seen.push(filters.params.currency));

        const first = reportFilter.onFilterChange();
        const second = reportFilter.onFilterChange();
        newer.resolve(meta({GBP: '£'}));
        await second;
        older.resolve(meta({EUR: '€'}));
        await first;

        expect(reportFilter.data.currency).toBe('GBP');
        expect(seen).toEqual(['GBP']);
    });

    it('moves the picker chip to the resolved currency', async () => {
        filterState.data.selectedFilters = {currency: {label: 'Currency', value: 'USD', type: 'single'}};
        filterState.data.currency = 'USD';
        Rest.get.mockResolvedValue(meta({EUR: '€'}));

        await reportFilter.onFilterChange();

        expect(filterState.data.selectedFilters.currency.value).toBe('EUR');
        expect(filterState.data.currency).toBe('EUR');
    });
});
