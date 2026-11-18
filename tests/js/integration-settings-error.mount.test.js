import {describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick} from 'vue';

/**
 * Global integration settings: a rejected request (Rest rejects with {data, status_code})
 * must end the loading state and show the server's message, not spin forever.
 */

vi.mock('@/mixin/saveButtonShortcutMixin', () => ({useSaveShortcut: () => ({onSave: () => {}})}));
vi.mock('@element-plus/icons-vue', () => ({InfoFilled: {}, Setting: {}}));

import GeneralIntegrationSettings from '@/Modules/Integrations/GeneralIntegrationSettings.vue';
import {FakeNode, renderer} from './support/fake-dom-renderer.js';

const flush = async () => {
    for (let tick = 0; tick < 4; tick++) {
        await Promise.resolve();
        await nextTick();
    }
};

const mount = async (globals) => {
    let vm = null;
    const errors = [];
    const app = renderer.createApp(defineComponent({
        setup: () => () => h(GeneralIntegrationSettings, {settings_key: 'missing', ref: instance => { vm = instance && instance.$.proxy; }}),
    }));
    app.config.warnHandler = () => {};
    app.config.errorHandler = error => errors.push(error);
    app.directive('loading', {});
    Object.assign(app.config.globalProperties, {$t: text => text, handleError: () => {}, handleSuccess: () => {}}, globals);
    const root = new FakeNode('div');
    app.mount(root);
    await flush();
    return {vm, errors, app, root};
};

const rejection = {data: {message: 'Sorry! No integration found with: missing'}, status_code: 422};

describe('global integration settings', () => {
    it('a 422 on load stops the spinner and shows the message', async () => {
        const {vm, app, root} = await mount({$get: () => Promise.reject(rejection)});

        expect(vm.loading).toBe(false);
        expect(vm.error_message).toBe('Sorry! No integration found with: missing');
        // No empty form with a blank Save button under the message.
        expect(root.findAll(node => String(node.className).includes('fct-integration-setting-body'))).toHaveLength(0);
        app.unmount();
    });

    it('a 422 on save or authenticate leaves no spinner behind', async () => {
        const handleError = vi.fn();
        const {vm, app} = await mount({
            $get: () => new Promise(() => {}),
            $post: () => Promise.reject(rejection),
            handleError,
        });

        vm.save();
        await flush();
        expect(vm.saving).toBe(false);

        vm.authenticate('/authenticate', {});
        await flush();
        expect(vm.loading).toBe(false);
        expect(handleError).toHaveBeenCalledWith(rejection.data.message);
        app.unmount();
    });
});
