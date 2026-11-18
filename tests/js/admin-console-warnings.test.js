import {afterEach, describe, expect, it, vi} from 'vitest';
import fs from 'node:fs';
import {createApp, defineComponent, h, nextTick, reactive} from 'vue';
import {createMemoryHistory, createRouter} from 'vue-router';

/**
 * Console warnings FluentCart itself caused in the admin and the customer portal.
 * Each case reproduces the condition that warned.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    globalThis.window.fluent_cart_admin = {hooks: {addFilter: (hook, name, callback) => { globalThis.__fctRoutesFilter = callback; }}};
});

vi.mock('@/utils/translator/Translator', () => ({default: text => text}));
vi.mock('@/Modules/Attributes/AttributesView.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/Attributes/AttrGroups.vue', () => ({default: {render: () => null}}));

const read = file => fs.readFileSync(new URL('../../' + file, import.meta.url), 'utf8');

afterEach(() => vi.restoreAllMocks());

describe('admin console warnings', () => {
    it('a legacy /attributes/:id link lands on the list without a discarded-param warning', async () => {
        await import('../../resources/attributes/attributes.js');
        const routes = Object.values(globalThis.__fctRoutesFilter({}));
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        const router = createRouter({history: createMemoryHistory(), routes});

        await router.push('/attributes/1');

        expect(router.currentRoute.value.name).toBe('attributes');
        expect(warn.mock.calls.flat().join(' ')).not.toContain('Discarded invalid param');
    });

    it('the remote select declares no reserved "key" prop', async () => {
        const {default: RemoteSearchableSelect} = await import('@/Bits/Components/Form/Components/Base/Inputs/RemoteSearchableSelect.vue');

        expect(Object.keys(RemoteSearchableSelect.props)).not.toContain('key');
    });

    it('the feed editor starts in its loading state, so the header never renders with empty settings', () => {
        const source = read('resources/admin/Modules/Integrations/FeedEditor.vue');
        const start = source.indexOf('data() {');
        const data = source.slice(start, source.indexOf('loading_list', start));

        expect(data).toContain('loading_app: true');
    });

    it('a variant price keeps its collapse model an array when it turns into a subscription', async () => {
        const source = read('resources/admin/Modules/Products/parts/VariantPrice.vue');

        // VariantItemCollapse's modelValue is an Array prop.
        expect(source).not.toMatch(/activeCollapse\.value = '/);
    });

    it('storage driver routes each have a component', () => {
        const source = read('resources/admin/routes.js');
        const loop = source.slice(source.indexOf('for (const route of genericDriverRoutes)'), source.indexOf('export var routes'));

        expect(loop).toContain('route.component =');
    });

    it('the portal address dialog is described by its hidden help text', () => {
        const source = read('resources/public/customer-profile/Vue/UserProfile/templates/AddressModal.vue');

        // A bound name that is not defined left the dialog with no description.
        expect(source).toContain('aria-describedby="addressFormDescId"');
        expect(source).not.toContain(':aria-describedby="addressFormDescId"');
        expect(source).toContain('id="addressFormDescId"');
    });

    it('the customer portal registers Element Plus once', async () => {
        const {useElementPlusComponents} = await import('../../resources/public/customer-profile/mixin/useElementPlusComponents.js');
        const app = {component: vi.fn(), config: {globalProperties: {}}};

        useElementPlusComponents(app);

        // app.use(ElementPlus) in Start.js registers every component and v-loading.
        expect(app.component).not.toHaveBeenCalled();
        expect(read('resources/public/customer-profile/Start.js')).not.toContain("app.directive('loading'");
    });
});
