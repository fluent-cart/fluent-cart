import {beforeEach, describe, expect, it, vi} from 'vitest';
import {h, nextTick} from 'vue';
import ElementPlus, {ID_INJECTION_KEY} from 'element-plus';

/**
 * Product edit, under Long Description: with a page builder active and its FluentCart add-on not, a super admin sees
 * a notice that activates or installs the add-on in place, or hides for good in this browser.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    globalThis.window.addEventListener = globalThis.window.addEventListener || (() => {});
    globalThis.window.removeEventListener = globalThis.window.removeEventListener || (() => {});
    globalThis.MouseEvent = globalThis.MouseEvent || class MouseEvent {};
});

const rest = vi.hoisted(() => ({post: vi.fn()}));
const config = vi.hoisted(() => ({addons: [], pro: true, admin: true}));
const storage = vi.hoisted(() => ({rows: new Map()}));

vi.mock('vue-router', () => ({useRoute: () => ({}), RouterLink: {}}));
vi.mock('@/utils/http/Rest', () => ({default: rest}));
vi.mock('@/utils/Notify', () => ({default: {success: vi.fn(), error: vi.fn()}}));
vi.mock('@/utils/Clipboard', () => ({default: {copy: vi.fn()}}));
vi.mock('@/utils/translator/Translator', () => ({
    default: (text, ...args) => args.reduce((out, arg, index) => out.split('%' + (index + 1) + '$s').join(arg), text),
}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {
    get: (key, fallback) => ({builder_addons: config.addons, 'app_config.isProActive': config.pro})[key] ?? (fallback ?? ''),
    onShopUpdate: () => {},
}}));
vi.mock('@/Bits/Components/Permission/UserCan.vue', () => ({default: {setup: (props, {slots}) => () => (config.admin ? slots.default() : null)}}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {render: () => null}}));

const bricks = () => ({
    key: 'fluent-cart-bricks-blocks', builder: 'Bricks', title: 'Bricks Blocks', is_installed: true,
    plugin_slug: 'fluent-cart-bricks-blocks', plugin_file: 'fluent-cart-bricks-blocks/fluent-cart-bricks-blocks.php',
});

const elementor = (installed) => ({
    key: 'elementor-block', builder: 'Elementor', title: 'Elementor Blocks', is_installed: installed,
    plugin_slug: 'fluent-cart-elementor-blocks', plugin_file: 'fluent-cart-elementor-blocks/fluent-cart-elementor-blocks.php',
    source_type: 'cdn', source_link: 'https://addons-cdn.fluentcart.com/fluent-cart-elementor-blocks.zip',
});

const flush = async () => {
    for (let tick = 0; tick < 6; tick++) {
        await Promise.resolve();
        await nextTick();
    }
};

// The component reads AppConfig at setup, so each test imports a fresh copy.
const mountNotice = async () => {
    vi.resetModules();
    const {default: BuilderAddonNotice} = await import('@/Modules/Products/parts/BuilderAddonNotice.vue');
    const {FakeNode, renderer} = await import('./support/fake-dom-renderer.js');
    const {registerFctComponents} = await import('@/Bits/Components/Fct/index.js');
    const root = new FakeNode('div');
    renderer.createApp({render: () => h(BuilderAddonNotice)}).use(ElementPlus).use(registerFctComponents).provide(ID_INJECTION_KEY, {prefix: 1, current: 0}).mount(root);
    await flush();
    return root;
};

const text = (node) => node.textContent.replace(/\s+/g, ' ').trim();
const buttons = (root) => root.findAll(node => node.tagName === 'button');
const click = async (node) => { node.listeners.click({stopPropagation: () => {}, preventDefault: () => {}}); await flush(); };

describe('Product edit builder add-on notice', () => {
    beforeEach(() => {
        config.addons = [elementor(true)];
        config.pro = true;
        config.admin = true;
        storage.rows.clear();
        rest.post.mockReset();
        rest.post.mockResolvedValue({action: 'reload'});
        window.localStorage = {
            getItem: (key) => (storage.rows.has(key) ? storage.rows.get(key) : null),
            setItem: (key, value) => storage.rows.set(key, String(value)),
            removeItem: (key) => storage.rows.delete(key),
        };
        window.location = {href: 'https://shop.test/wp-admin/admin.php?page=fluent-cart#/products/1', reload: vi.fn(), assign: vi.fn()};
        window.history = {replaceState: vi.fn()};
        window.open = vi.fn();
    });

    it('names the builder and the add-on, with Activate for an installed add-on', async () => {
        const root = await mountNotice();

        expect(text(root)).toContain('Design product pages in Elementor');
        expect(text(root)).toContain('The Elementor Blocks add-on brings FluentCart to Elementor.');
        expect(text(buttons(root)[0])).toBe('Activate');
        expect(buttons(root)[1].attrs['aria-label']).toBe("Don't show again");
    });

    it('activates in place, then reloads the product page', async () => {
        vi.useFakeTimers();
        const root = await mountNotice();

        await click(buttons(root)[0]);
        vi.runAllTimers();
        vi.useRealTimers();

        expect(rest.post).toHaveBeenCalledWith('settings/modules/plugin-addons/activate', {plugin_file: 'fluent-cart-elementor-blocks/fluent-cart-elementor-blocks.php'});
        expect(window.location.reload).toHaveBeenCalled();
        expect(window.history.replaceState).not.toHaveBeenCalled();
    });

    it('offers Install & Activate with Pro and Download without it', async () => {
        config.addons = [elementor(false)];
        expect(text(buttons(await mountNotice())[0])).toBe('Install & Activate');

        config.pro = false;
        expect(text(buttons(await mountNotice())[0])).toBe('Download');
    });

    it('hides for good in this browser', async () => {
        const root = await mountNotice();

        await click(buttons(root)[1]);
        expect(text(root)).toBe('');
        expect(storage.rows.get('fct_builder_notice_hidden_elementor-block')).toBe('yes');
        expect(text(await mountNotice())).toBe('');
    });

    it('shows nothing without a builder gap or to users who cannot install plugins', async () => {
        config.addons = [];
        expect(text(await mountNotice())).toBe('');

        config.addons = [elementor(true)];
        config.admin = false;
        expect(text(await mountNotice())).toBe('');
    });

    it('moves on to the next builder after one is closed, also after a reload', async () => {
        config.addons = [elementor(true), bricks()];
        const root = await mountNotice();
        expect(text(root)).toContain('Design product pages in Elementor');

        await click(buttons(root)[1]);
        expect(text(root)).toContain('Design product pages in Bricks');
        expect(text(await mountNotice())).toContain('Design product pages in Bricks');

        await click(buttons(await mountNotice())[1]);
        expect(text(await mountNotice())).toBe('');
    });
});
