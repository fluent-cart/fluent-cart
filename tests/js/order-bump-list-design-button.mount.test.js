import {describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick} from 'vue';

/**
 * The Order Bumps page has a Design button beside Add New. It opens the card design page.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    globalThis.window.addEventListener = globalThis.window.addEventListener || (() => {});
    globalThis.window.removeEventListener = globalThis.window.removeEventListener || (() => {});
});

vi.mock('@/utils/translator/Translator', () => ({default: text => text}));
vi.mock('@/utils/table-new/OrderBumpTable', () => ({default: () => ({isLoading: () => false})}));
vi.mock('@/Bits/Components/Layout/Page.vue', () => ({
    default: {render() { return h('div', {class: 'fake-page'}, [h('div', {class: 'actions'}, this.$slots.actions && this.$slots.actions()), this.$slots.default && this.$slots.default()]); }},
}));
vi.mock('@/Bits/Components/TableNew/TableWrapper.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/OrderBump/OrderBumpsTable.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/OrderBump/OrderBumpsTableLoader.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/OrderBump/CreateNewOrderBump.vue', () => ({default: {render: () => h('button', {class: 'add-new'}, 'Add New')}}));

import AllOrderBump from '@/Modules/OrderBump/AllOrderBump.vue';
import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';

const mountPage = async () => {
    const root = new FakeNode('div');
    const app = renderer.createApp(defineComponent({setup: () => () => h(AllOrderBump)}));
    app.component('fct-button', defineComponent({
        props: ['to'],
        render() { return h('button', {class: 'fct-button', 'data-to': JSON.stringify(this.to || null)}, this.$slots.default && this.$slots.default()); }
    }));
    app.mount(root);
    await Promise.resolve();
    await nextTick();
    return root;
};

describe('Order Bumps page', () => {
    it('has a Design button that opens the card design page', async () => {
        const root = await mountPage();
        const design = byClass(root, 'fct-button').filter(node => node.textContent.trim() === 'Design');

        expect(design).toHaveLength(1);
        expect(JSON.parse(design[0].attrs['data-to'])).toEqual({name: 'order_bump_design'});
    });

    it('keeps the Design button beside Add New', async () => {
        const root = await mountPage();
        const actions = byClass(root, 'actions')[0];

        expect(actions.textContent).toContain('Design');
        expect(actions.textContent).toContain('Add New');
        expect(actions.textContent.indexOf('Design')).toBeLessThan(actions.textContent.indexOf('Add New'));
    });
});
