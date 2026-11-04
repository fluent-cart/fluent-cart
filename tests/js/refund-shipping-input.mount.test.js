import {describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick} from 'vue';

vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/VariationSelector.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Badge.vue', () => ({default: {render: () => null}}));
vi.mock('@/utils/translator/Translator', () => ({default: text => text, _x: text => text}));
vi.mock('@/utils/support/CurrencyFormatter', () => ({default: {}}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {get: key => (key === 'currency_decimals' ? {JPY: 0, USD: 2} : {})}}));

import Refund from '@/Modules/Orders/Modals/Refund.vue';
import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';

// Element Plus is not installed on the app, so <el-input-number> renders as a
// plain node and its props land on node.attrs.
const shippingInput = async (currency) => {
    const root = new FakeNode('div');
    const order = {
        currency, total_paid: 150000, total_refund: 0, shipping_total: 50000, shipping_refundable: 50000,
        shipping_refunded_amount: 0, order_items: [],
        transactions: [{id: 1, status: 'succeeded', currency, refundable: 150000}],
    };
    const app = renderer.createApp(defineComponent({
        setup: () => () => h(Refund, {order_id: 7, order, showRefundModal: true}),
    }));
    app.config.globalProperties.$t = text => text;
    app.config.globalProperties.formatNumberForOrder = amount => String(amount);
    app.config.warnHandler = () => {};
    app.mount(root);
    await nextTick();
    return byClass(root, 'fct-refund-shipping-amount')[0];
};

describe('refund shipping input precision', () => {
    it('steps a yen shipping refund in whole yen', async () => {
        const input = await shippingInput('JPY');
        expect([input.attrs.min, input.attrs.step, input.attrs.precision]).toEqual([1, 1, 0]);
    });

    it('keeps cents for a two-decimal currency', async () => {
        const input = await shippingInput('USD');
        expect([input.attrs.min, input.attrs.step, input.attrs.precision]).toEqual([0.01, 0.01, 2]);
    });
});
