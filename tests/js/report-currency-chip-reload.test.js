import {describe, expect, it, vi} from 'vitest';

/**
 * After a reload the reports read the stored currency, and the chip must show
 * it. ReportFilterModel writes the chip into the filter state it holds, so the
 * view's picker only sees it when both use the same filter state.
 */

vi.mock('@/utils/Storage', () => ({
    default: {
        get: (key, fallback = '') => fallback,
        set: () => {},
        remove: () => {},
    },
}));
vi.mock('@/utils/http/Rest', () => ({default: {get: vi.fn(() => new Promise(() => {}))}}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {get: () => 'USD'}}));
vi.mock('@/utils/translator/Translator', () => ({default: (text) => text}));
vi.mock('vue-router', () => ({useRoute: () => ({name: 'reports_sales'})}));

import reportFilter from '@/Models/Reports/ReportFilterModel';
import useFilterState from '@/Bits/Components/FilterDropdown/FilterState';

describe('the currency chip after a reload', () => {
    it('shows the applied currency in the filter state the view renders', () => {
        const viewState = useFilterState();
        viewState.data.selectedFilters = {};

        reportFilter.data.currency = 'JPY';
        reportFilter.syncCurrencyChip();

        expect(viewState).toBe(reportFilter.filterState);
        expect(viewState.data.selectedFilters.currency.value).toBe('JPY');
        expect(viewState.data.selectedFilters.currency.label).toBeTruthy();
        expect(viewState.data.currency).toBe('JPY');
    });

    it('is cleared by a reset so the next meta load picks the default currency', () => {
        reportFilter.data.currency = 'JPY';

        reportFilter.resetFiltersToDefault();
        expect(reportFilter.data.currency).toBe('');

        reportFilter.data.currency = 'JPY';
        reportFilter.resetFilterToDefault('currency');
        expect(reportFilter.data.currency).toBe('');
    });
});
