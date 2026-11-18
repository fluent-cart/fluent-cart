import {afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick} from 'vue';
import ElementPlus, {ID_INJECTION_KEY} from 'element-plus';
import {fctPlugin, popoverStub} from './support/fct-stubs.js';
import {fctComponents} from '@/Bits/Components/Fct';

/**
 * How the Create shipment drawer reads: the order and its destination on lines of
 * their own, the zone methods' source said once, in the list header, and one hint
 * under both tracking fields instead of one under Tracking URL alone.
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
    default: {formatFixed: (cents) => '$' + (cents / 100).toFixed(2)},
}));
vi.mock('@/Modules/Shipping/useCarriers', () => ({
    fetchDispatchCarriers: () => Promise.resolve([
        {slug: 'manual', name: 'Manual / Other', is_enabled: true, supports_labels: false, supports_rates: true},
    ]),
}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Buttons/IconButton.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Badge.vue', () => ({default: {render: () => null}}));
// The real transition animates element styles the fake DOM does not have.
vi.mock('@/Bits/Components/Animation.vue', async () => {
    const {h} = await import('vue');
    return {default: {props: ['visible'], setup: (props, {slots}) => () => (props.visible ? h('div', slots.default ? slots.default() : []) : null)}};
});

import ShipmentForm from '@/Modules/Orders/Components/ShipmentForm.vue';
import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';

const flush = async () => {
    for (let tick = 0; tick < 6; tick++) {
        await Promise.resolve();
        await nextTick();
    }
};

const stub = {render: () => null};

const DatePickerStub = {
    props: ['modelValue'],
    emits: ['update:modelValue'],
    setup: (props, {emit, expose}) => {
        expose({focus: () => {}});
        return () => h('input', {class: 'fct-test-date', value: props.modelValue, onInput: event => emit('update:modelValue', event.target.value)});
    },
};

// The drawer teleports, which the fake DOM cannot; render its slots in place.
const DrawerStub = {
    setup: (props, {slots}) => () => h('div', [
        slots.header ? slots.header({close: () => {}, titleId: 'fct-test-drawer-title'}) : null,
        slots.default ? slots.default() : null,
        slots.footer ? slots.footer() : null,
    ]),
};

const order = {
    id: 7,
    invoice_no: 'INV-7',
    currency: 'USD',
    shipping_address: {formatted_address: {full_name: 'Jane Buyer', full_address: '2 Market St, Apt 9, New York, NY 10001, United States'}},
    order_items: [{id: 1, post_title: 'Mug', quantity: 2, fulfilled_quantity: 0, fulfillment_type: 'physical'}],
};

const quote = {quote: {rates: [{rate_object_id: 'flat', service_name: 'Flat rate', cost_cents: 500, currency: 'USD'}], rate_object_id: 'flat'}};

const mountForm = async () => {
    const root = new FakeNode('div');
    const Host = defineComponent({setup: () => () => h(ShipmentForm, {order})});
    renderer.createApp(Host)
        .use(ElementPlus)
        .component('ElDatePicker', DatePickerStub)
        .component('ElInputNumber', stub)
        .component('ElSelect', stub)
        .component('ElOption', stub)
        .component('ElPopconfirm', stub)
        .component('ElDrawer', DrawerStub)
        .use(fctPlugin({FctDatePicker: DatePickerStub, FctSelect: stub, FctDialog: DrawerStub, FctPopover: popoverStub({confirm: stub, popover: fctComponents.FctPopover})}))
        .provide(ID_INJECTION_KEY, {prefix: 1, current: 0})
        .mount(root);
    await flush();
    return root;
};

const click = async (root, className, label) => {
    byClass(root, className).filter(node => node.textContent.trim() === label)[0].listeners.click(new MouseEvent());
    await flush();
};

const fieldOf = (node) => {
    for (let parent = node.parentNode; parent; parent = parent.parentNode) {
        if (String(parent.className).split(/\s+/).includes('el-form-item')) {
            return parent;
        }
    }
    return null;
};

describe('Create shipment drawer layout', () => {
    beforeAll(() => {
        globalThis.document = {activeElement: null};
    });

    afterAll(() => {
        delete globalThis.document;
    });

    beforeEach(() => {
        rest.post.mockReset();
        rest.post.mockImplementation(url => Promise.resolve(url.endsWith('/quote') ? quote : {message: 'Created', shipment: {id: 1}}));
    });

    it('shows the order and its whole destination on lines of their own', async () => {
        const root = await mountForm();

        expect(byClass(root, 'fct-shipment-drawer__order')[0].textContent.trim()).toBe('Order INV-7');
        const shipTo = byClass(root, 'fct-shipment-drawer__ship-to')[0];
        const destination = 'Jane Buyer, 2 Market St, Apt 9, New York, NY 10001, United States';
        expect(shipTo.textContent.trim()).toBe('Ship to ' + destination);
        expect(shipTo.attrs.title, 'the line wraps, so nothing is hidden behind a hover').toBeUndefined();
    });

    it('says where the zone methods come from once, in the list header', async () => {
        const root = await mountForm();
        await click(root, 'el-button', 'Continue');

        expect(root.findAll(node => node.textContent.trim() === 'Methods from your shipping zones')).toHaveLength(0);
        expect(byClass(root, 'fct-shipment-rates__head')[0].textContent).toContain('1 method from your shipping zones');
    });

    it('puts one hint under both tracking fields, not under Tracking URL alone', async () => {
        const root = await mountForm();
        await click(root, 'el-button', 'Continue');

        const hints = byClass(root, 'fct-shipment-tracking-hint');
        expect(hints).toHaveLength(1);
        expect(fieldOf(hints[0])).toBeNull();
        const url = root.findAll(node => node.tagName === 'input' && node.attrs['aria-describedby'] === hints[0].attrs.id);
        expect(url).toHaveLength(1);
    });
});
