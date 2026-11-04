import {describe, expect, it, vi} from 'vitest';

/**
 * Free stores pick any report range: every preset is offered, and the picked range is what the request sends
 * (it used to be replaced with this month).
 */

// A filter saved before this change: the free plan sent this month's fixed dates with no preset key
const stored = vi.hoisted(() => ({'report-filter': {startDate: '2001-03-01 00:00:00', endDate: '2001-03-31 23:59:59', rangeKey: ''}}));
vi.mock('@/utils/Storage', () => ({
    default: {get: (key, fallback = '') => (key in stored ? stored[key] : fallback), set: () => {}, remove: () => {}},
}));
vi.mock('@/utils/http/Rest', () => ({default: {get: vi.fn(() => Promise.resolve({}))}}));
vi.mock('@/utils/Config/AppConfig', () => ({
    default: {get: (key, fallback) => (key === 'app_config.isProActive' ? false : fallback), onShopUpdate: () => {}},
}));
const filterState = vi.hoisted(() => ({data: {selectedFilters: {}, currency: ''}}));
vi.mock('@/Bits/Components/FilterDropdown/FilterState', () => ({default: () => filterState}));
vi.mock('@/utils/translator/Translator', () => ({default: (text) => text}));

import {presetRange, reportPresets} from '@/Modules/Reports/Utils/datePresets';
import reportFilter from '@/Models/Reports/ReportFilterModel';

describe('report date range without Pro', () => {
    it('offers every preset', () => {
        expect(reportPresets().map(preset => preset.key)).toEqual(
            expect.arrayContaining(['today', 'last_7', 'last_30', 'this_month', 'last_month', 'last_12_months', 'all_time'])
        );
    });

    it('reads a filter saved with no preset as this month, from today', () => {
        expect(reportFilter.data.rangeKey).toBe('this_month');
        expect(reportFilter.applicableFilters.params.startDate.slice(0, 10)).toBe(presetRange('this_month')[0].format('YYYY-MM-DD'));
    });

    it('sends the picked custom range', () => {
        reportFilter.data.rangeKey = 'custom';
        reportFilter.data.dateRange = ['2001-03-01', '2001-03-31'];

        const {startDate, endDate} = reportFilter.applicableFilters.params;
        expect(startDate.slice(0, 10)).toBe('2001-03-01');
        expect(endDate.slice(0, 10)).toBe('2001-03-31');
    });
});
