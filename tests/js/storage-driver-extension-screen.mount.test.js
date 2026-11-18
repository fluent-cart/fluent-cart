import {afterEach, describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick} from 'vue';

/**
 * Storage settings: a plugin's Vue screen registered as storage_<driver>_settings
 * (fluent_cart_admin_components, module API 3) replaces core's form for that driver.
 */

const response = vi.hoisted(() => ({
    settings: {is_active: 'no'},
    fields: {},
    template_payload: {driver: 'r2', driver_label: 'Cloudflare R2'},
}));

vi.mock('vue-router', () => ({useRoute: () => ({name: 'r2'})}));
vi.mock('@/utils/http/Rest', () => ({default: {get: () => Promise.resolve(response), post: vi.fn()}}));
vi.mock('@/utils/Notify', () => ({default: {error: () => {}, success: () => {}}}));
vi.mock('@/utils/translator/Translator', () => ({default: text => text}));
vi.mock('@/mixin/saveButtonShortcutMixin', () => ({useSaveShortcut: () => ({onSave: () => {}})}));
vi.mock('@/utils/model/form/FormModel', () => ({
    useFormModel: () => {
        const form = {values: {}, setSchema: () => form, setDefaults: () => form, initForm: () => form};
        return form;
    },
}));
vi.mock('@/Bits/Components/Form/VueForm.vue', () => ({default: {name: 'VueForm', render: () => h('div', {class: 'core-form'})}}));
vi.mock('@/Bits/Components/DynamicTemplates/VueTemplateLoader.vue', () => ({default: {render: () => h('div', {class: 'runtime-template'})}}));
vi.mock('@/Bits/Components/AdminNotice.vue', () => ({default: {render: () => null}}));
vi.mock('@element-plus/icons-vue', () => ({ArrowRight: {}}));

import GlobalStorageComponents from '@/Modules/Settings/GlobalStorageComponents.vue';
import {FakeNode, renderer} from './support/fake-dom-renderer.js';

const flush = async () => {
    for (let tick = 0; tick < 4; tick++) {
        await Promise.resolve();
        await nextTick();
    }
};

const mount = async (components) => {
    globalThis.window = globalThis.window || {};
    globalThis.window.fluent_cart_admin = {hooks: {applyFilters: (hook, value) => ({...value, ...components})}};
    const root = new FakeNode('div');
    const app = renderer.createApp(defineComponent({setup: () => () => h(GlobalStorageComponents)}));
    app.config.warnHandler = () => {};
    app.mount(root);
    await flush();
    return root;
};

const byClass = (root, name) => root.findAll(node => String(node.className).split(/\s+/).includes(name));

afterEach(() => {
    delete globalThis.window.fluent_cart_admin;
});

describe('storage settings screen', () => {
    it('shows the registered storage_<driver>_settings component with the driver payload', async () => {
        let received = null;
        const R2Screen = defineComponent({
            props: {payload: Object, data: Object},
            setup: props => () => {
                received = props;
                return h('div', {class: 'r2-screen'});
            },
        });

        const root = await mount({storage_r2_settings: R2Screen});

        expect(byClass(root, 'r2-screen')).toHaveLength(1);
        expect(byClass(root, 'core-form')).toHaveLength(0);
        expect(received.payload.driver_label).toBe('Cloudflare R2');
        expect(received.data.route_name).toBe('r2');
        // The plugin's screen saves itself.
        expect(byClass(root, 'fct-setting-header-action')).toHaveLength(0);
    });

    it('keeps core\'s form when no plugin registered a screen', async () => {
        const root = await mount({});

        expect(byClass(root, 'core-form')).toHaveLength(1);
        expect(byClass(root, 'fct-setting-header-action')).toHaveLength(1);
    });
});
