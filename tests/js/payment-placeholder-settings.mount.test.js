import {beforeEach, describe, expect, it, vi} from 'vitest';
import {h, nextTick} from 'vue';
import ElementPlus, {ID_INJECTION_KEY} from 'element-plus';

/**
 * A payment method's settings page while its add-on (or Pro) is missing:
 * - no activation toggle and no Save, because there is nothing to configure yet;
 * - a failed install or activation shows the server's message, not a generic one.
 */

const listeners = vi.hoisted(() => ({click: null}));

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    globalThis.window.addEventListener = globalThis.window.addEventListener || (() => {});
    globalThis.window.removeEventListener = globalThis.window.removeEventListener || (() => {});
    globalThis.MouseEvent = globalThis.MouseEvent || class MouseEvent {};
});

const notify = vi.hoisted(() => ({success: vi.fn(), error: vi.fn()}));
const api = vi.hoisted(() => ({get: vi.fn(), post: vi.fn()}));

vi.mock('vue-router', () => ({useRoute: () => ({}), useRouter: () => ({push: () => {}})}));
vi.mock('@/utils/Notify', () => ({default: notify}));
vi.mock('@/utils/translator/Translator', () => ({default: (text) => text}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {get: (key, fallback) => (key === 'app_config.isProActive' ? true : (fallback ?? '')), onShopUpdate: () => {}}}));
vi.mock('@/mixin/saveButtonShortcutMixin', () => ({useSaveShortcut: () => ({onSave: () => {}})}));
vi.mock('@/Bits/common', () => ({handleSuccess: vi.fn(), handleError: vi.fn()}));
vi.mock('@/utils/support/Asset', () => ({default: {getUrl: () => ''}}));
vi.mock('@/Modules/Settings/Parts/_connect_account.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/Settings/PaymentComponet/Tabs.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Form/Components/Base/Inputs/ColorPicker.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Inputs/MediaInput.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/Settings/PaymentComponet/RadioSelectDependants.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Card/ContentCard.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/Settings/PaymentComponet/PayPalWebhookSetup.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Inputs/WpEditor.vue', () => ({default: {render: () => null}}));

import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';
import {registerFctComponents} from '@/Bits/Components/Fct/index.js';

const flush = async () => {
    for (let tick = 0; tick < 6; tick++) {
        await Promise.resolve();
        await nextTick();
    }
};

const mount = async (component, props = {}, route = {name: 'flutterwave', meta: {title: 'Flutterwave'}}) => {
    const root = new FakeNode('div');
    const app = renderer.createApp({render: () => h(component, props)}).use(ElementPlus).use(registerFctComponents).provide(ID_INJECTION_KEY, {prefix: 1, current: 0});
    app.mixin({methods: {$get: (...args) => api.get(...args), $post: (...args) => api.post(...args), $t: (text) => text}});
    app.config.globalProperties.$route = route;
    app.config.globalProperties.$router = {push: () => {}};
    app._context.components.ElBreadcrumb = {setup: (p, {slots}) => () => slots.default?.()};
    app._context.components.ElBreadcrumbItem = {setup: (p, {slots}) => () => slots.default?.()};
    app.mount(root);
    await flush();
    return root;
};

// Renderer listens for clicks on document; set a stub only around its mounts (Element Plus checks for document on load).
const withDocument = async (run) => {
    globalThis.document = {
        addEventListener: (type, handler) => { if (type === 'click') listeners.click = handler; },
        removeEventListener: () => {},
        querySelector: () => null,
        querySelectorAll: () => [],
        getElementById: () => null,
        createElement: () => ({style: {}, setAttribute: () => {}, appendChild: () => {}, classList: {add: () => {}, remove: () => {}}}),
        body: {appendChild: () => {}, removeChild: () => {}, classList: {add: () => {}, remove: () => {}}},
        documentElement: {classList: {contains: () => false}},
    };
    try {
        await run();
    } finally {
        delete globalThis.document;
    }
};

// A click on a server-rendered add-on button inside the settings HTML.
const addonButtonClick = (className, attrs) => ({
    target: {classList: {contains: (name) => name === className}, closest: () => null, getAttribute: (name) => attrs[name]},
    preventDefault: () => {},
    stopPropagation: () => {},
});

describe('Payment method settings page while the add-on is missing', () => {
    beforeEach(() => {
        notify.error.mockReset();
        api.get.mockReset();
        api.get.mockResolvedValue({});
        api.post.mockReset();
    });

    it('hides the activation toggle, Save and the design pencil for a placeholder', async () => {
        const {default: GlobalPaymentComponents} = await import('@/Modules/Settings/GlobalPaymentComponents.vue');
        api.get.mockImplementation((path) => Promise.resolve(path === 'settings/payment-methods/all'
            ? {gateways: []}
            : {fields: [], settings: {is_active: 'no'}, is_placeholder: true}));

        let root;
        await withDocument(async () => { root = await mount(GlobalPaymentComponents); });

        expect(byClass(root, 'setting-switcher')).toHaveLength(0);
        expect(byClass(root, 'setting-save-action')).toHaveLength(0);
        // The pencil opens the checkout design page, which would save the placeholder too.
        expect(byClass(root, 'cursor-pointer')).toHaveLength(0);
    });

    it('keeps the toggle and Save for a real payment method', async () => {
        const {default: GlobalPaymentComponents} = await import('@/Modules/Settings/GlobalPaymentComponents.vue');
        api.get.mockImplementation((path) => Promise.resolve(path === 'settings/payment-methods/all'
            ? {gateways: []}
            : {fields: [], settings: {is_active: 'yes'}}));

        let root;
        await withDocument(async () => { root = await mount(GlobalPaymentComponents, {}, {name: 'stripe', meta: {title: 'Card'}}); });

        expect(byClass(root, 'setting-switcher')).toHaveLength(1);
        expect(byClass(root, 'setting-save-action')).toHaveLength(1);
        expect(byClass(root, 'cursor-pointer')).toHaveLength(1);
    });

    it("shows the server's message when the install fails", async () => {
        const {default: Renderer} = await import('@/Modules/Settings/PaymentComponet/Renderer.vue');
        // Rest rejects with the response wrapped in data.
        api.post.mockRejectedValue({data: {message: 'Could not create directory.'}, status_code: 423});
        await withDocument(async () => {
            await mount(Renderer, {fields: [], settings: {}, routeName: 'flutterwave'});
            listeners.click(addonButtonClick('fct-install-addon-btn', {
                'data-addon-slug': 'flutterwave-for-fluent-cart', 'data-addon-file': 'flutterwave/flutterwave.php',
                'data-repo-link': 'https://fluentcart.com/addons',
            }));
            await flush();
        });

        expect(notify.error).toHaveBeenCalledWith({message: 'Could not create directory.'});
    });

    it("shows the server's message when the activation fails", async () => {
        const {default: Renderer} = await import('@/Modules/Settings/PaymentComponet/Renderer.vue');
        api.post.mockRejectedValue({data: {message: 'Plugin file does not exist.'}, status_code: 423});
        await withDocument(async () => {
            await mount(Renderer, {fields: [], settings: {}, routeName: 'flutterwave'});
            listeners.click(addonButtonClick('fct-activate-addon-btn', {'data-addon-file': 'flutterwave/flutterwave.php'}));
            await flush();
        });

        expect(notify.error).toHaveBeenCalledWith({message: 'Plugin file does not exist.'});
    });
});
