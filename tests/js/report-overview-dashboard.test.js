import {afterEach, describe, expect, it, vi} from 'vitest';
import {createRenderer, h, ssrContextKey} from 'vue';

vi.mock('@/Modules/Reports/Overview/OverviewCards.vue', () => ({default: {}}));
vi.mock('@/Modules/Reports/Overview/OverviewTrendChart.vue', () => ({default: {}}));
vi.mock('@/Modules/Reports/Overview/OverviewTopList.vue', () => ({default: {}}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {}}));
vi.mock('@/utils/translator/Translator', () => ({default: (text, ...args) => args.reduce((out, arg, i) => out.replace('%' + (i + 1) + '$s', arg), text)}));
vi.mock('@/utils/http/Rest', () => ({default: {get: vi.fn()}}));

import Rest from '@/utils/http/Rest';
import Overview from '@/Modules/Reports/Overview/Overview.vue';

const renderer = createRenderer({
    createElement: () => ({}), createText: () => ({}), createComment: () => ({}),
    insert: () => {}, remove: () => {}, setText: () => {}, setElementText: () => {},
    parentNode: () => null, nextSibling: () => null, patchProp: () => {},
});
const settle = async () => {
    for (let i = 0; i < 5; i++) {
        await new Promise(resolve => setTimeout(resolve, 0));
    }
};

let app;
let tracked;
const mount = (responses) => {
    Rest.get.mockReset();
    Rest.get.mockImplementation(url => Promise.resolve(responses[url.replace('reports/', '')] || {}));
    let setup;
    // The page's setup bindings, read through the render function
    const Page = {...Overview, render: (ctx, cache, props, bindings) => { setup = bindings; return null; }};
    const filter = {
        applicableFilters: {params: {currency: 'USD', orderTypes: ['renewal'], variation_ids: [7], subscriptionType: 'yearly'}},
        addListener() {}, removeListener() {},
        trackCompareRange: request => (tracked = request),
        formatAmount: (value, options = {}) => (options.cents ? value / 100 : value).toFixed(2),
    };
    app = renderer.createApp({render: () => h(Page, {reportFilter: filter})});
    app.provide(ssrContextKey, {});
    app.mount({});

    return () => setup;
};
const requested = () => Rest.get.mock.calls.map(call => call[0].replace('reports/', ''));

afterEach(() => {
    app?.unmount();
    app = null;
});

