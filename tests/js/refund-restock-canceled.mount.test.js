import {describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick, ref} from 'vue';

vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/VariationSelector.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Badge.vue', () => ({default: {render: () => null}}));
vi.mock('@/utils/translator/Translator', () => ({default: text => text, _x: text => text}));
vi.mock('@/utils/support/CurrencyFormatter', () => ({default: {}}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {get: () => ({})}}));

import Refund from '@/Modules/Orders/Modals/Refund.vue';
import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';

const restockRows = async (status) => {
    const root = new FakeNode('div');
    const modal = ref(null);
    const order = {
        status, currency: 'USD', total_paid: 15000, total_refund: 0, shipping_total: 0, shipping_refundable: 0,
        shipping_refunded_amount: 0, order_items: [],
        transactions: [{id: 1, status: 'succeeded', currency: 'USD', refundable: 15000}],
    };
    const app = renderer.createApp(defineComponent({
        setup: () => () => h(Refund, {ref: modal, order_id: 7, order, showRefundModal: true}),
    }));
    app.config.globalProperties.$t = text => text;
    app.config.globalProperties.formatNumberForOrder = amount => String(amount);
    app.config.warnHandler = () => {};
    app.mount(root);
    modal.value.refundableItems = [{id: 1, isSelected: true, refund_qty: 3, remainingBase: 15000, variants: {manage_stock: '1'}}];
    await nextTick();
    return [byClass(root, 'fct-refund-restock-row').length, modal.value.anyManageStockSelected];
};

describe('refund restock option', () => {
    it('is offered on a processing order', async () => {
        expect(await restockRows('processing')).toEqual([1, true]);
    });

    it('is hidden and not sent on a canceled order, which cancelling already restocked', async () => {
        expect(await restockRows('canceled')).toEqual([0, false]);
    });
});
