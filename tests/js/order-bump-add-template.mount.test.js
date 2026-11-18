import {beforeEach, describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick} from 'vue';

/**
 * Order Bumps → Design → Add Template: a modal asks for a name, makes the card, then opens its editor.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    globalThis.window.addEventListener = globalThis.window.addEventListener || (() => {});
    globalThis.window.removeEventListener = globalThis.window.removeEventListener || (() => {});
});

const rest = vi.hoisted(() => ({post: vi.fn()}));
const notify = vi.hoisted(() => ({success: vi.fn(), error: vi.fn()}));
const router = vi.hoisted(() => ({push: vi.fn()}));

vi.mock('@/utils/http/Rest', () => ({default: rest}));
vi.mock('@/utils/Notify', () => ({default: notify}));
vi.mock('@/utils/translator/Translator', () => ({default: text => text}));
vi.mock('vue-router', () => ({useRouter: () => router}));

import AddOrderBumpTemplate from '@/Modules/OrderBump/parts/AddOrderBumpTemplate.vue';
import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';

const flush = async () => {
    for (let tick = 0; tick < 5; tick++) {
        await Promise.resolve();
        await nextTick();
    }
};

const mountDialog = async () => {
    const root = new FakeNode('div');
    const app = renderer.createApp(defineComponent({setup: () => () => h(AddOrderBumpTemplate)}));
    app.component('fct-button', defineComponent({
        props: ['loading', 'disabled', 'type'],
        render() { return h('button', {class: 'fct-button', 'data-loading': String(!!this.loading), 'data-disabled': String(!!this.disabled)}, this.$slots.default && this.$slots.default()); }
    }));
    app.component('fct-dialog', defineComponent({
        props: ['modelValue', 'title', 'closeOnPressEscape', 'closeOnClickModal', 'showClose'],
        render() {
            return this.modelValue
                ? h('div', {class: 'fake-dialog', 'data-title': this.title, 'data-escape': String(this.closeOnPressEscape), 'data-overlay': String(this.closeOnClickModal), 'data-show-close': String(this.showClose)}, [this.$slots.default && this.$slots.default(), this.$slots.footer && this.$slots.footer()])
                : null;
        }
    }));
    app.component('fct-input', defineComponent({
        props: ['modelValue', 'label', 'placeholder', 'maxlength'],
        emits: ['update:modelValue'],
        render() { return h('input', {class: 'fake-input', 'data-label': this.label, 'data-maxlength': String(this.maxlength), value: this.modelValue, onInput: event => this.$emit('update:modelValue', event.target.value)}); }
    }));
    app.mount(root);
    await flush();
    return root;
};

const button = (root, label) => byClass(root, 'fct-button').find(node => node.textContent.trim() === label);
const type = async (root, value) => { byClass(root, 'fake-input')[0].listeners.input({target: {value}}); await flush(); };

describe('Add Template on the Design page', () => {
    beforeEach(() => {
        rest.post.mockReset().mockResolvedValue({message: 'Card created successfully', key: 'custom_1_2'});
        notify.success.mockReset();
        notify.error.mockReset();
        router.push.mockReset();
    });

    it('opens a modal that asks for the template name', async () => {
        const root = await mountDialog();

        expect(byClass(root, 'fake-dialog')).toHaveLength(0);
        button(root, 'Add Template').listeners.click();
        await flush();

        expect(byClass(root, 'fake-dialog')).toHaveLength(1);
        expect(byClass(root, 'fake-dialog')[0].attrs['data-title']).toBe('Add Custom Template');
        expect(byClass(root, 'fake-input')[0].attrs['data-label']).toBe('Template Name');
    });

    it('creates the card with the name and opens its editor', async () => {
        const root = await mountDialog();
        button(root, 'Add Template').listeners.click();
        await flush();
        await type(root, 'Black Friday');

        button(root, 'Create').listeners.click();
        await flush();

        expect(rest.post).toHaveBeenCalledWith('order_bump/templates', {title: 'Black Friday'});
        expect(notify.success).toHaveBeenCalledWith('Card created successfully');
        expect(router.push).toHaveBeenCalledWith({name: 'order_bump_template_edit', params: {template: 'custom_1_2'}});
    });

    it('asks for a name first and sends nothing when the name is empty', async () => {
        const root = await mountDialog();
        button(root, 'Add Template').listeners.click();
        await flush();
        await type(root, '   ');

        button(root, 'Create').listeners.click();
        await flush();

        expect(rest.post).not.toHaveBeenCalled();
        expect(notify.error).toHaveBeenCalledWith('Please enter a name for the card.');
        expect(router.push).not.toHaveBeenCalled();
    });

    it('says why and stays in the modal when the server refuses', async () => {
        rest.post.mockRejectedValue({data: {message: 'You can add up to 20 cards. Delete one to add another.'}});
        const root = await mountDialog();
        button(root, 'Add Template').listeners.click();
        await flush();
        await type(root, 'One more');

        button(root, 'Create').listeners.click();
        await flush();

        expect(notify.error).toHaveBeenCalledWith('You can add up to 20 cards. Delete one to add another.');
        expect(router.push).not.toHaveBeenCalled();
        expect(byClass(root, 'fake-dialog')).toHaveLength(1);
        expect(button(root, 'Create').attrs['data-loading']).toBe('false');
    });

    it('does not send a second request while the first is still running', async () => {
        let finish;
        rest.post.mockReturnValue(new Promise((resolve) => { finish = resolve; }));
        const root = await mountDialog();
        button(root, 'Add Template').listeners.click();
        await flush();
        await type(root, 'Once');

        button(root, 'Create').listeners.click();
        button(root, 'Create').listeners.click();
        await flush();
        expect(rest.post).toHaveBeenCalledTimes(1);

        finish({message: 'Card created successfully', key: 'custom_3_4'});
        await flush();
    });

    it('cannot be closed while the card is being made, so the editor always opens for it', async () => {
        let finish;
        rest.post.mockReturnValue(new Promise((resolve) => { finish = resolve; }));
        const root = await mountDialog();
        button(root, 'Add Template').listeners.click();
        await flush();
        expect(byClass(root, 'fake-dialog')[0].attrs['data-escape']).toBe('true');
        await type(root, 'Slow');

        button(root, 'Create').listeners.click();
        await flush();

        const dialog = byClass(root, 'fake-dialog')[0];
        expect(button(root, 'Cancel').attrs['data-disabled']).toBe('true');
        expect(dialog.attrs['data-escape']).toBe('false');
        expect(dialog.attrs['data-overlay']).toBe('false');
        expect(dialog.attrs['data-show-close']).toBe('false');

        finish({message: 'Card created successfully', key: 'custom_5_6'});
        await flush();
        expect(router.push).toHaveBeenCalledTimes(1);
    });

    it('keeps the name within the 100 characters the server keeps', async () => {
        const root = await mountDialog();
        button(root, 'Add Template').listeners.click();
        await flush();

        expect(byClass(root, 'fake-input')[0].attrs['data-maxlength']).toBe('100');
    });
});
