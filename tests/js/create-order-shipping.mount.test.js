import {afterEach, describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick} from 'vue';
import {FakeNode, renderer} from './support/fake-dom-renderer.js';

vi.hoisted(() => { globalThis.window = {}; });
vi.mock('@/utils/translator/Translator', () => ({default: text => text, translateNumber: value => value}));
vi.mock('@/utils/http/Rest', () => ({default: {get: vi.fn(), post: vi.fn()}}));
vi.mock('@/utils/Notify', () => ({default: {error: vi.fn(), info: vi.fn(), success: vi.fn(), validationErrors: vi.fn()}}));
vi.mock('@/utils/Confirmation', () => ({default: vi.fn()}));
vi.mock('@/utils/support/Asset', () => ({default: {productPlaceholder: () => ''}}));
vi.mock('@/Bits/productService', () => ({formatNumber: value => value}));
vi.mock('@/Bits/Components/Card/Card.js', () => ({__v_isRef: false, Container: {render() { return h('div', this.$slots.default?.()); }}, Header: {render: () => null}, Body: {render() { return h('div', this.$slots.default?.()); }}}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/Orders/Modals/AddProductItemModal.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/Products/AddProductModal.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/SaveBar.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Inputs/NumberInput.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/Parts/Labels/Label.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/Orders/_OrderBulkActions.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/Orders/_Transaction.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Badge.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Buttons/IconButton.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/CopyToClipboard.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/Parts/Notes/Note.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/Orders/Coupon.vue', () => ({default: {methods: {invalidate() {}, setCoupons() {}}, render: () => null}}));
vi.mock('@/Modules/Orders/CustomDiscount.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/Orders/OrderCustomerInformation.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/Orders/OrderCurrencySummary.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/Orders/Shipping.vue', () => ({default: {render: () => null}}));
vi.mock('dayjs', () => ({default: {tz: {guess: () => 'UTC'}}}));

import CreateOrder from '@/Modules/Orders/CreateOrder.vue';
import Rest from '@/utils/http/Rest';

const mounted = [];
const flush = async () => { for (let i = 0; i < 5; i++) { await Promise.resolve(); await nextTick(); } };
const line = type => ({object_id: 8, quantity: 1, fulfillment_type: type, payment_type: 'onetime', other_info: {}, unit_price: 10000});
const mountOrder = async (type = 'physical') => {
    Rest.get.mockResolvedValue({currencies: [], shipping_methods: [], other_shipping_methods: []});
    const root = new FakeNode('div');
    const app = renderer.createApp(CreateOrder);
    app.config.warnHandler = () => {};
    app.component('fct-table', {render: () => null});
    app.component('fct-select', defineComponent({props: ['modelValue', 'ariaLabel'], setup: (props, {emit}) => () => h('select', {'aria-label': props.ariaLabel, value: props.modelValue, onChange: event => emit('update:modelValue', event.target.value)})}));
    app.mixin({methods: {$t: text => text, formatNumberForOrder: cents => String(cents / 100), formatNumber: value => value, translateNumber: value => value}});
    app.component('fct-input', defineComponent({props: ['modelValue', 'ariaLabel', 'disabled'], setup: (props, {emit}) => () => h('input', {'aria-label': props.ariaLabel, value: props.modelValue, disabled: props.disabled, onInput: event => emit('update:modelValue', event.target.value)})}));
    app.mount(root);
    const vm = app._instance.proxy;
    mounted.push(app);
    vm.order.order_items = [line(type)];
    await flush();
    clearTimeout(vm.repriceTimer);
    vm.calculating = false;
    return {vm, root};
};

afterEach(() => { mounted.splice(0).forEach(app => app.unmount()); vi.clearAllMocks(); });

describe('manual order shipping charge', () => {
    it('shows a charge control for a physical order before it is saved', async () => {
        const {root} = await mountOrder();
        expect(root.findAll(node => node.attrs['aria-label'] === 'Shipping charge')).toHaveLength(1);
    });

    it('has no shipping charge control for digital-only orders', async () => {
        const {root} = await mountOrder('digital');
        expect(root.findAll(node => node.attrs['aria-label'] === 'Shipping charge')).toHaveLength(0);
    });

    it('prices the entered charge in cents before saving', async () => {
        const {vm, root} = await mountOrder();
        const input = root.findAll(node => node.attrs['aria-label'] === 'Shipping charge')[0];
        input.listeners.input({target: {value: '19.99'}});
        expect(vm.order.shipping_total).toBe(1999);
        expect(vm.calculating).toBe(true);
        Rest.post.mockResolvedValue({order_items: [line('physical')], shipping_total: 1999, subtotal: 10000, total_amount: 11999, applied_coupons: {}, quote_revision: 'shipping-1999'});
        clearTimeout(vm.repriceTimer);
        await vm.reprice();
        expect(Rest.post).toHaveBeenCalledWith('orders/price', expect.objectContaining({shipping_total: 1999}));
        expect(vm.order.total_amount).toBe(11999);
        Rest.post.mockClear();
        Rest.post.mockReturnValue(new Promise(() => {}));
        await vm.update();
        expect(Rest.post).toHaveBeenCalledWith('orders', expect.objectContaining({shipping_total: 1999, total_amount: 11999}));
    });

    it('blocks saving an invalid charge and accepts free shipping', async () => {
        const {vm} = await mountOrder();
        vm.updateShippingCharge('-5');
        await vm.update();
        expect(Rest.post).not.toHaveBeenCalled();
        vm.updateShippingCharge('0');
        expect(vm.shippingInvalid).toBe(false);
        expect(vm.order.shipping_total).toBe(0);
    });

    it('keeps manual shipping in the preview and save after editing a free product quantity', async () => {
        const {vm, root} = await mountOrder();
        const item = vm.order.order_items[0];
        item.unit_price = 0;
        const input = root.findAll(node => node.attrs['aria-label'] === 'Shipping charge')[0];
        Rest.post.mockImplementation((route, payload) => Promise.resolve({
            order_items: [{...item, subtotal: 0, discount_total: 0}],
            shipping_total: payload.shipping_total,
            subtotal: 0,
            total_amount: payload.shipping_total,
            applied_coupons: {},
            quote_revision: 'free-product-shipping',
        }));
        input.listeners.input({target: {value: '10'}});
        clearTimeout(vm.repriceTimer);
        await vm.reprice();
        Rest.post.mockClear();

        vm.updateQuantity(2, 0, item);
        await flush();
        clearTimeout(vm.repriceTimer);
        await vm.reprice();
        await flush();
        expect(Rest.post).toHaveBeenCalledWith('orders/price', expect.objectContaining({shipping_total: 1000}));
        expect(Number(input.value)).toBe(10);
        expect(vm.order.total_amount).toBe(1000);
        Rest.post.mockClear();
        Rest.post.mockReturnValue(new Promise(() => {}));
        await vm.update();
        expect(Rest.post).toHaveBeenCalledWith('orders', expect.objectContaining({shipping_total: 1000, total_amount: 1000}));
    });

    it('clears shipping when the last physical item is removed', async () => {
        const {vm} = await mountOrder();
        vm.updateShippingCharge('10');
        vm.order.order_items = [line('digital')];
        await flush();
        expect(vm.order.shipping_total).toBe(0);
    });

    it('ignores an old price response after the shipping charge changes', async () => {
        const {vm} = await mountOrder();
        let settle;
        Rest.post.mockReturnValueOnce(new Promise(resolve => { settle = resolve; }));
        const pricing = vm.reprice();
        vm.updateShippingCharge('20');
        settle({order_items: [], shipping_total: 0, total_amount: 10000});
        await pricing;
        expect(vm.order.shipping_total).toBe(2000);
        expect(vm.calculating).toBe(true);
        await vm.update();
        expect(Rest.post).toHaveBeenCalledTimes(1);
    });

    it('does not create an order when the shipping preview fails', async () => {
        const {vm} = await mountOrder();
        vm.updateShippingCharge('10');
        clearTimeout(vm.repriceTimer);
        Rest.post.mockRejectedValue({status_code: 422});
        await vm.reprice();
        await vm.update();
        expect(Rest.post.mock.calls.every(([route]) => route === 'orders/price')).toBe(true);
        expect(vm.priceFailed).toBe(true);
    });

    it('sends the selected method for pricing and saves its returned charge', async () => {
        const {vm, root} = await mountOrder();
        const select = root.findAll(node => node.attrs['aria-label'] === 'Shipping method')[0];
        select.listeners.change({target: {value: 5}});
        clearTimeout(vm.repriceTimer);
        Rest.post.mockResolvedValue({order_items: [{...line('physical'), shipping_charge: 1500}], shipping: {id: 5, title: 'Standard'}, shipping_total: 1500, total_amount: 11500, applied_coupons: {}, quote_revision: 'method-5'});
        await vm.reprice();
        expect(Rest.post).toHaveBeenCalledWith('orders/price', expect.objectContaining({shipping_method_id: 5}));
        expect(vm.shippingAmount).toBe(15);
        expect(vm.order.order_items[0].shipping_charge).toBe(1500);
        Rest.post.mockClear();
        Rest.post.mockReturnValue(new Promise(() => {}));
        await vm.update();
        expect(Rest.post).toHaveBeenCalledWith('orders', expect.objectContaining({shipping_method_id: 5, shipping_total: 1500}));
    });

    it('clears the selected method and line charges when switching to a custom charge', async () => {
        const {vm} = await mountOrder();
        vm.shippingSettings = {id: 5};
        vm.order.order_items[0].shipping_charge = 1000;
        vm.updateShippingMethod(0);
        vm.updateShippingCharge('7.50');
        expect(vm.shippingSettings.id || 0).toBe(0);
        expect(vm.order.shipping_total).toBe(750);
        expect(vm.order.order_items[0].shipping_charge).toBe(0);
    });

    it('ignores a method price response after switching back to a custom charge', async () => {
        const {vm} = await mountOrder();
        vm.updateShippingMethod(5);
        clearTimeout(vm.repriceTimer);
        let settle;
        Rest.post.mockReturnValueOnce(new Promise(resolve => { settle = resolve; }));
        const pricing = vm.reprice();
        vm.updateShippingMethod(0);
        vm.updateShippingCharge('7.50');
        settle({order_items: [], shipping: {id: 5}, shipping_total: 1500, total_amount: 11500});
        await pricing;
        expect(vm.shippingSettings.id || 0).toBe(0);
        expect(vm.shippingAmount).toBe('7.50');
        expect(vm.order.shipping_total).toBe(750);
        expect(vm.order.config.shipping_source).toBe('manual');
    });

    it('keeps only the latest address method list', async () => {
        const {vm} = await mountOrder();
        let settle;
        Rest.get.mockReturnValueOnce(new Promise(resolve => { settle = resolve; }));
        vm.selectedCustomerCountry = 'US';
        vm.selectedCustomerAddress = {state: 'NY'};
        vm.fetchShippingMethods();
        Rest.get.mockResolvedValueOnce({shipping_methods: [{id: 2}], other_shipping_methods: []});
        vm.selectedCustomerCountry = 'CA';
        vm.selectedCustomerAddress = {state: 'ON'};
        vm.fetchShippingMethods();
        await flush();
        settle({shipping_methods: [{id: 1}], other_shipping_methods: []});
        await flush();
        expect(Rest.get).toHaveBeenLastCalledWith('orders/shipping_methods', expect.objectContaining({country_code: 'CA', state: 'ON'}));
        expect(vm.shippingMethods).toEqual([{id: 2}]);
        expect(vm.loadingShippingMethods).toBe(false);
    });


    it('shows shipping when pricing resolves a physical variant under a digital product', async () => {
        const {vm, root} = await mountOrder('digital');
        Rest.post.mockResolvedValue({order_items: [line('physical')], shipping: {}, shipping_total: 0, total_amount: 10000, applied_coupons: {}});
        await vm.reprice();
        await flush();
        expect(root.findAll(node => node.attrs['aria-label'] === 'Shipping method')).toHaveLength(1);
    });

});
