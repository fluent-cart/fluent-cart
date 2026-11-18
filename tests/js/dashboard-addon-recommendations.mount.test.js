import {beforeEach, describe, expect, it, vi} from 'vitest';
import {h, nextTick} from 'vue';
import ElementPlus, {ID_INJECTION_KEY} from 'element-plus';

/**
 * Dashboard "Recommended for your store": the button installs or activates the add-on in place and the admin then
 * reloads on the add-on's redirect_url. The card collapses and remembers it.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    globalThis.window.addEventListener = globalThis.window.addEventListener || (() => {});
    globalThis.window.removeEventListener = globalThis.window.removeEventListener || (() => {});
    globalThis.MouseEvent = globalThis.MouseEvent || class MouseEvent {};
});

const rest = vi.hoisted(() => ({post: vi.fn()}));
const config = vi.hoisted(() => ({pro: true}));
const storage = vi.hoisted(() => ({rows: new Map()}));

vi.mock('vue-router', () => ({RouterLink: {props: ['to'], setup: (props, {slots}) => () => h('a', {href: '#' + props.to.path}, slots.default())}, useRoute: () => ({})}));
vi.mock('@/utils/http/Rest', () => ({default: rest}));
vi.mock('@/utils/Notify', () => ({default: {success: vi.fn(), error: vi.fn()}}));
vi.mock('@/utils/Clipboard', () => ({default: {copy: vi.fn()}}));
vi.mock('@/utils/translator/Translator', () => ({default: (text) => text}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {get: (key, fallback) => (key === 'app_config.isProActive' ? config.pro : (fallback ?? '')), onShopUpdate: () => {}}}));
vi.mock('@/Bits/Components/Badge.vue', () => ({default: {props: ['text'], setup: (props) => () => h('span', {class: 'badge'}, props.text)}}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Animation.vue', () => ({
    default: {props: ['visible'], setup: (props, {slots}) => () => (props.visible ? slots.default() : null)},
}));

import AddonRecommendations from '@/Pages/Dashboard/Components/AddonRecommendations.vue';
import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';

const items = () => [
    {key: 'fluent-cart-customer-rights', title: 'Customer Rights', logo: 'a.svg', badge: 'Required in Germany', reason: 'Germany.', is_installed: true,
        plugin_file: 'fluent-cart-customer-rights/fluent-cart-customer-rights.php', redirect_url: '/settings/withdrawal-settings'},
    {key: 'shipping-for-fluent-cart', title: 'Shipping', logo: 'b.svg', badge: '', reason: 'Physical.', is_installed: false,
        plugin_slug: 'shipping-for-fluent-cart', source_type: 'cdn', source_link: 'https://addons-cdn.fluentcart.com/shipping.zip', repo_link: 'https://fluentcart.com/addons'},
];

const flush = async () => {
    for (let tick = 0; tick < 6; tick++) {
        await Promise.resolve();
        await nextTick();
    }
};

const mountCard = async () => {
    const root = new FakeNode('div');
    renderer.createApp({render: () => h(AddonRecommendations, {items: items()})}).use(ElementPlus).provide(ID_INJECTION_KEY, {prefix: 1, current: 0}).mount(root);
    await flush();
    return root;
};

const text = (node) => node.textContent.replace(/\s+/g, ' ').trim();
const buttons = (root) => byClass(root, 'fct-addon-reco__action');
const click = async (node) => { node.listeners.click({stopPropagation: () => {}, preventDefault: () => {}}); await flush(); };

describe('Dashboard add-on recommendations card', () => {
    beforeEach(() => {
        config.pro = true;
        storage.rows.clear();
        rest.post.mockReset();
        rest.post.mockResolvedValue({action: 'reload'});
        window.localStorage = {
            getItem: (key) => (storage.rows.has(key) ? storage.rows.get(key) : null),
            setItem: (key, value) => storage.rows.set(key, String(value)),
            removeItem: (key) => storage.rows.delete(key),
        };
        window.location = {href: 'https://shop.test/wp-admin/admin.php?page=fluent-cart#/', reload: vi.fn(), assign: vi.fn()};
        window.history = {replaceState: vi.fn()};
        window.open = vi.fn();
    });

    it('labels the button by add-on state, and Download without Pro', async () => {
        expect(buttons(await mountCard()).map(text)).toEqual(['Activate Customer Rights', 'Install & Activate Shipping']);

        config.pro = false;
        expect(text(buttons(await mountCard())[1])).toBe('Download Shipping');
    });

    it('activates in place, then reloads on the redirect_url', async () => {
        vi.useFakeTimers();
        const root = await mountCard();

        await click(buttons(root)[0]);
        vi.runAllTimers();
        vi.useRealTimers();

        expect(rest.post).toHaveBeenCalledWith('settings/modules/plugin-addons/activate', {plugin_file: 'fluent-cart-customer-rights/fluent-cart-customer-rights.php'});
        expect(window.history.replaceState).toHaveBeenCalledWith(null, '', '#/settings/withdrawal-settings');
        expect(window.location.reload).toHaveBeenCalled();
    });

    it('installs with Pro and opens the download page without it', async () => {
        const root = await mountCard();
        await click(buttons(root)[1]);
        expect(rest.post).toHaveBeenCalledWith('settings/modules/plugin-addons/install', {
            plugin_slug: 'shipping-for-fluent-cart', source_type: 'cdn', source_link: 'https://addons-cdn.fluentcart.com/shipping.zip', asset_path: '',
        });

        rest.post.mockClear();
        config.pro = false;
        await click(buttons(await mountCard())[1]);
        expect(window.open).toHaveBeenCalledWith('https://fluentcart.com/addons', '_blank', 'noopener,noreferrer');
        expect(rest.post).not.toHaveBeenCalled();
    });

    it('collapses, remembers it, and opens again', async () => {
        const root = await mountCard();
        const toggle = byClass(root, 'fct-addon-reco__toggle')[0];

        await click(toggle);
        expect(toggle.attrs['aria-expanded']).toBe('false');
        expect(buttons(root)).toHaveLength(0);
        expect(storage.rows.get('fct_addon_recommendations_collapsed')).toBe('yes');

        const again = await mountCard();
        expect(buttons(again)).toHaveLength(0);

        await click(byClass(again, 'fct-addon-reco__toggle')[0]);
        expect(buttons(again)).toHaveLength(2);
        expect(storage.rows.has('fct_addon_recommendations_collapsed')).toBe(false);
    });
});
