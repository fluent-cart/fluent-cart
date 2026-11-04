import {afterAll, beforeAll, beforeEach, describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick} from 'vue';
import ElementPlus, {ID_INJECTION_KEY} from 'element-plus';

/**
 * The Create shipment Custom price field: it shows the amount with the
 * currency's decimals ("13.70", not "13.7"), formatting on blur only. A picked
 * zone method hides the field and uses the method's price.
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
vi.mock('@/utils/Config/AppConfig', () => ({
    default: {
        get: (key, fallback) => {
            const values = {
                'shop.currency_sign': '$',
                'shop.decimal_separator': 'dot',
                currency_signs: {USD: '$', JPY: '¥', BDT: '৳'},
                currency_decimals: {USD: 2, JPY: 0, BDT: 2},
            };
            return key in values ? values[key] : fallback;
        },
    },
}));
vi.mock('@/utils/support/CurrencyFormatter', () => ({
    default: {formatFixed: (cents, sign, code, currency) => (currency || '') + ' ' + (cents / 100).toFixed(2)},
}));
vi.mock('@/Modules/Shipping/useCarriers', () => ({
    fetchDispatchCarriers: () => Promise.resolve([
        {slug: 'manual', name: 'Manual', is_enabled: true, supports_labels: false, supports_rates: true},
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

const order = currency => ({
    id: 7,
    currency,
    order_items: [{id: 1, post_title: 'Mug', quantity: 1, fulfilled_quantity: 0, fulfillment_type: 'physical'}],
});

const flush = async () => {
    for (let tick = 0; tick < 6; tick++) {
        await Promise.resolve();
        await nextTick();
    }
};

const quote = (costCents, currency) => ({
    quote: {
        rates: [{rate_object_id: 'r1', service_name: 'Ground', cost_cents: costCents, currency}],
        rate_object_id: 'r1',
    },
});

// Stub the pickers the fake DOM cannot run; the cost field is real.
const stub = {render: () => null};

// The drawer teleports, which the fake DOM cannot; render its slots in place.
const DrawerStub = {
    setup: (props, {slots}) => () => h('div', [
        slots.header ? slots.header({close: () => {}, titleId: 'fct-test-drawer-title'}) : null,
        slots.default ? slots.default() : null,
        slots.footer ? slots.footer() : null,
    ]),
};

const mountForm = async (currency) => {
    const root = new FakeNode('div');
    const Host = defineComponent({setup: () => () => h(ShipmentForm, {order: order(currency)})});
    const app = renderer.createApp(Host);
    app.use(ElementPlus)
        .component('ElDatePicker', stub)
        .component('ElInputNumber', stub)
        .component('ElSelect', stub)
        .component('ElOption', stub)
        .component('ElPopconfirm', stub)
        .component('ElDrawer', DrawerStub)
        .provide(ID_INJECTION_KEY, {prefix: 1, current: 0})
        .mount(root);
    await flush();
    return {root, app};
};

const findVnodes = (vnode, match, found = []) => {
    if (!vnode) {
        return found;
    }
    if (match(vnode)) {
        found.push(vnode);
    }
    if (vnode.component) {
        findVnodes(vnode.component.subTree, match, found);
    }
    if (Array.isArray(vnode.children)) {
        vnode.children.forEach(child => findVnodes(child, match, found));
    }
    return found;
};

// The props PriceInput hands its el-input, so a test can call its handlers.
const priceInputProps = (app) => {
    const priceInput = findVnodes(app._instance.vnode, vnode => vnode.type && vnode.type.__name === 'PriceInput')[0];
    return findVnodes(priceInput.component.subTree, vnode => vnode.type && vnode.type.name === 'ElInput')[0].props;
};

const buttonLabelled = (root, label) => byClass(root, 'el-button')
    .filter(button => button.textContent.trim() === label)[0];

// The only input with this placeholder.
const costInput = root => root.findAll(node => node.tagName === 'input' && node.attrs.placeholder === '0.00')[0];

// The Method step quotes on its own as soon as it opens.
const fetchRates = async (root, costCents, currency) => {
    rest.post.mockResolvedValue(quote(costCents, currency));
    buttonLabelled(root, 'Continue').listeners.click(new MouseEvent());
    await flush();
};

const chooseCustomPrice = async (root) => {
    const rows = byClass(root, 'fct-shipment-rate');
    rows[rows.length - 1].listeners.click(new MouseEvent());
    await flush();
};

const typeCost = async (root, app, value) => {
    const input = costInput(root);
    input.value = value;
    input.listeners.input({target: input});
    await flush();
    // el-input listens for blur on its wrapper, out of the fake DOM's reach.
    priceInputProps(app).onBlur();
    await flush();
};

describe('Create shipment cost from a quote', () => {
    // el-input reads the focused element when its value changes; set after
    // import, since vue's DOM runtime would take it for a real document.
    beforeAll(() => {
        globalThis.document = {activeElement: null};
    });

    afterAll(() => {
        delete globalThis.document;
    });

    beforeEach(() => {
        rest.post.mockReset();
    });

    it('hides the cost field while a zone method is picked', async () => {
        const {root} = await mountForm('USD');
        await fetchRates(root, 1370, 'USD');
        expect(costInput(root)).toBeUndefined();
    });

    it('keeps the trailing zero of a custom price', async () => {
        const {root, app} = await mountForm('USD');
        await fetchRates(root, 1370, 'USD');
        await chooseCustomPrice(root);
        await typeCost(root, app, '13.7');
        expect(costInput(root).value).toBe('13.70');
    });

    it('pads a whole amount to the currency decimals', async () => {
        const {root, app} = await mountForm('BDT');
        await fetchRates(root, 1300, 'BDT');
        await chooseCustomPrice(root);
        await typeCost(root, app, '13');
        expect(costInput(root).value).toBe('13.00');
    });

    it('shows no decimals for a zero-decimal currency', async () => {
        const {root, app} = await mountForm('JPY');
        await fetchRates(root, 150000, 'JPY');
        await chooseCustomPrice(root);
        await typeCost(root, app, '1500');
        expect(costInput(root).value).toBe('1,500');
    });

    it('does not reformat while typing, only on blur', async () => {
        const {root, app} = await mountForm('USD');
        await fetchRates(root, 1370, 'USD');
        await chooseCustomPrice(root);

        const input = costInput(root);
        input.value = '12.5';
        input.listeners.input({target: input});
        await flush();
        expect(costInput(root).value).toBe('12.5');

        // el-input listens for blur on its wrapper, out of the fake DOM's reach.
        priceInputProps(app).onBlur();
        await flush();
        expect(costInput(root).value).toBe('12.50');
    });
});
