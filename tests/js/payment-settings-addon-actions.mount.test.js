import {beforeEach, describe, expect, it, vi} from 'vitest';
import {h, nextTick} from 'vue';
import ElementPlus, {ID_INJECTION_KEY} from 'element-plus';

/**
 * Payment Settings list: an add-on gateway that is not installed shows Install & Activate (Download without Pro),
 * an installed but inactive one shows Activate, everything else shows Manage. Clicking the row opens the manage page.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    globalThis.window.addEventListener = globalThis.window.addEventListener || (() => {});
    globalThis.window.removeEventListener = globalThis.window.removeEventListener || (() => {});
    globalThis.MouseEvent = globalThis.MouseEvent || class MouseEvent {};
});

const config = vi.hoisted(() => ({pro: true}));
const router = vi.hoisted(() => ({push: vi.fn(), resolve: ({name}) => ({path: '/settings/payments/' + name})}));
const notify = vi.hoisted(() => ({success: vi.fn(), error: vi.fn()}));

vi.mock('vue-router', () => ({useRouter: () => router}));
vi.mock('@/utils/Notify', () => ({default: notify}));
vi.mock('@/utils/translator/Translator', () => ({default: (text) => text}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {get: (key, fallback) => (key === 'app_config.isProActive' ? config.pro : (fallback ?? '')), onShopUpdate: () => {}}}));
vi.mock('vue-draggable-next', () => ({VueDraggableNext: {inheritAttrs: false, setup: (props, {slots}) => () => slots.default()}}));
vi.mock('@/Modules/Settings/Parts/SettingsHeader.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/AdminNotice.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Badge.vue', () => ({default: {props: ['text'], setup: (props) => () => h('span', {class: 'badge'}, props.text)}}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {render: () => null}}));

import PaymentSettings from '@/Modules/Settings/PaymentSettings.vue';
import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';
import {registerFctComponents} from '@/Bits/Components/Fct/index.js';

const addon = (route, installed, active) => ({
    route, title: route, is_addon: true, status: false,
    addon_status: {is_installed: installed, is_active: active, plugin_file: route + '/' + route + '.php'},
    addon_source: {type: 'cdn', link: 'https://addons-cdn.fluentcart.com/' + route + '.zip', slug: route, repo_link: 'https://fluentcart.com/fluentcart-addons'},
});

const fixtures = {list: null};
const gateways = () => fixtures.list || [
    {route: 'stripe', title: 'Stripe', status: true},
    addon('paystack', false, false),
    addon('razorpay', true, false),
];

// Square and Airwallex: their add-ons only run with FluentCart Pro.
const proAddon = (route, installed) => ({...addon(route, installed, false), requires_pro: true,
    addon_source: {type: 'cdn', link: 'https://fluentcart.com/?fluent-cart=get_license_version', slug: route, repo_link: 'https://fluentcart.com/pricing/'}});

const post = vi.fn();

const flush = async () => {
    for (let tick = 0; tick < 6; tick++) {
        await Promise.resolve();
        await nextTick();
    }
};

const mountPage = async () => {
    const root = new FakeNode('div');
    const app = renderer.createApp(PaymentSettings).use(ElementPlus).use(registerFctComponents).provide(ID_INJECTION_KEY, {prefix: 1, current: 0});
    app._context.components.ElTooltip = {setup: (props, {slots}) => () => slots.default()};
    app._context.components.RouterLink = {props: ['to'], setup: (props, {slots}) => () => h('a', {href: '#' + props.to.name}, slots.default())};
    // The admin app adds $get/$post through a global mixin.
    app.mixin({methods: {$get: () => Promise.resolve({gateways: gateways()}), $post: (...args) => post(...args)}});
    app.mount(root);
    await flush();
    return root;
};

const text = (node) => node.textContent.replace(/\s+/g, ' ').trim();
const event = () => ({stopPropagation: () => {}, preventDefault: () => {}});
const rows = (root) => byClass(root, 'fct-list-item');
// The first button in a row is now the reorder handle; the action sits in the actions slot.
const rowButton = (row) => byClass(row, 'fct-list-item__actions')[0].findAll(node => node.tagName === 'button')[0];
const click = async (node) => { node.listeners.click(event()); await flush(); };

describe('Payment Settings add-on actions', () => {
    beforeEach(() => {
        config.pro = true;
        fixtures.list = null;
        router.push.mockReset();
        post.mockReset();
        post.mockResolvedValue({});
        window.location = {reload: vi.fn()};
        window.history = {replaceState: vi.fn()};
        window.open = vi.fn();
    });

    it('shows Install, Activate or Manage by add-on state', async () => {
        const root = await mountPage();

        expect(rows(root).map(row => text(rowButton(row)))).toEqual(['Manage', 'Install & Activate', 'Activate']);
    });

    it('opens the manage page from a click anywhere on the row, installed or not', async () => {
        const root = await mountPage();

        await click(rows(root)[1]);
        expect(router.push).toHaveBeenCalledWith({name: 'paystack'});
    });

    it('gives keyboard users a link to the manage page on every row', async () => {
        const root = await mountPage();

        const links = rows(root).map(row => row.findAll(node => node.tagName === 'a')[0]?.attrs.href);
        expect(links).toEqual(['#stripe', '#paystack', '#razorpay']);
    });

    it('installs and activates with Pro, then reloads on the gateway settings page', async () => {
        const root = await mountPage();

        await click(rowButton(rows(root)[1]));
        expect(post).toHaveBeenCalledWith('settings/payment-methods/install-addon', {plugin_slug: 'paystack'});
        expect(window.history.replaceState).toHaveBeenCalledWith(null, '', '#/settings/payments/paystack');
        expect(window.location.reload).toHaveBeenCalled();
    });

    it('activates an installed add-on, then reloads on the gateway settings page', async () => {
        const root = await mountPage();

        await click(rowButton(rows(root)[2]));
        expect(post).toHaveBeenCalledWith('settings/payment-methods/activate-addon', {plugin_file: 'razorpay/razorpay.php'});
        expect(window.history.replaceState).toHaveBeenCalledWith(null, '', '#/settings/payments/razorpay');
    });

    it('offers a download link without Pro instead of installing', async () => {
        config.pro = false;
        const root = await mountPage();

        expect(text(rowButton(rows(root)[1]))).toBe('Download');
        await click(rowButton(rows(root)[1]));
        expect(window.open).toHaveBeenCalledWith('https://fluentcart.com/fluentcart-addons', '_blank', 'noopener,noreferrer');
        expect(post).not.toHaveBeenCalled();
    });

    it('keeps the row clickable and shows the error when the install fails', async () => {
        // Rest rejects with the response wrapped in data.
        post.mockRejectedValue({data: {message: 'Download failed'}, status_code: 423});
        const root = await mountPage();

        await click(rowButton(rows(root)[1]));
        expect(notify.error).toHaveBeenCalledWith('Download failed');
        expect(window.location.reload).not.toHaveBeenCalled();
        expect(rowButton(rows(root)[1]).attrs.disabled).toBeFalsy();
    });

    it('asks for Pro on a Pro-only add-on that is not installed, without Pro', async () => {
        config.pro = false;
        fixtures.list = [proAddon('square', false)];
        const root = await mountPage();

        const row = rows(root)[0];
        expect(text(row)).toContain('Requires Pro');
        const link = row.findAll(node => node.tagName === 'a' && node.attrs.target === '_blank')[0];
        expect(text(link)).toBe('Upgrade to Pro');
        expect(link.attrs.href).toBe('https://fluentcart.com/pricing/');
        expect(byClass(row, 'fct-list-item__actions')[0].findAll(node => node.tagName === 'button')).toHaveLength(0);
    });

    it('asks for Pro on an installed Pro-only add-on instead of activating, without Pro', async () => {
        config.pro = false;
        fixtures.list = [proAddon('airwallex', true)];
        const root = await mountPage();

        const row = rows(root)[0];
        expect(text(row)).toContain('Requires Pro');
        expect(text(row)).not.toContain('Activate');
        expect(row.findAll(node => node.tagName === 'a' && node.attrs.target === '_blank').map(text)).toEqual(['Upgrade to Pro']);
        expect(post).not.toHaveBeenCalled();
    });

    it('keeps Install & Activate for a Pro-only add-on when Pro is active', async () => {
        fixtures.list = [proAddon('square', false)];
        const root = await mountPage();

        expect(text(rowButton(rows(root)[0]))).toBe('Install & Activate');
        expect(text(rows(root)[0])).not.toContain('Requires Pro');
    });
});