describe('Overview dashboard', () => {
    it('shows MRR while the churn card still loads', async () => {
        Rest.get.mockReset();
        const pending = {};
        Rest.get.mockImplementation(url => {
            const key = url.replace('reports/', '');
            if (key === 'subscription-health') {
                return Promise.resolve({health: {subscriptions: 5, mrr: 100, failing: {count: 0, mrr: 0}}});
            }
            return new Promise(resolve => (pending[key] = resolve));
        });
        let setup;
        const Page = {...Overview, render: (ctx, cache, props, bindings) => { setup = bindings; return null; }};
        const filter = {applicableFilters: {params: {currency: 'USD'}}, addListener() {}, removeListener() {}, trackCompareRange: request => request,
            formatAmount: value => String(value)};
        app = renderer.createApp({render: () => h(Page, {reportFilter: filter})});
        app.provide(ssrContextKey, {});
        app.mount({});
        // Let the first three finish so health runs
        ['revenue', 'customer-overview', 'refunds-overview'].forEach(key => pending[key] && pending[key]({}));
        await settle();

        const cards = setup.subscriptionCards;
        expect([setup.hasSubscriptions, cards.find(card => card.key === 'mrr').loading, cards.find(card => card.key === 'churn_rate').loading])
            .toEqual([true, undefined, true]);
    });

    it('runs at most three report requests at once, the cards first', async () => {
        Rest.get.mockReset();
        const pending = [];
        Rest.get.mockImplementation(() => new Promise(resolve => pending.push(resolve)));
        const Page = {...Overview, render: () => null};
        const filter = {applicableFilters: {params: {currency: 'USD'}}, addListener() {}, removeListener() {}, trackCompareRange: request => request,
            formatAmount: value => String(value)};
        app = renderer.createApp({render: () => h(Page, {reportFilter: filter})});
        app.provide(ssrContextKey, {});
        app.mount({});

        expect(requested()).toEqual(['revenue', 'customer-overview', 'refunds-overview']);
        pending.shift()({});
        await settle();
        expect(requested()).toHaveLength(4);
        expect(requested()[3]).toBe('subscription-health');
    });

    it('skips the subscription cards, and the slow churn request, for a store without subscriptions', async () => {
        const page = mount({'subscription-health': {health: {subscriptions: 0, mrr: 0, failing: {count: 0, mrr: 0}}}});
        await settle();
        await settle();

        expect(page().hasSubscriptions).toBe(false);
        expect(requested()).not.toContain('subscriptions-overview');
        expect(page().attention).toEqual([]);
    });

    it('lists only what needs attention, and rounds the refund rate', async () => {
        const page = mount({
            'subscription-health': {health: {subscriptions: 10, mrr: 500, failing: {count: 3, mrr: 45}}},
            'subscriptions-overview': {period: {churn_rate: 2.5}, previousPeriod: {churn_rate: 2}},
            'payment-methods': {totals: {dispute_rate: 1.2}},
            'installments': {summary: {overdue_count: 0, overdue: 0}},
            // The headline is the cohort rate, as on the Refunds page; refunds per order placed is a different figure
            'refunds-overview': {summary: {refund_rate_cohort: 6.1304919757958, refund_rate_orders: 99}, previousSummary: {refund_rate_cohort: 2, refund_rate_orders: 1}},
        });
        await settle();
        await settle();

        expect(requested()).toContain('subscriptions-overview');
        // The churn card asks for the period figures only; health may come from the short cache
        expect(Rest.get.mock.calls.find(call => call[0] === 'reports/subscriptions-overview')[1].params.summary_only).toBe(1);
        expect(Rest.get.mock.calls.find(call => call[0] === 'reports/subscription-health')[1].params.cached).toBe(1);
        expect(page().attention.map(item => item.key)).toEqual(['failing', 'disputes', 'refunds']);
        const refund = page().salesCards.find(card => card.key === 'refund_rate');
        expect([refund.value, refund.change]).toEqual(['6.13%', 4.1]);
        expect(page().subscriptionCards.find(card => card.key === 'churn_rate').change).toBe(0.5);
    });

    it('shows no refund change or alert when the compared period had no orders', async () => {
        const page = mount({'refunds-overview': {summary: {refund_rate_cohort: 5, refund_rate_orders: 5}, previousSummary: {refund_rate_cohort: null, refund_rate_orders: 1}}});
        await settle();

        expect(page().salesCards.find(card => card.key === 'refund_rate').change).toBe(null);
        expect(page().attention).toEqual([]);
    });

    it('drops filters the Overview does not show, and charts net sales against the year before', async () => {
        const page = mount({overview: {data: {net_sales_months: [{group: '2025-03', net_sales: '40'}, ...Array.from({length: 11}, (_, i) => ({group: '2025-' + String(i + 4).padStart(2, '0'), net_sales: '0'})),
            {group: '2026-03', net_sales: '55.5'}]}}});
        await settle();

        // Only the 12-month net sales, from the short cache: it ignores the date range
        expect(Rest.get.mock.calls.find(call => call[0] === 'reports/overview')[1].params).toEqual({currency: 'USD', net_sales_only: 1, cached: 1});
        // The filter bar reads the compare ranges from the tracked revenue response
        expect(await tracked).toEqual({});
        const productsCall = Rest.get.mock.calls.find(call => call[0] === 'reports/product-sales');
        expect([productsCall[1].params.orderTypes, productsCall[1].params.variation_ids, productsCall[1].params.subscriptionType]).toEqual([undefined, [], '']);
        const yearly = page().yearly;
        expect([yearly.groups.at(-1), yearly.current.at(-1), yearly.previous.at(-1)]).toEqual(['2026-03', 55.5, 40]);
    });
});
