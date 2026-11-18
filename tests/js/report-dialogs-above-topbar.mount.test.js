import {describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick} from 'vue';

/**
 * .fct-reports-view is position: fixed, a stacking context below the admin top bar,
 * so report dialogs must teleport out of it into the app wrapper.
 */

vi.mock('@/Modules/Reports/Components/ProReportPreview.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/Reports/Components/ProReportSample.vue', () => ({default: {render: () => null}}));
vi.mock('@/utils/translator/Translator', () => ({default: text => text}));

import ProductDetailPreview from '@/Modules/Reports/Product/ProductDetailPreview.vue';
import ChannelRulesPreview from '@/Modules/Reports/Sources/ChannelRulesPreview.vue';
import {FakeNode, renderer} from './support/fake-dom-renderer.js';

const dialogProps = async (component, props) => {
    let seen = null;
    const app = renderer.createApp(defineComponent({setup: () => () => h(component, props)}));
    app.component('fct-dialog', defineComponent({
        inheritAttrs: false,
        setup: (p, {attrs}) => () => {
            seen = attrs;
            return h('div');
        },
    }));
    app.mount(new FakeNode('div'));
    await nextTick();
    app.unmount();
    return seen;
};

describe('report dialogs', () => {
    it.each([
        ['product drawer', ProductDetailPreview, {product: {product_title: 'Mug'}}],
        ['channel rules', ChannelRulesPreview, {modelValue: true}],
    ])('%s teleports into the app wrapper', async (name, component, props) => {
        const attrs = await dialogProps(component, props);
        expect(attrs['append-to']).toBe('#fct_admin_app_wrapper');
    });
});
