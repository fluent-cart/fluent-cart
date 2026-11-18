import {describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick} from 'vue';

/**
 * The Display card on the bump screen: one card says where the offer shows, and opens the preview.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    globalThis.window.addEventListener = globalThis.window.addEventListener || (() => {});
    globalThis.window.removeEventListener = globalThis.window.removeEventListener || (() => {});
});

const modal = vi.hoisted(() => ({props: null}));

vi.mock('@/utils/translator/Translator', () => ({default: text => text}));
vi.mock('@/utils/support/CurrencyFormatter', () => ({default: {formatNumber: cents => '$' + cents}}));
vi.mock('@/Bits/Components/Icons/Eye.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/OrderBump/PlacementPreviewModal.vue', () => ({
    default: {
        props: ['modelValue', 'position', 'showInCart', 'bump'],
        setup(props) {
            modal.props = props;
            return () => null;
        },
    },
}));

import PlacementSummaryCard from '@/Modules/OrderBump/parts/PlacementSummaryCard.vue';
import PlacementPreviewButton from '@/Modules/OrderBump/parts/PlacementPreviewButton.vue';
import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';

const flush = async () => {
    for (let tick = 0; tick < 3; tick++) {
        await Promise.resolve();
        await nextTick();
    }
};

const mount = async (render) => {
    const root = new FakeNode('div');
    renderer.createApp(defineComponent({setup: () => render})).mount(root);
    await flush();
    return root;
};

describe('Placement summary card', () => {
    it('says the default placement is under the order notes, and the cart is off', async () => {
        const root = await mount(() => h(PlacementSummaryCard, {position: '', showInCart: 'no'}));

        expect(root.textContent).toContain('Checkout: Under order notes');
        expect(root.textContent).toContain('Cart drawer and Cart page: Off');
    });

    it('names the chosen placement, and marks the cart on', async () => {
        const root = await mount(() => h(PlacementSummaryCard, {position: 'above_summary', showInCart: 'yes'}));

        expect(root.textContent).toContain('Above Order Summary');
        expect(root.textContent).toContain('Cart drawer and Cart page: On');
    });

    it('draws the sketch with the blue block at the chosen place, the default one under the notes', async () => {
        const thumbOf = async position => byClass(await mount(() => h(PlacementSummaryCard, {position})), 'fct-order-bump-placement-thumb')[0].attrs['data-position'];

        expect(await thumbOf('')).toBe('default');
        expect(await thumbOf('below_payment')).toBe('below_payment');
    });

    it('opens the preview from the card, through the slot of the preview button', async () => {
        const root = await mount(() => h(PlacementPreviewButton, {position: '', showInCart: 'no', orderBump: {config: {}}}, {
            default: ({open}) => h(PlacementSummaryCard, {position: '', showInCart: 'no', onClick: open}),
        }));

        expect(modal.props.modelValue).toBe(false);
        byClass(root, 'fct-order-bump-placement-card')[0].listeners.click();
        await flush();
        expect(modal.props.modelValue).toBe(true);
    });
});
