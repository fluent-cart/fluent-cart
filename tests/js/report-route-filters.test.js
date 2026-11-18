import {describe, expect, it, vi} from 'vitest';

/**
 * A report route may list the filters it reads in `meta.report_filters` (Pro's Licenses report lists only
 * products); the Filters menu then hides the others, which would change nothing on that page.
 */

const route = vi.hoisted(() => ({name: 'reports_licenses', meta: {}}));
vi.mock('vue-router', () => ({useRoute: () => route}));
vi.mock('@/utils/Storage', () => ({default: {get: (key, fallback = '') => fallback, set: () => {}, remove: () => {}}}));
vi.mock('@/utils/http/Rest', () => ({default: {get: vi.fn()}}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {get: () => '', onShopUpdate: () => {}}}));
vi.mock('@/utils/translator/Translator', () => ({default: (text) => text}));
vi.mock('@/Models/Reports/ReportFilterModel', () => ({default: {data: {currencies: {}}}}));

import useFilterState from '@/Bits/Components/FilterDropdown/FilterState';

const options = [
    {value: 'orderTypes', disableRoutes: []},
    {value: 'subscriptionType', disableRoutes: []},
    {value: 'variation_ids', disableRoutes: []},
];
const shown = () => options.filter(option => useFilterState().shouldRenderOption(option)).map(option => option.value);

describe('report_filters route meta', () => {
    it('shows only the filters the route lists', () => {
        route.meta = {report_filters: ['variation_ids']};

        expect(shown()).toEqual(['variation_ids']);
    });

    it('keeps every filter on routes that list none', () => {
        route.meta = {};

        expect(shown()).toEqual(['orderTypes', 'subscriptionType', 'variation_ids']);
    });

    it('still applies a filter option\'s own disabled routes', () => {
        route.meta = {report_filters: ['variation_ids']};

        expect(useFilterState().shouldRenderOption({value: 'variation_ids', disableRoutes: ['reports_licenses']})).toBe(false);
    });
});

describe('an open filter after a route change', () => {
    it('is dropped on a report that does not read it', () => {
        route.meta = {};
        const filter = useFilterState();
        filter.data.activeFilter = {value: 'orderTypes', disableRoutes: []};
        expect(filter.openFilter).toEqual({value: 'orderTypes', disableRoutes: []});

        route.meta = {report_filters: ['variation_ids']};
        expect(filter.openFilter).toBeFalsy();

        filter.data.activeFilter = {value: 'variation_ids', disableRoutes: []};
        expect(filter.openFilter).toEqual({value: 'variation_ids', disableRoutes: []});
    });
});
