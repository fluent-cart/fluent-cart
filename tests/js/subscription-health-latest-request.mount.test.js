import {describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick} from 'vue';
import ElementPlus, {ID_INJECTION_KEY} from 'element-plus';

/**
 * The subscription health cards after a quick filter change: only the latest request may fill them, so a slow answer
 * for the old currency never lands under the new one. A failed request clears them instead of keeping old figures.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    globalThis.window.addEventListener = globalThis.window.addEventListener || (() => {});
    globalThis.window.removeEventListener = globalThis.window.removeEventListener || (() => {});
});

const rest = vi.hoisted(() => ({get: vi.fn()}));
vi.mock('@/utils/http/Rest', () => ({default: rest}));
vi.mock('@/utils/translator/Translator', () => ({default: text => text}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/Reports/Components/SummaryLoader.vue', () => ({default: {render: () => h('span', {class: 'loading'})}}));
vi.mock('@/Modules/Reports/Components/ReportLoadError.vue', () => ({
    default: {emits: ['retry'], render() { return h('button', {class: 'load-error', onClick: () => this.$emit('retry')}); }},
}));
vi.mock('@/Bits/Components/ReportSummary.vue', () => ({
    default: {props: ['amount', 'title'], render() { return h('span', {class: 'amount'}, this.amount); }},
}));

import SubscriptionHealth from '@/Modules/Reports/Subscription/SubscriptionHealth.vue';
import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';

const deferred = () => {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    return {promise, resolve, reject};
};

const group = {count: 0, mrr: 0};
const health = (mrr) => ({health: {mrr, arpu: mrr, subscriptions: 1, canceling: group, discounted: {...group, uplift: 0},
    failing: group, paused: group, trial: {count: 0, renewal_mrr: 0}}});

const mount = async () => {
    let listener = null;
    const reportFilter = {
        applicableFilters: {params: {currency: 'USD'}},
        addListener: (key, callback) => { listener = callback; },
        removeListener: () => {},
        formatAmount: value => String(value),
    };
    const root = new FakeNode('div');
    const Host = defineComponent({render: () => h(SubscriptionHealth, {reportFilter})});
    renderer.createApp(Host).use(ElementPlus)
        // The tooltip's teardown needs a real DOM; the cards are what the test reads
        .component('ElTooltip', {setup: (props, {slots}) => () => (slots.default ? slots.default() : null)})
        .provide(ID_INJECTION_KEY, {prefix: 1, current: 0}).mount(root);

    return {root, change: params => listener({params})};
};

const settle = async () => {
    for (let i = 0; i < 5; i++) {
        await Promise.resolve();
        await nextTick();
    }
};

describe('subscription health cards', () => {
    it('keep the latest request when an older one answers last', async () => {
        const usd = deferred();
        const eur = deferred();
        rest.get.mockReset().mockReturnValueOnce(usd.promise).mockReturnValueOnce(eur.promise);

        const {root, change} = await mount();
        change({currency: 'EUR'});
        eur.resolve(health(500));
        await settle();
        usd.resolve(health(9900));
        await settle();

        expect(byClass(root, 'amount')[0].textContent).toBe('500');
    });

    it('show no old figures after a failed request', async () => {
        const usd = deferred();
        const eur = deferred();
        rest.get.mockReset().mockReturnValueOnce(usd.promise).mockReturnValueOnce(eur.promise);

        const {root, change} = await mount();
        usd.resolve(health(9900));
        await settle();
        change({currency: 'EUR'});
        eur.reject(new Error('timeout'));
        await settle();

        expect(byClass(root, 'amount')).toHaveLength(0);
    });

    it('say a failed request failed, and Retry loads the cards', async () => {
        rest.get.mockReset().mockReturnValueOnce(Promise.reject(new Error('timeout'))).mockReturnValueOnce(Promise.resolve(health(700)));

        const {root} = await mount();
        await settle();
        const error = byClass(root, 'load-error');
        expect(error).toHaveLength(1);

        error[0].listeners.click();
        await settle();
        expect([byClass(root, 'load-error').length, byClass(root, 'amount')[0].textContent]).toEqual([0, '700']);
    });
});
