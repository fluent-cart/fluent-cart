import {afterEach, describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick} from 'vue';
import {FakeNode, renderer} from './support/fake-dom-renderer.js';

/**
 * The saved billing_summary keeps the wording, price and currency from the day the
 * product was saved, so the Add Items dialog builds the text from other_info instead.
 */

vi.hoisted(() => { globalThis.window = {}; });
vi.mock('@/utils/translator/Translator', () => ({
    default: (text, ...args) => args.reduce((out, arg) => out.replace('%s', arg), text),
    translateNumber: value => value,
}));
vi.mock('@/utils/http/Rest', () => ({default: {get: vi.fn(), post: vi.fn()}}));
vi.mock('@/utils/Notify', () => ({default: {error: vi.fn()}}));
vi.mock('@/utils/support/Asset', () => ({default: {productPlaceholder: () => ''}}));
vi.mock('@/utils/support/CurrencyPricing', () => ({orderQuote: () => null, toPrice: cents => cents}));
vi.mock('@/Bits/productService', () => ({prepareProductList: vi.fn(), formatCapitalized: value => value}));
vi.mock('@/Bits/Components/Pagination.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Badge.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Table/Empty.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/BundleProducts.vue', () => ({default: {render: () => null}}));

import AddProductItemModal from '@/Modules/Orders/Modals/AddProductItemModal.vue';

const renderSlots = defineComponent({setup: (props, {slots}) => () => h('div', slots.default?.())});
const subscription = (price, summary) => ({
    price,
    bundle_items: [],
    other_info: {payment_type: 'subscription', repeat_interval: 'yearly', times: '0', billing_summary: summary},
});

const mounted = [];
afterEach(() => { mounted.splice(0).forEach(app => app.unmount()); });

const mountDialog = async (products) => {
    const root = new FakeNode('div');
    const app = renderer.createApp(AddProductItemModal, {orderParent: {order_items: []}, searchQueryParent: ''});
    app.config.warnHandler = () => {};
    ['fct-dialog', 'fct-input', 'fct-tooltip', 'fct-choice', 'fct-skeleton', 'fct-button'].forEach(name => app.component(name, renderSlots));
    app.mixin({methods: {$t: text => text, formatNumber: cents => 'zł' + (cents / 100).toFixed(2)}});
    app.mount(root);
    mounted.push(app);
    const vm = app._instance.proxy;
    vm.availableProducts = products;
    vm.collapsedStates = products.map(() => false);
    await nextTick();
    return root.findAll(node => node.tagName === 'small').map(node => node.textContent.replace(/\s+/g, ' ').trim());
};

describe('Add Items dialog subscription text', () => {
    it('builds the text from the subscription settings, not the saved summary', async () => {
        const texts = await mountDialog([
            {post_title: 'Simple plan', object_id: 1, children: [], ...subscription(2413, '$24.13 yearly Until Cancel')},
            {
                post_title: 'Variable plan', object_id: 2, min_price: 5900, max_price: 49900, other_info: {},
                children: [{title: 'Yearly License', object_id: 3, ...subscription(5900, '$59 yearly Until Cancel')}],
            },
        ]);

        expect(texts).toEqual(['zł24.13 yearly Until Canceled', 'zł59.00 yearly Until Canceled']);
    });
});
