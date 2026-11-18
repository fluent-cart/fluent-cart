import {beforeEach, describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick} from 'vue';

/**
 * A failed report request shows an error with Retry in the section it fed. It never shows zeros, "no data",
 * the previous filter's figures, or a Pro update prompt.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    globalThis.window.addEventListener = globalThis.window.addEventListener || (() => {});
    globalThis.window.removeEventListener = globalThis.window.removeEventListener || (() => {});
});

const rest = vi.hoisted(() => ({get: vi.fn()}));
vi.mock('@/utils/http/Rest', () => ({default: rest}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {get: (key, fallback) => fallback, onShopUpdate: () => {}}}));
vi.mock('@/utils/Notify', () => ({default: {error: vi.fn()}}));
vi.mock('@/utils/translator/Translator', () => ({default: text => text}));
vi.mock('echarts', () => ({init: () => ({setOption() {}, dispose() {}, resize() {}})}));
vi.mock('@/utils/Theme', () => ({default: {isDark: () => false, colors: {report: {}}}}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Permission/UserCan.vue', () => ({default: {render() { return this.$slots.default(); }}}));
vi.mock('@/Bits/Components/Layout/PageHeading.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Fluctuation.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/Reports/Components/SummaryLoader.vue', () => ({default: {render: () => h('span', {class: 'loading'})}}));
vi.mock('@/Bits/Components/ReportSummary.vue', () => ({
    default: {props: ['amount', 'title'], render() { return h('span', {class: 'amount'}, this.amount); }},
}));
vi.mock('@/Bits/Components/Extension/ExtensionComponent.vue', () => ({
    default: {props: ['name'], render() { return h('div', {class: 'extension'}, this.name); }},
}));
vi.mock('@/Modules/Reports/Components/ProReportPreview.vue', () => ({
    default: {props: ['placement'], render() { return h('div', {class: 'pro-preview'}, this.placement); }},
}));
vi.mock('@/Modules/Reports/Components/ProReportSample.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/Reports/Customer/CustomerTrendChart.vue', () => ({
    default: {props: ['points', 'loading'], render() { return h('div', {class: 'customer-chart'}); }},
}));
vi.mock('@/Modules/Reports/Subscription/SubscriptionHealth.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/Reports/Subscription/SubscriptionPeriodCards.vue', () => ({
    default: {props: ['overview'], render() { return h('div', {class: 'period-cards'}, String(this.overview?.period?.new_subscriptions || 0)); }},
}));
vi.mock('@/Modules/Reports/Subscription/SubscriptionMovementChart.vue', () => ({default: {render: () => h('div', {class: 'movement'})}}));
vi.mock('@/Modules/Reports/Refund/RefundCards.vue', () => ({
    default: {props: ['overview'], render() { return h('div', {class: 'refund-cards'}, String(this.overview?.summary?.refunds || 0)); }},
}));
vi.mock('@/Modules/Reports/Refund/RefundLineChart.vue', () => ({default: {render: () => h('div', {class: 'refund-chart'})}}));
vi.mock('@/Modules/Reports/Refund/RefundBreakdown.vue', () => ({default: {render: () => h('div', {class: 'refund-breakdown'})}}));
vi.mock('@/Modules/Reports/Refund/RefundReasons.vue', () => ({default: {render: () => h('div', {class: 'refund-reasons'})}}));

import Overview from '@/Modules/Reports/Overview/Overview.vue';
import CustomerReport from '@/Modules/Reports/Customer/CustomerReport.vue';
import SubscriptionReport from '@/Modules/Reports/Subscription/SubscriptionReport.vue';
import RefundReport from '@/Modules/Reports/Refund/RefundReport.vue';
import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';

const slot = name => ({render() { return h('div', {class: name}, this.$slots.default ? this.$slots.default() : []); }});
const stubs = {
    // The click listener falls through to the button node
    ElButton: {render() { return h('button', {class: 'el-button'}, this.$slots.default()); }},
    ElSkeleton: {render: () => h('span', {class: 'loading'})},
    ElTooltip: slot('tooltip'),
    ElEmpty: {props: ['description'], render() { return h('div', {class: 'empty'}, this.description); }},
    ElTable: {props: ['data'], render() { return h('div', {class: 'table'}, (this.data || []).map(row => row.email).join(',')); }},
    ElTableColumn: {render: () => null},
    RouterLink: slot('router-link'),
};
// The reports render core's fct-* wrappers: same stand-ins under their names
Object.assign(stubs, {FctButton: stubs.ElButton, FctSkeleton: stubs.ElSkeleton, FctTooltip: stubs.ElTooltip, FctTable: stubs.ElTable, FctTableColumn: stubs.ElTableColumn});

const deferred = () => {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    return {promise, resolve, reject};
};

// The Overview runs three requests at a time, so its queue needs a few macrotasks to drain
const settle = async () => {
    for (let i = 0; i < 10; i++) {
        await new Promise(resolve => setTimeout(resolve, 0));
        await nextTick();
    }
};

const mount = (component) => {
    const listeners = {};
    const reportFilter = {
        applicableFilters: {params: {currency: 'USD'}},
        addListener: (key, callback) => { listeners[key] = callback; },
        removeListener: () => {},
        trackCompareRange: request => request,
        formatAmount: value => '$' + value,
    };
    const root = new FakeNode('div');
    const app = renderer.createApp(defineComponent({render: () => h(component, {reportFilter})}));
    Object.entries(stubs).forEach(([name, stub]) => app.component(name, stub));
    app.mount(root);
    const change = (params) => Object.values(listeners).forEach(listener => listener({params}));

    return {root, change, text: () => root.textContent};
};

const clickRetry = (root, index = 0) => byClass(root, 'el-button').filter(node => node.textContent === 'Retry')[index].listeners.click();

beforeEach(() => {
    rest.get.mockReset();
});

describe('Overview when report requests fail', () => {
    it('shows an error, not zeros or "no sales", and says Needs Attention could not run', async () => {
        rest.get.mockRejectedValue(new Error('timeout'));
        const {root, text} = mount(Overview);
        await settle();

        expect(byClass(root, 'amount')).toHaveLength(0);
        expect(text()).not.toContain('No sales in this period');
        expect(text()).not.toContain('No product sales in this period');
        expect(text()).toContain('This report could not be loaded.');
        expect(text()).toContain('Some checks could not run, so this list may be incomplete.');
    });

    it('keeps the sections that loaded and marks only the failed figure', async () => {
        rest.get.mockImplementation((path) => path === 'reports/refunds-overview'
            ? Promise.reject(new Error('timeout'))
            : Promise.resolve({
                'reports/revenue': {summary: {net_sales: 236, order_count: 47}},
                'reports/customer-overview': {summary: {repeat_rate: 40}},
            }[path] || {}));
        const {root, text} = mount(Overview);
        await settle();

        const amounts = byClass(root, 'amount').map(node => node.textContent);
        expect(amounts).toContain('$236');
        // The refund rate card shows no figure; it does not read 0%
        expect(amounts).not.toContain('0%');
        expect(text()).toContain('Unavailable');
    });

    it('Retry re-runs only the failed request', async () => {
        rest.get.mockImplementation((path) => path === 'reports/refunds-overview' ? Promise.reject(new Error('timeout')) : Promise.resolve({}));
        const {root} = mount(Overview);
        await settle();
        const before = rest.get.mock.calls.length;

        rest.get.mockResolvedValue({summary: {refund_rate_cohort: 21.28}});
        clickRetry(root);
        await settle();

        expect(rest.get.mock.calls.slice(before).map(call => call[0])).toEqual(['reports/refunds-overview']);
        expect(byClass(root, 'amount').map(node => node.textContent)).toContain('21.28%');
    });
});

describe('Customers report when a refresh fails', () => {
    // Each endpoint answers from its own queue: the page and Top Customers request at the same time
    const route = (answers) => rest.get.mockImplementation(path => answers[path].shift()());

    it('hides the previous currency figures and rows, and shows an error with Retry', async () => {
        const overview = (customers, ltv) => () => Promise.resolve({summary: {customers, average_ltv: ltv}, previous: null, chart: []});
        const fail = () => Promise.reject(new Error('timeout'));
        route({
            'reports/customer-overview': [overview(15, 16488), fail, overview(1, 88668)],
            'reports/customer-insights': [() => Promise.resolve({top_customers: [{customer_id: 1, email: 'renewal@example.com'}]}), fail],
        });
        const {root, change, text} = mount(CustomerReport);
        await settle();
        expect(byClass(root, 'amount').map(node => node.textContent)).toContain('$16488');
        expect(text()).toContain('renewal@example.com');

        change({currency: 'EUR'});
        await settle();

        expect(byClass(root, 'amount')).toHaveLength(0);
        expect(byClass(root, 'customer-chart')).toHaveLength(0);
        expect(text()).not.toContain('renewal@example.com');
        expect(byClass(root, 'el-button').filter(node => node.textContent === 'Retry')).toHaveLength(2);

        clickRetry(root);
        await settle();
        const last = rest.get.mock.calls.filter(call => call[0] === 'reports/customer-overview').at(-1);
        expect(last[1].params.currency).toBe('EUR');
        expect(byClass(root, 'amount').map(node => node.textContent)).toContain('$88668');
    });
});

describe('Subscriptions report when the overview fails', () => {
    it('shows an error instead of zero cards and an empty MRR breakdown', async () => {
        rest.get.mockRejectedValueOnce(new Error('timeout'));
        const {root, text} = mount(SubscriptionReport);
        await settle();

        expect(byClass(root, 'period-cards')).toHaveLength(0);
        expect(byClass(root, 'movement')).toHaveLength(0);
        expect(text()).not.toContain('reports.subscriptions.mrr_breakdown');
        expect(text()).toContain('This report could not be loaded.');

        rest.get.mockResolvedValueOnce({period: {new_subscriptions: 16}, chart: []});
        clickRetry(root);
        await settle();
        expect(byClass(root, 'period-cards')[0].textContent).toBe('16');
    });
});

describe('Refunds report when the overview fails', () => {
    it('shows an error, not zero refunds, and never asks an up-to-date Pro to update', async () => {
        rest.get.mockRejectedValueOnce(new Error('timeout'));
        const {root, text} = mount(RefundReport);
        await settle();

        expect(byClass(root, 'refund-cards')).toHaveLength(0);
        expect(byClass(root, 'refund-reasons')).toHaveLength(0);
        expect(text()).not.toContain('feature_lock_report_refund_products');
        expect(text()).toContain('This report could not be loaded.');

        rest.get.mockResolvedValueOnce({summary: {refunds: 22}, by_product: []});
        clickRetry(root);
        await settle();
        expect(byClass(root, 'refund-cards')[0].textContent).toBe('22');
        expect(text()).toContain('reports.refunds.products');
    });
});
