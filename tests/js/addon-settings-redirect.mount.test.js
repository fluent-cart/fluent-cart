import {beforeEach, describe, expect, it, vi} from 'vitest';
import {h, nextTick} from 'vue';
import ElementPlus, {ID_INJECTION_KEY} from 'element-plus';

/**
 * Settings → Features & addon: an add-on entry's redirect_url (admin route or full URL) drives the gear link and the
 * page the admin lands on after activation. The older settings_route key still works.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    globalThis.window.addEventListener = globalThis.window.addEventListener || (() => {});
    globalThis.window.removeEventListener = globalThis.window.removeEventListener || (() => {});
    globalThis.MouseEvent = globalThis.MouseEvent || class MouseEvent {};
});

const rest = vi.hoisted(() => ({get: vi.fn(), post: vi.fn()}));

vi.mock('vue-router', () => ({useRoute: () => ({})}));
vi.mock('@/utils/http/Rest', () => ({default: rest}));
vi.mock('@/utils/Notify', () => ({default: {success: vi.fn(), error: vi.fn()}}));
vi.mock('@/utils/translator/Translator', () => ({default: (text) => text}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {get: (key, fallback) => (key === 'app_config.isProActive' ? true : (fallback ?? '')), onShopUpdate: () => {}}}));
vi.mock('@/Models/SettingsModel', () => {
    const form = {isReady: false, values: {}};
    form.setSchema = form.setDefaults = form.initForm = () => form;
    return {useSettingsModel: () => ({data: {form}})};
});
vi.mock('@/mixin/saveButtonShortcutMixin.js', () => ({useSaveShortcut: () => ({onSave: () => {}})}));
vi.mock('@/Bits/Components/Form/VueForm.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Table/Empty.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Badge.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/AdminNotice.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/Settings/Parts/SettingsHeader.vue', () => ({default: {render: () => null}}));

import AddonSettings from '@/Modules/Settings/AddonSettings.vue';
import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';
import {registerFctComponents} from '@/Bits/Components/Fct/index.js';

const ADMIN = 'https://shop.test/wp-admin/admin.php?page=fluent-cart';

const addons = () => ({
    shipping: {logo: 'logo.svg', title: 'Shipping', is_installed: true, is_active: true, redirect_url: '/settings/shipping/carriers'},
    legacy: {logo: 'logo.svg', title: 'Legacy', is_installed: true, is_active: true, settings_route: '/settings/legacy'},
    external: {logo: 'logo.svg', title: 'External', is_installed: true, is_active: true, redirect_url: ADMIN + '-external'},
    migrator: {logo: 'logo.svg', title: 'Migrator', is_installed: true, is_active: false, plugin_file: 'migrator/migrator.php', redirect_url: '/settings/migrator'},
});

const recommendations = {list: []};

const flush = async () => {
    for (let tick = 0; tick < 6; tick++) {
        await Promise.resolve();
        await nextTick();
    }
};

const mountPage = async () => {
    const root = new FakeNode('div');
    const app = renderer.createApp(AddonSettings).use(ElementPlus).use(registerFctComponents).provide(ID_INJECTION_KEY, {prefix: 1, current: 0});
    app._context.components.RouterLink = {props: ['to'], setup: (props, {slots}) => () => h('a', {href: '#' + props.to.path}, slots.default())};
    app.mount(root);
    await flush();
    return root;
};

const text = (node) => node.textContent.replace(/\s+/g, ' ').trim();
const row = (root, title) => byClass(root, 'fct-content-card-list-item').find(item => text(item).startsWith(title));
const gear = (item) => item.findAll(node => node.tagName === 'a')[0]?.attrs.href;

describe('Add-on list redirect_url', () => {
    beforeEach(() => {
        rest.get.mockImplementation((path) => Promise.resolve(
            path === 'settings/modules/plugin-addons' ? {addons: addons(), recommendations: recommendations.list} : {settings: {}, fields: {}}
        ));
        recommendations.list = [];
        rest.post.mockReset();
        rest.post.mockResolvedValue({});
        window.location = {href: ADMIN + '#/settings/addons', hash: '#/settings/addons', reload: vi.fn(), assign: vi.fn()};
        window.history = {replaceState: vi.fn()};
    });

    it('links the gear to redirect_url, a route or a full URL, and still reads settings_route', async () => {
        const root = await mountPage();

        expect(gear(row(root, 'Shipping'))).toBe('#/settings/shipping/carriers');
        expect(gear(row(root, 'Legacy'))).toBe('#/settings/legacy');
        expect(gear(row(root, 'External'))).toBe(ADMIN + '-external');
        expect(gear(row(root, 'Migrator'))).toBeUndefined();
    });

    it('reloads on redirect_url after activation', async () => {
        vi.useFakeTimers();
        const root = await mountPage();

        const activate = row(root, 'Migrator').findAll(node => node.tagName === 'button')[0];
        activate.listeners.click({stopPropagation: () => {}, preventDefault: () => {}});
        await flush();
        vi.runAllTimers();
        vi.useRealTimers();

        expect(rest.post).toHaveBeenCalledWith('settings/modules/plugin-addons/activate', {plugin_file: 'migrator/migrator.php'});
        expect(window.history.replaceState).toHaveBeenCalledWith(null, '', '#/settings/migrator');
        expect(window.location.reload).toHaveBeenCalled();
    });

    it('shows recommended add-ons above the list and activates one in place', async () => {
        vi.useFakeTimers();
        recommendations.list = [{
            key: 'fluent-cart-customer-rights', title: 'Customer Rights', logo: 'logo.svg', badge: 'Required in Germany',
            reason: 'Your store is in Germany.', is_installed: true, plugin_file: 'customer-rights/customer-rights.php',
            redirect_url: '/settings/withdrawal-settings',
        }];
        const root = await mountPage();

        const card = byClass(root, 'fct-card')[0];
        expect(text(card)).toContain('Recommended for your store');
        expect(text(card)).toContain('Your store is in Germany.');

        card.findAll(node => node.tagName === 'button')[0].listeners.click({stopPropagation: () => {}, preventDefault: () => {}});
        await flush();
        vi.runAllTimers();
        vi.useRealTimers();

        expect(rest.post).toHaveBeenCalledWith('settings/modules/plugin-addons/activate', {plugin_file: 'customer-rights/customer-rights.php'});
        expect(window.history.replaceState).toHaveBeenCalledWith(null, '', '#/settings/withdrawal-settings');
    });

    it('shows no recommended card when nothing matches', async () => {
        const root = await mountPage();

        expect(text(byClass(root, 'fct-card')[0] || {textContent: ''})).not.toContain('Recommended for your store');
    });
});
