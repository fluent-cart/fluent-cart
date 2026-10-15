import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import {createRenderer, h, ssrContextKey} from 'vue';

vi.mock('@/Bits/Components/Permission/UserCan.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Layout/PageHeading.vue', () => ({default: {}}));
vi.mock('@/Modules/Reports/Customer/Summary.vue', () => ({default: {}}));
vi.mock('@/Modules/Reports/Customer/CustomerReportChart.vue', () => ({default: {}}));
vi.mock('@/Modules/Reports/Customer/Comparison.vue', () => ({default: {}}));
vi.mock('@/utils/translator/Translator', () => ({default: text => text}));
vi.mock('@/utils/http/Rest', () => ({default: {get: vi.fn()}}));

import Rest from '@/utils/http/Rest';
import CustomerReport from '@/Modules/Reports/Customer/CustomerReport.vue';

const Report = {...CustomerReport, render: () => null};
const renderer = createRenderer({
    createElement: () => ({}), createText: () => ({}), createComment: () => ({}),
    insert: () => {}, remove: () => {}, setText: () => {}, setElementText: () => {},
    parentNode: () => null, nextSibling: () => null, patchProp: () => {},
});
const settle = () => new Promise(resolve => setTimeout(resolve, 0));

let app, filter;
beforeEach(() => {
    Rest.get.mockReset();
    Rest.get.mockResolvedValue({summary: {}, currentMetrics: [], previousMetrics: []});
    filter = {
        applicableFilters: {params: {currency: 'USD'}},
        data: {compareType: 'no_comparison'},
        listeners: {},
        addListener(key, callback) { this.listeners[key] = callback; },
        removeListener(key) { delete this.listeners[key]; },
    };
    app = renderer.createApp({render: () => h(Report, {reportFilter: filter})});
    app.provide(ssrContextKey, {});
    app.mount({});
});
afterEach(() => {
    app?.unmount();
});

describe('Customer report filter listener', () => {
    it('stops requesting the customer report after the page unmounts', async () => {
        await settle();
        expect(filter.listeners['customer-report']).toBeTypeOf('function');
        expect(Rest.get).toHaveBeenCalledTimes(1);

        app.unmount();
        app = null;

        expect(filter.listeners['customer-report']).toBeUndefined();
        Object.values(filter.listeners).forEach(listener => listener({params: {currency: 'GBP'}}));
        await settle();
        expect(Rest.get).toHaveBeenCalledTimes(1);
    });
});
