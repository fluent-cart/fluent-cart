import {describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick} from 'vue';

/**
 * The placement preview on the order bump screen: the select keeps Default as an empty
 * saved value, and the sample offer is priced like the real checkout card.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    globalThis.window.addEventListener = globalThis.window.addEventListener || (() => {});
    globalThis.window.removeEventListener = globalThis.window.removeEventListener || (() => {});
});

const modal = vi.hoisted(() => ({props: null}));

vi.mock('@/utils/translator/Translator', () => ({default: text => text}));
// Money here is in cents, like item_price.
vi.mock('@/utils/support/CurrencyFormatter', () => ({
    default: {formatNumber: cents => '$' + (cents / 100).toFixed(2)},
}));
vi.mock('@/Bits/Components/Icons/Eye.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/OrderBump/PlacementPreviewModal.vue', () => ({
    default: {
        props: ['modelValue', 'position', 'showInCart', 'bump'],
        setup(props) {
            modal.props = props;
            return () => null;
        },
    },
}));

import PlacementSelect from '@/Modules/OrderBump/parts/PlacementSelect.vue';
import PlacementPreviewButton from '@/Modules/OrderBump/parts/PlacementPreviewButton.vue';
import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';

const flush = async () => {
    for (let tick = 0; tick < 3; tick++) {
        await Promise.resolve();
        await nextTick();
    }
};

const mount = async (render, register = () => {}) => {
    const root = new FakeNode('div');
    const app = renderer.createApp(defineComponent({setup: () => render}));
    register(app);
    app.mount(root);
    await flush();
    return root;
};

describe('Placement select', () => {
    const mountSelect = async modelValue => {
        const seen = {select: null, emitted: []};
        const ElSelect = defineComponent({
            props: ['modelValue'],
            setup(props, {emit, slots}) {
                seen.select = emit;
                return () => h('div', {class: 'fake-select', 'data-value': props.modelValue}, slots.default && slots.default());
            },
        });
        const root = await mount(
            () => h(PlacementSelect, {modelValue, 'onUpdate:modelValue': value => seen.emitted.push(value)}),
            app => {
                app.component('fct-select', ElSelect);
                app.component('el-option', defineComponent({props: ['label', 'value'], render: () => null}));
            }
        );
        return {...seen, shown: byClass(root, 'fake-select')[0].attrs['data-value']};
    };

    it('shows Default for a saved empty position, because an empty model shows a placeholder', async () => {
        expect((await mountSelect('')).shown).toBe('default');
    });

    it('shows any other saved position as it is', async () => {
        expect((await mountSelect('below_payment')).shown).toBe('below_payment');
    });

    it('saves Default as an empty position and passes the other choices on', async () => {
        const picked = await mountSelect('below_payment');

        picked.select('update:modelValue', 'default');
        picked.select('update:modelValue', 'disabled');

        expect(picked.emitted).toEqual(['', 'disabled']);
    });
});

describe('Placement preview sample offer', () => {
    const variant = {id: 31, item_price: 1250, variation_title: 'White', thumbnail: 'https://x.test/variant.jpg', product: {post_title: 'Zipper Hoodie'}};
    const bumpFor = async (orderBump, forVariant = variant) => {
        await mount(() => h(PlacementPreviewButton, {orderBump, variant: forVariant, position: '', showInCart: 'no'}));
        return modal.props.bump;
    };
    const offer = (discount, extra = {}) => ({title: 'Hoodie deal', description: '', src_object_id: 31, config: {discount, ...extra}});

    it('takes a percentage off the price, rounded to the cent', async () => {
        const bump = await bumpFor(offer({discount_type: 'percentage', discount_amount: 25}), {...variant, item_price: 1999});

        expect(bump.originalPrice).toBe('$19.99');
        expect(bump.price).toBe('$14.99');
        expect(bump.savings).toBe('$5.00');
    });

    it('reads a fixed amount in currency units, not cents', async () => {
        const bump = await bumpFor(offer({discount_type: 'fixed', discount_amount: 2.5}));

        expect(bump.originalPrice).toBe('$12.50');
        expect(bump.price).toBe('$10.00');
        expect(bump.savings).toBe('$2.50');
    });

    it('never goes below zero when the discount is larger than the price', async () => {
        const bump = await bumpFor(offer({discount_type: 'fixed', discount_amount: 99}));

        expect(bump.price).toBe('$0.00');
        expect(bump.savings).toBe('$12.50');
    });

    it('shows one price and no savings when there is no discount', async () => {
        const bump = await bumpFor(offer({discount_type: 'percentage', discount_amount: 0}));

        expect(bump.price).toBe('$12.50');
        expect(bump.originalPrice).toBe('');
        expect(bump.savings).toBe('');
    });

    it('has no offer to show until the product is loaded', async () => {
        expect(await bumpFor(offer({discount_type: 'fixed', discount_amount: 1}), null)).toBeNull();
    });

    it('shows no offer once another product is picked, until the new one is saved and loaded', async () => {
        const picked = {...offer({discount_type: 'fixed', discount_amount: 1}), src_object_id: 44};
        const cleared = {...offer({discount_type: 'fixed', discount_amount: 1}), src_object_id: ''};

        expect(await bumpFor(picked)).toBeNull();
        expect(await bumpFor(cleared)).toBeNull();
        // The form can hold the id as text; the same product still shows.
        expect(await bumpFor({...picked, src_object_id: '31'})).not.toBeNull();
    });

    it('prefers the custom image and skips a variation title that repeats the product name', async () => {
        const bump = await bumpFor(
            offer({discount_type: 'fixed', discount_amount: 1}, {image: {id: 7, url: 'https://x.test/custom.jpg'}}),
            {...variant, variation_title: 'Zipper Hoodie'}
        );

        expect(bump.imageUrl).toBe('https://x.test/custom.jpg');
        expect(bump.variationTitle).toBe('');
    });
});
