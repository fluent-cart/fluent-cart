import {describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick, reactive} from 'vue';

/**
 * Turning a one-time price into a subscription focuses the Times input when it is
 * rendered; when it is not, the switch must not throw.
 */

const renderSlots = vi.hoisted(() => ({
    setup: (props, {slots}) => () => Object.values(slots).map(slot => slot()),
}));

vi.mock('@/utils/translator/Translator', () => ({default: text => text}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {get: () => false}}));
vi.mock('@/Bits/productService', () => ({formatNumber: n => n, getMargin: () => 0, getProfit: () => 0}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Inputs/ValidationError.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Inputs/PriceInput.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Animation.vue', () => ({default: renderSlots}));
vi.mock('@/Bits/Components/Fct/Field.vue', () => ({default: renderSlots}));
vi.mock('@/Modules/Products/parts/VariantItemCollapse.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/Products/parts/SharedVariantItemBox.vue', () => ({default: renderSlots}));

import VariantPrice from '@/Modules/Products/parts/VariantPrice.vue';
import {FakeNode, renderer} from './support/fake-dom-renderer.js';

describe('VariantPrice subscription switch', () => {
    it('switching to subscription with no Times input throws nothing', async () => {
        const variant = reactive({id: 1, item_price: 1000, other_info: {payment_type: 'onetime', times: ''}});
        const productEditModel = {
            validationErrors: {},
            hasValidationError: () => false,
            isTaxEnabled: () => false,
            updatePricingValue: () => {},
            updatePricingOtherValue: (property, value, fieldKey, target) => {
                target.other_info[property] = value;
            },
        };
        let toggle = null;
        const errors = [];
        const app = renderer.createApp(defineComponent({
            setup: () => () => h(VariantPrice, {variant, fieldKey: 'variants.0', product: {variants: [variant]}, productEditModel}),
        }));
        app.config.errorHandler = error => errors.push(error);
        app.config.warnHandler = () => {};
        app.component('fct-switch', defineComponent({
            emits: ['change'],
            setup: (props, {emit}) => {
                toggle = value => emit('change', value);
                return () => h('div');
            },
        }));
        app.mount(new FakeNode('div'));
        await nextTick();

        toggle(true);
        await nextTick();
        await nextTick();
        app.unmount();

        expect(errors).toEqual([]);
        expect(variant.other_info.payment_type).toBe('subscription');
    });
});
