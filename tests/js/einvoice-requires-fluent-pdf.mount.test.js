import {beforeEach, describe, expect, it, vi} from 'vitest';
import {h, nextTick} from 'vue';
import ElementPlus, {ID_INJECTION_KEY} from 'element-plus';

/**
 * E-Invoice Settings: with e-invoices switched on and Fluent PDF not active, the page says Fluent PDF is required and
 * offers to install or activate it in place.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    globalThis.window.addEventListener = globalThis.window.addEventListener || (() => {});
    globalThis.window.removeEventListener = globalThis.window.removeEventListener || (() => {});
    globalThis.MouseEvent = globalThis.MouseEvent || class MouseEvent {};
});

const rest = vi.hoisted(() => ({get: vi.fn(), post: vi.fn()}));

vi.mock('vue-router', () => ({useRoute: () => ({}), useRouter: () => ({push: () => {}})}));
vi.mock('@/utils/http/Rest', () => ({default: rest}));
vi.mock('@/utils/Notify', () => ({default: {success: vi.fn(), error: vi.fn()}}));
vi.mock('@/utils/translator/Translator', () => ({default: (text) => text}));
vi.mock('@/mixin/saveButtonShortcutMixin', () => ({useSaveShortcut: () => ({onSave: () => {}})}));
vi.mock('@/Modules/Settings/Parts/SettingsHeader.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/AdminNotice.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {render: () => null}}));

import PdfTemplateSettings from '@/Modules/Settings/PdfTemplate/PdfTemplateSettings.vue';
import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';
import {registerFctComponents} from '@/Bits/Components/Fct/index.js';

const response = (enabled, addonInfo) => ({
    seller_details: {zugferd_enabled: enabled},
    store_country_set: true,
    has_fluent_pdf: !addonInfo,
    addon_info: addonInfo,
});

const missingPdf = (installed) => ({plugin_slug: 'fluentforms-pdf', plugin_file: 'fluentforms-pdf/fluentforms-pdf.php', source_type: 'wordpress', is_installed: installed, is_active: false});

const flush = async () => {
    for (let tick = 0; tick < 6; tick++) {
        await Promise.resolve();
        await nextTick();
    }
};

const mountPage = async (data) => {
    rest.get.mockResolvedValue(data);
    const root = new FakeNode('div');
    const app = renderer.createApp({render: () => h(PdfTemplateSettings)}).use(ElementPlus).use(registerFctComponents).provide(ID_INJECTION_KEY, {prefix: 1, current: 0});
    // Selects teleport their popper, which the fake DOM cannot host; they do not matter here.
    app._context.components.FctSelect = {render: () => null};
    app.mount(root);
    await flush();
    return root;
};

const text = (node) => node.textContent.replace(/\s+/g, ' ').trim();
const notice = (root) => byClass(root, 'fct-alert-warning')[0];

describe('E-Invoice settings need Fluent PDF', () => {
    beforeEach(() => {
        rest.post.mockReset();
        rest.post.mockResolvedValue({});
        window.location = {reload: vi.fn()};
    });

    it('warns only while e-invoices are on and Fluent PDF is not active', async () => {
        expect(notice(await mountPage(response('1', missingPdf(true))))).toBeDefined();
        expect(notice(await mountPage(response('0', missingPdf(true))))).toBeUndefined();
        expect(notice(await mountPage(response('1', null)))).toBeUndefined();
    });

    it('activates an installed Fluent PDF in place and keeps the unsaved toggle', async () => {
        // Saved off; the admin switches it on without saving, sees the warning and activates.
        const root = await mountPage(response('0', missingPdf(true)));
        const toggle = byClass(root, 'el-switch')[0];
        toggle.listeners.click({stopPropagation: () => {}, preventDefault: () => {}});
        await flush();

        const button = notice(root).findAll(node => node.tagName === 'button')[0];
        expect(text(button)).toBe('Activate');
        button.listeners.click({stopPropagation: () => {}, preventDefault: () => {}});
        await flush();

        expect(rest.post).toHaveBeenCalledWith('settings/modules/plugin-addons/activate', {plugin_file: 'fluentforms-pdf/fluentforms-pdf.php'});
        expect(window.location.reload).not.toHaveBeenCalled();
        expect(notice(root)).toBeUndefined();
        expect(byClass(root, 'el-switch')[0].className).toContain('is-checked');
    });

    it('installs Fluent PDF from WordPress.org when it is missing', async () => {
        const button = notice(await mountPage(response('1', missingPdf(false)))).findAll(node => node.tagName === 'button')[0];
        expect(text(button)).toBe('Install & Activate');

        button.listeners.click({stopPropagation: () => {}, preventDefault: () => {}});
        await flush();

        expect(rest.post).toHaveBeenCalledWith('settings/modules/plugin-addons/install', {plugin_slug: 'fluentforms-pdf', source_type: 'wordpress'});
    });
});
