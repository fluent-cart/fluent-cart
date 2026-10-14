import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import {createRenderer, h, ssrContextKey} from 'vue';

vi.mock('echarts', () => ({}));
vi.mock('@/Bits/Components/Permission/UserCan.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Card/Card.js', () => ({Container: {}, Body: {}, Header: {}}));
vi.mock('@/Bits/Components/Screenshot.vue', () => ({default: {}}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {}}));
vi.mock('@/Bits/Components/Buttons/IconButton.vue', () => ({default: {}}));
vi.mock('@/Bits/Components/Table/Empty.vue', () => ({default: {}}));
vi.mock('@/Bits/Components/Layout/PageHeading.vue', () => ({default: {}}));
vi.mock('@/Modules/Reports/Components/SummaryLoader.vue', () => ({default: {}}));
vi.mock('@/Models/Reports/CheckBoxFilterModel', () => ({default: {}}));
vi.mock('@/Models/Reports/ChartTypeFilterModel', () => ({default: {}}));
vi.mock('@/utils/Theme', () => ({default: {colors: {report: {}}, isDark: () => false}}));
vi.mock('@/utils/translator/Translator', () => ({default: text => text}));
vi.mock('@/utils/http/Rest', () => ({default: {get: vi.fn()}}));
vi.mock('@/utils/Notify', () => ({default: {error: vi.fn()}}));
vi.mock('@/utils/support/CurrencyFormatter', () => ({default: {}}));
vi.mock('@/utils/Utils', () => ({
    chartAxisPointer: {}, chartTooltipAmount: vi.fn(), chartTooltipPosition: vi.fn(),
    resolveDateFormat: () => 'YYYY-MM-DD', fluentDayjsLocale: () => 'en',
}));

import Rest from '@/utils/http/Rest';
import Notify from '@/utils/Notify';
import FutureRenewalsReport from '@/Modules/Reports/Subscription/FutureRenewalsReport.vue';

// Only the component lifecycle and request handling are under test; chart children stay stubbed.
const Report = {...FutureRenewalsReport, render: () => null};
const renderer = createRenderer({
    createElement: () => ({}), createText: () => ({}), createComment: () => ({}),
    insert: () => {}, remove: () => {}, setText: () => {}, setElementText: () => {},
    parentNode: () => null, nextSibling: () => null, patchProp: () => {},
});
const settle = () => new Promise(resolve => setTimeout(resolve, 0));
const deferred = () => {
    let resolve, reject;
    const promise = new Promise((done, fail) => { resolve = done; reject = fail; });
    return {promise, resolve, reject};
};

let app, state, filter;
beforeEach(() => {
    vi.stubGlobal('window', {addEventListener: vi.fn(), removeEventListener: vi.fn()});
    filter = {
        applicableFilters: {params: {currency: 'USD'}},
        listeners: {},
        addListener(key, callback) { this.listeners[key] = callback; },
        removeListener(key) { delete this.listeners[key]; },
    };
    Rest.get.mockResolvedValue({totalRenewals: 0, totalProjected: 0});
    app = renderer.createApp({render: () => h(Report, {reportFilter: filter})});
    app.provide(ssrContextKey, {});
    app.mixin({beforeMount() {
        if (this.$.type === Report) state = this.$.setupState;
    }});
    app.mount({});
});
afterEach(() => {
    app?.unmount();
    vi.unstubAllGlobals();
});

describe('Future Renewals filter changes', () => {
    it.each(['JPY', '__all__'])('reloads the endpoint for %s and removes its listener on exit', async currency => {
        await settle();
        expect(filter.listeners['future-renewals']).toBeTypeOf('function');
        Rest.get.mockResolvedValue({totalRenewals: 2, totalProjected: 300000});

        await filter.listeners['future-renewals']({params: {currency}});
        await settle();

        expect(Rest.get).toHaveBeenLastCalledWith('reports/future-renewals', {params: {currency, groupKey: 'monthly'}});
        expect(state.data.totalProjected).toBe(300000);
        app.unmount();
        app = null;
        expect(filter.listeners['future-renewals']).toBeUndefined();
    });

    it('keeps the latest currency response when an earlier request finishes later', async () => {
        await settle();
        const older = deferred();
        const newer = deferred();
        Rest.get.mockReturnValueOnce(older.promise).mockReturnValueOnce(newer.promise);
        state.fetchData({params: {currency: 'JPY'}});
        state.fetchData({params: {currency: 'GBP'}});
        newer.resolve({totalRenewals: 2, totalProjected: 2000});
        await settle();
        older.resolve({totalRenewals: 1, totalProjected: 150000});
        await settle();

        expect(state.data.totalProjected).toBe(2000);
        expect(state.loading).toBe(false);
    });

    it('reports a failed refresh and releases the loading state', async () => {
        await settle();
        const error = {message: 'Could not load renewals'};
        Rest.get.mockRejectedValue(error);
        await state.fetchData({params: {currency: 'JPY'}});
        await settle();

        expect(Notify.error).toHaveBeenCalledWith(error);
        expect(state.loading).toBe(false);
    });
});
