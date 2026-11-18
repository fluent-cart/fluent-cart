import {beforeEach, describe, expect, it, vi} from 'vitest';
import {h, nextTick} from 'vue';
import ElementPlus, {ID_INJECTION_KEY} from 'element-plus';

/**
 * Shipping → Carriers: without the shipping add-on the list only has Manual, so a super admin sees an add-on row
 * that installs or activates it in place. Nobody else sees it, and an active add-on shows its own carriers instead.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    globalThis.window.addEventListener = globalThis.window.addEventListener || (() => {});
    globalThis.window.removeEventListener = globalThis.window.removeEventListener || (() => {});
    globalThis.MouseEvent = globalThis.MouseEvent || class MouseEvent {};
});

const rest = vi.hoisted(() => ({get: vi.fn(), post: vi.fn()}));
const access = vi.hoisted(() => ({superAdmin: true}));

vi.mock('vue-router', () => ({useRoute: () => ({}), useRouter: () => ({push: () => {}})}));
vi.mock('@/utils/http/Rest', () => ({default: rest}));
vi.mock('@/utils/Notify', () => ({default: {success: vi.fn(), error: vi.fn()}}));
vi.mock('@/utils/Clipboard', () => ({default: {copy: vi.fn()}}));
vi.mock('@/utils/translator/Translator', () => ({default: (text) => text}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {get: (key, fallback) => (key === 'app_config.isProActive' ? true : (fallback ?? '')), onShopUpdate: () => {}}}));
vi.mock('@/utils/permission/Permission', () => ({default: {isSuperAdmin: () => access.superAdmin}}));
vi.mock('@/Modules/Shipping/useCarriers', () => ({fetchCarriers: () => Promise.resolve([
    {slug: 'manual', is_enabled: true, meta: {name: 'Manual / Other', description: 'Zone-based rates.'}},
])}));
vi.mock('@/Modules/Settings/Parts/SettingsHeader.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Badge.vue', () => ({default: {props: ['text'], setup: (props) => () => h('span', {class: 'badge'}, props.text)}}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {render: () => null}}));

import Carriers from '@/Modules/Shipping/Carriers.vue';
import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';
import {registerFctComponents} from '@/Bits/Components/Fct/index.js';

const shippingAddon = (installed, active) => ({
    'shipping-for-fluent-cart': {
        title: 'Shipping For FluentCart', description: 'Live rates.', plugin_slug: 'shipping-for-fluent-cart',
        plugin_file: 'shipping-for-fluent-cart/shipping-for-fluent-cart.php', source_type: 'cdn',
        source_link: 'https://addons-cdn.fluentcart.com/shipping-for-fluent-cart.zip', redirect_url: '/settings/shipping/carriers',
        is_installed: installed, is_active: active,
    },
});

const flush = async () => {
    for (let tick = 0; tick < 6; tick++) {
        await Promise.resolve();
        await nextTick();
    }
};

const mountPage = async (addons) => {
    rest.get.mockResolvedValue({addons});
    const root = new FakeNode('div');
    renderer.createApp({render: () => h(Carriers)}).use(ElementPlus).use(registerFctComponents).provide(ID_INJECTION_KEY, {prefix: 1, current: 0}).mount(root);
    await flush();
    return root;
};

const text = (node) => node.textContent.replace(/\s+/g, ' ').trim();
const rows = (root) => byClass(root, 'fct-content-card-list-item');
const button = (row) => row.findAll(node => node.tagName === 'button')[0];

describe('Carriers shipping add-on row', () => {
    beforeEach(() => {
        access.superAdmin = true;
        rest.get.mockReset();
        rest.post.mockReset();
        rest.post.mockResolvedValue({});
        window.location = {href: 'https://shop.test/wp-admin/admin.php?page=fluent-cart#/settings/shipping/carriers', reload: vi.fn(), assign: vi.fn()};
        window.history = {replaceState: vi.fn()};
    });

    it('shows the add-on under Manual while it is not active', async () => {
        const root = await mountPage(shippingAddon(true, false));

        expect(rows(root).map(row => text(row).split(' ')[0])).toEqual(['Manual', 'Shipping']);
        expect(text(button(rows(root)[1]))).toBe('Activate');
        expect(text(button(rows(await mountPage(shippingAddon(false, false)))[1]))).toBe('Install & Activate');
    });

    it('hides the row when the add-on is active or the user is not a super admin', async () => {
        expect(rows(await mountPage(shippingAddon(true, true)))).toHaveLength(1);

        access.superAdmin = false;
        rest.get.mockClear();
        expect(rows(await mountPage(shippingAddon(true, false)))).toHaveLength(1);
        expect(rest.get).not.toHaveBeenCalled();
    });

    it('activates in place, then reloads on Carriers', async () => {
        vi.useFakeTimers();
        const root = await mountPage(shippingAddon(true, false));

        button(rows(root)[1]).listeners.click({stopPropagation: () => {}, preventDefault: () => {}});
        await flush();
        vi.runAllTimers();
        vi.useRealTimers();

        expect(rest.post).toHaveBeenCalledWith('settings/modules/plugin-addons/activate', {plugin_file: 'shipping-for-fluent-cart/shipping-for-fluent-cart.php'});
        expect(window.history.replaceState).toHaveBeenCalledWith(null, '', '#/settings/shipping/carriers');
        expect(window.location.reload).toHaveBeenCalled();
    });
});
