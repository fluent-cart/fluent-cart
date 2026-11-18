import {afterAll, beforeAll, beforeEach, describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick} from 'vue';
import ElementPlus, {ID_INJECTION_KEY} from 'element-plus';
import {fctPlugin, popoverStub} from './support/fct-stubs.js';

/**
 * A Shippo label is billed in USD; on a BDT order the rate picker, the cost
 * field, the buy confirmation and the shipment card also show the approximate
 * BDT figure. Nothing changes when the label is in the order's currency.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    globalThis.MouseEvent = globalThis.MouseEvent || class MouseEvent {};
});

const rest = vi.hoisted(() => ({post: vi.fn()}));

vi.mock('@/utils/http/Rest', () => ({default: rest}));
vi.mock('@/utils/Notify', () => ({default: {success: vi.fn(), error: vi.fn()}}));
vi.mock('@/utils/translator/Translator', () => ({
    _x: text => text,
    default: (text, ...args) => args.reduce((out, arg, index) => out.replace('%' + (index + 1) + '$s', arg), text),
    pluralizeTranslate: (single, plural, count) => (count === 1 ? single : plural).replace('%s', count),
}));
vi.mock('@/utils/support/Asset', () => ({default: {productPlaceholder: () => ''}}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {get: (key, fallback) => fallback}}));
vi.mock('@/utils/support/CurrencyFormatter', () => ({
    default: {
        formatFixed: (cents, sign, hide, currency) => ({USD: '$', BDT: '৳'}[currency] || '') + (cents / 100).toFixed(2),
        formatForOrder: (cents, currency) => ({USD: '$', BDT: '৳'}[currency] || '') + (cents / 100).toLocaleString('en-US'),
    },
}));
vi.mock('@/Modules/Shipping/useCarriers', () => ({
    fetchDispatchCarriers: () => Promise.resolve([
        {slug: 'shippo', name: 'Shippo', is_enabled: true, supports_labels: true, supports_rates: true},
    ]),
}));
vi.mock('@/Modules/Orders/Components/shipmentCarrierDefault', () => ({
    defaultShipmentCarrier: () => 'shippo',
}));
vi.mock('@/Bits/common', () => ({
    formatDate: () => '',
    getErrorMessage: (errors, fallback) => fallback,
    getOrderItemImageUrl: () => '',
}));
vi.mock('@/Bits/Components/Permission/UserCan.vue', () => ({
    default: {props: ['permission'], setup: (props, {slots}) => () => (slots.default ? slots.default() : null)},
}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Buttons/IconButton.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Badge.vue', () => ({default: {render: () => null}}));
// The real transition animates element styles the fake DOM does not have.
vi.mock('@/Bits/Components/Animation.vue', async () => {
    const {h} = await import('vue');
    return {default: {props: ['visible'], setup: (props, {slots}) => () => (props.visible ? h('div', slots.default ? slots.default() : []) : null)}};
});
vi.mock('@/utils/Clipboard', () => ({default: {copy: vi.fn()}}));

import ShipmentForm from '@/Modules/Orders/Components/ShipmentForm.vue';
import ShipmentRecord from '@/Modules/Orders/Components/ShipmentRecord.vue';
import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';

const flush = async () => {
    for (let tick = 0; tick < 6; tick++) {
        await Promise.resolve();
        await nextTick();
    }
};

const stub = {render: () => null};

// The drawer teleports, which the fake DOM cannot; render its slots in place.
const DrawerStub = {
    setup: (props, {slots}) => () => h('div', [
        slots.header ? slots.header({close: () => {}, titleId: 'fct-test-drawer-title'}) : null,
        slots.default ? slots.default() : null,
        slots.footer ? slots.footer() : null,
    ]),
};

// Renders only the confirm title, so a test can read what the admin is asked.
const PopconfirmTitle = {
    props: ['title'],
    render() {
        return h('div', {class: 'fct-test-popconfirm-title'}, this.title);
    },
};

const mount = async (component, props) => {
    const root = new FakeNode('div');
    const Host = defineComponent({setup: () => () => h(component, props)});
    renderer.createApp(Host)
        .use(ElementPlus)
        .component('ElDatePicker', stub)
        .component('ElInputNumber', stub)
        .component('ElSelect', stub)
        .component('ElOption', stub)
        .component('ElDropdown', stub)
        .component('ElPopconfirm', PopconfirmTitle)
        .component('ElDrawer', DrawerStub)
        .component('ElPopover', stub)
        .use(fctPlugin({FctDatePicker: stub, FctSelect: stub, FctDropdown: stub, FctDialog: DrawerStub, FctPopover: popoverStub({confirm: PopconfirmTitle, popover: stub})}))
        .provide(ID_INJECTION_KEY, {prefix: 1, current: 0})
        .mount(root);
    await flush();
    return root;
};

const order = currency => ({
    id: 7,
    currency,
    order_items: [{id: 1, post_title: 'Mug', quantity: 1, fulfilled_quantity: 0, fulfillment_type: 'physical'}],
});

const text = root => root.textContent.replace(/\s+/g, ' ');

// The fake DOM joins sibling spans without spaces: name, days, cost.
const rateRow = root => byClass(root, 'fct-shipment-rate')[0].textContent.replace(/\s+/g, ' ').trim();

const clickButton = async (root, label) => {
    byClass(root, 'el-button').filter(button => button.textContent.trim() === label)[0]
        .listeners.click(new MouseEvent());
    await flush();
};

// Continue opens the Method step, which quotes on its own.
const mountFormWithRate = async (orderCurrency, rate) => {
    rest.post.mockResolvedValue({quote: {rates: [rate], rate_object_id: rate.rate_object_id}});
    const root = await mount(ShipmentForm, {order: order(orderCurrency)});
    await clickButton(root, 'Continue');
    return root;
};

const usdRate = {
    rate_object_id: 'r1',
    service_name: 'Priority Mail',
    cost_cents: 9200,
    currency: 'USD',
    estimated_days: 2,
    order_currency_estimate: 1104000,
    order_currency: 'BDT',
};

describe('Create shipment on a BDT order with a USD label', () => {
    beforeAll(() => {
        globalThis.document = {activeElement: null};
    });

    afterAll(() => {
        delete globalThis.document;
    });

    beforeEach(() => {
        rest.post.mockReset();
    });

    it('shows the estimate in the rate row', async () => {
        const root = await mountFormWithRate('BDT', usdRate);
        expect(rateRow(root)).toBe('Priority MailEst. 2 days$92.00 USD (≈ ৳11,040)');
    });

    it('says who bills the cost and what it is roughly in the order currency', async () => {
        const root = await mountFormWithRate('BDT', usdRate);
        const hint = byClass(root, 'fct-shipment-cost-estimate')[0];
        expect(hint.textContent.trim()).toBe('Billed by Shippo in USD. ≈ ৳11,040 in the order currency.');
    });

    it('names the billing currency even without an estimate', async () => {
        const root = await mountFormWithRate('BDT', {...usdRate, order_currency_estimate: null});
        expect(byClass(root, 'fct-shipment-cost-estimate')[0].textContent.trim()).toBe('Billed by Shippo in USD.');
        expect(text(root)).not.toContain('≈');
    });

    it('includes the estimate in the buy confirmation', async () => {
        const root = await mountFormWithRate('BDT', usdRate);
        await clickButton(root, 'Continue');
        const title = byClass(root, 'fct-test-popconfirm-title')[0].textContent;
        expect(title).toBe('Buy ⁨Priority Mail⁩ for ⁨$92.00 USD (≈ ৳11,040)⁩?');
    });

    it('adds nothing when the label is in the order currency', async () => {
        const root = await mountFormWithRate('USD', {...usdRate, order_currency_estimate: null, order_currency: 'USD'});
        expect(rateRow(root)).toBe('Priority MailEst. 2 days$92.00');
        expect(text(root)).not.toContain('≈');
        expect(byClass(root, 'fct-shipment-cost-estimate')).toHaveLength(0);
        await clickButton(root, 'Continue');
        expect(byClass(root, 'fct-test-popconfirm-title')[0].textContent).toBe('Buy ⁨Priority Mail⁩ for ⁨$92.00⁩?');
    });
});

describe('the shipment card cost', () => {
    const shipment = (meta, estimate) => ({
        id: 3,
        status: 'dispatched',
        carrier_slug: 'shippo',
        carrier_name: 'Shippo',
        tracking_number: 'TRK',
        meta,
        order_currency_estimate: estimate,
    });

    it('shows the estimate next to a label billed in another currency', async () => {
        const root = await mount(ShipmentRecord, {shipment: shipment({cost: 9200, cost_currency: 'USD'}, 1104000), orderId: 7, currency: 'BDT'});
        expect(text(root)).toContain('$92.00 (≈ ৳11,040)');
    });

    it('shows the cost alone in the order currency', async () => {
        const root = await mount(ShipmentRecord, {shipment: shipment({cost: 50000}, null), orderId: 7, currency: 'BDT'});
        expect(text(root)).toContain('৳500.00');
        expect(text(root)).not.toContain('≈');
    });
});
