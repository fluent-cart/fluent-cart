import {beforeAll, describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick} from 'vue';
import ElementPlus, {ID_INJECTION_KEY} from 'element-plus';

/**
 * Zones moved to the Manual carrier page: the old /settings/shipping route shows a notice that links there, and the
 * settings sidebar keeps a Shipping Zones item that opens that notice.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    globalThis.window.innerWidth = 1280;
    globalThis.window.addEventListener = () => {};
    globalThis.window.removeEventListener = () => {};
});

const route = vi.hoisted(() => ({path: '/settings/shipping/carriers/manual'}));

vi.mock('@/utils/translator/Translator', async () => ({default: (await import('./support/reserved-words.js')).withReserved(text => text)}));
vi.mock('vue-router', async () => {
    const {h} = await import('vue');
    return {
        useRoute: () => route,
        useRouter: () => ({hasRoute: name => name === 'shipping', push: () => {}}),
        RouterLink: {props: ['to'], setup: (props, {slots}) => () => h('a', {'data-to': JSON.stringify(props.to)}, slots.default && slots.default())},
    };
});
vi.mock('@/utils/Config/AppConfig', () => ({default: {get: (key, fallback) => fallback}}));
vi.mock('@/utils/permission/Permission', () => ({default: {has: () => true, hasAny: () => true}}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Animation.vue', () => ({
    default: {props: ['visible'], setup: (props, {slots}) => () => (props.visible ? slots.default() : null)},
}));

import ShippingZonesMoved from '@/Modules/Shipping/ShippingZonesMoved.vue';
import SettingsView from '@/Modules/Settings/SettingsView.vue';
import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';
import {registerFctComponents} from '@/Bits/Components/Fct/index.js';
import {RouterLink} from 'vue-router';


const mount = async (component) => {
    const root = new FakeNode('div');
    const Host = defineComponent({setup: () => () => h(component)});
    renderer.createApp(Host).use(ElementPlus).use(registerFctComponents).component('router-link', RouterLink)
        .provide(ID_INJECTION_KEY, {prefix: 1, current: 0}).mount(root);
    await nextTick();
    return root;
};

describe('Shipping zones moved notice', () => {
    it('explains the move and links to the Manual carrier page', async () => {
        const root = await mount(ShippingZonesMoved);

        const heading = root.findAll(node => node.tagName === 'h2')[0];
        expect(heading.textContent.trim()).toBe('Shipping zones have moved');
        expect(root.textContent).toContain('Shipping zones are now part of the Manual carrier.');

        const link = root.findAll(node => node.tagName === 'a' && node.textContent.includes('Go to Manual carrier'))[0];
        expect(JSON.parse(link.attrs['data-to'])).toEqual({name: 'carrier_settings', params: {slug: 'manual'}});
    });

    it('shows the path to the new location', async () => {
        const root = await mount(ShippingZonesMoved);

        const path = byClass(root, 'fct-shipping-zones-moved-path')[0];
        const items = byClass(path, 'el-breadcrumb__inner').map(item => item.textContent.trim());
        expect(items).toEqual(['Shipping', 'Carriers', 'Manual / Other', 'Shipping Zones']);
    });
});

describe('Settings sidebar shipping group', () => {
    // Set after Vue loads: runtime-dom probes document.createElement on import.
    beforeAll(() => {
        globalThis.document = globalThis.document || {
            body: {classList: {toggle: () => {}, remove: () => {}}},
            getElementById: () => null,
        };
    });

    const shippingGroup = root => byClass(root, 'fct-settings-nav-item')
        .find(item => byClass(item, 'fct-settings-nav-link-text')[0]?.textContent.trim() === 'Shipping');

    const activeChildren = group => byClass(byClass(group, 'fct-settings-nav-child-list')[0], 'fct-settings-nav-item-active')
        .map(item => item.textContent.trim());

    it('opens on the Carriers page and still lists Shipping Zones for the moved notice', async () => {
        route.path = '/settings/shipping/carriers/manual';
        const root = await mount(SettingsView);
        const group = shippingGroup(root);

        expect(JSON.parse(group.findAll(node => node.tagName === 'a')[0].attrs['data-to'])).toBe('/settings/shipping/carriers');
        const children = byClass(group, 'fct-settings-nav-child-list')[0].findAll(node => node.tagName === 'a');
        expect(children.map(link => link.textContent.trim())).toEqual(['Carriers', 'Shipping Zones', 'Shipping Classes', 'Packages']);
        expect(JSON.parse(children[1].attrs['data-to'])).toBe('/settings/shipping');

        expect(activeChildren(group)).toEqual(['Carriers']);
    });

    it('marks Shipping Zones active on the moved notice', async () => {
        route.path = '/settings/shipping';
        const root = await mount(SettingsView);

        expect(activeChildren(shippingGroup(root))).toEqual(['Shipping Zones']);
    });

    it('keeps the shipping group open on a zone edit page', async () => {
        route.path = '/settings/shipping/zone/3/edit';
        const root = await mount(SettingsView);

        expect(byClass(shippingGroup(root), 'fct-settings-nav-child-list')).toHaveLength(1);
    });
});
