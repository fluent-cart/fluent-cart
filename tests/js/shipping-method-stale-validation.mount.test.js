import {describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick, watch} from 'vue';

/**
 * The shipping method drawer reuses one form for Add and Edit; an error left from
 * a failed Add must not show when the drawer opens again.
 */

vi.mock('@/utils/translator/Translator', () => ({default: text => text}));
vi.mock('@/utils/http/Rest', () => ({default: {get: () => new Promise(() => {})}}));
vi.mock('@/utils/Notify', () => ({default: {error: () => {}, success: () => {}}}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {get: () => 'kg'}}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Badge.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Buttons/IconButton.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/Shipping/Components/WeightTiersEditor.vue', () => ({default: {render: () => null}}));

import ShippingMethods from '@/Modules/Shipping/ShippingMethods.vue';
import {FakeNode, renderer} from './support/fake-dom-renderer.js';

const passSlots = {setup: (props, {slots}) => () => h('div', slots.default && slots.default())};

describe('shipping method drawer', () => {
    it('clears old validation errors each time it opens', async () => {
        const clearValidate = vi.fn();
        const buttons = [];
        const app = renderer.createApp(defineComponent({setup: () => () => h(ShippingMethods, {zone_id: 2})}));
        app.config.warnHandler = () => {};
        app.component('fct-button', defineComponent({
            emits: ['click'],
            setup: (props, {slots, emit}) => {
                const button = {text: () => (slots.default ? slots.default().map(node => node.children).join('') : ''), click: () => emit('click')};
                buttons.push(button);
                return () => h('button', slots.default && slots.default());
            },
        }));
        // Like el-drawer: emits open each time it becomes visible.
        app.component('fct-dialog', defineComponent({
            props: {modelValue: Boolean},
            emits: ['open'],
            setup: (props, {slots, emit}) => {
                watch(() => props.modelValue, open => open && emit('open'));
                return () => h('div', slots.default && slots.default());
            },
        }));
        app.component('el-form', defineComponent({
            setup: (props, {slots, expose}) => {
                expose({clearValidate, validate: () => {}});
                return () => h('form', slots.default && slots.default());
            },
        }));
        ['fct-table', 'fct-table-column'].forEach(name => app.component(name, {render: () => null}));
        ['el-form-item', 'fct-switch', 'fct-input', 'fct-select', 'fct-popover', 'fct-tooltip'].forEach(name => app.component(name, passSlots));
        ['CardContainer', 'CardBody', 'CardHeader'].forEach(name => app.component(name, passSlots));
        app.mount(new FakeNode('div'));
        await nextTick();

        buttons.find(button => button.text().includes('Add Shipping Method')).click();
        await nextTick();
        await nextTick();
        app.unmount();

        expect(clearValidate).toHaveBeenCalled();
    });
});
