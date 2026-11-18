import {beforeEach, describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick, ref} from 'vue';

/**
 * The edit page for an order bump card: it loads the card into the block editor, saves it,
 * and can put the default back.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    globalThis.window.addEventListener = globalThis.window.addEventListener || (() => {});
    globalThis.window.removeEventListener = globalThis.window.removeEventListener || (() => {});
});

const rest = vi.hoisted(() => ({get: vi.fn(), post: vi.fn(), delete: vi.fn()}));
const common = vi.hoisted(() => ({$confirm: vi.fn()}));
const router = vi.hoisted(() => ({push: vi.fn()}));
const notify = vi.hoisted(() => ({success: vi.fn(), error: vi.fn()}));
// The editor frame in these tests runs the component's real forceAutosave() and handleEditorMessage().
// `answer` is what the iframe does after FORCE_SAVE: null never answers, otherwise it answers later.
const frame = vi.hoisted(() => ({props: null, asked: 0, answer: null}));

vi.mock('@/utils/http/Rest', () => ({default: rest}));
vi.mock('@/utils/Notify', () => ({default: notify}));
vi.mock('@/Bits/common', () => common);
vi.mock('vue-router', () => ({useRouter: () => router}));
vi.mock('@/utils/translator/Translator', () => ({default: text => text}));
vi.mock('@element-plus/icons-vue', () => ({MoreFilled: {render: () => null}}));
vi.mock('@/Bits/Components/Layout/Page.vue', () => ({
    default: {render() { return h('div', [this.$slots.title && this.$slots.title(), this.$slots.actions && this.$slots.actions(), this.$slots.default && this.$slots.default()]); }},
}));
vi.mock('@/Bits/Components/AdminNotice.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Layout/PageHeading.vue', () => ({
    default: {
        render() { return h('div', {class: 'fake-heading'}, [this.$slots.title && this.$slots.title(), this.$slots.action && this.$slots.action()]); },
    },
}));
vi.mock('@/Bits/Components/BlockEditorFrame.vue', async () => {
    const {methods} = (await vi.importActual('@/Bits/Components/BlockEditorFrame.vue')).default;

    return {
        default: {
            props: ['editorParams', 'modelValue'],
            emits: ['update:modelValue', 'contentUpdated'],
            setup(props, {emit, expose}) {
                frame.props = props;
                const real = {
                    pendingSaves: {},
                    forceSaveId: 0,
                    editorOrigin: 'https://shop.test',
                    dataSent: true,
                    editorData: {},
                    _timers: [],
                    _setTimeout(fn, delay) { const id = setTimeout(fn, delay); this._timers.push(id); return id; },
                    $emit: emit,
                };
                real.handleEditorMessage = methods.handleEditorMessage.bind(real);
                real.editorFrame = {
                    contentWindow: {
                        postMessage(message) {
                            frame.asked++;
                            if (frame.answer) {
                                setTimeout(() => real.handleEditorMessage({
                                    origin: 'https://shop.test',
                                    data: {action: 'FORCE_SAVE_DONE', id: message.id, content: frame.answer.content, saved: frame.answer.saved},
                                }), frame.answer.after);
                            }
                        },
                    },
                };
                expose({forceAutosave: methods.forceAutosave.bind(real)});

                return () => h('div', {class: 'fake-editor'});
            },
        },
    };
});

import OrderBumpTemplateEdit from '@/Modules/OrderBump/OrderBumpTemplateEdit.vue';
import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';

const flush = async () => {
    for (let tick = 0; tick < 6; tick++) {
        await Promise.resolve();
        await nextTick();
    }
};

const passThrough = defineComponent({render() { return h('div', this.$slots.default && this.$slots.default()); }});

const mountPage = async (template = ref('compact')) => {
    const root = new FakeNode('div');
    const app = renderer.createApp(defineComponent({setup: () => () => h(OrderBumpTemplateEdit, {template: template.value})}));
    ['el-icon', 'fct-skeleton'].forEach(name => app.component(name, passThrough));
    // The menu items, which the real component shows on click; a disabled item sends no command.
    app.component('fct-dropdown', defineComponent({
        props: ['items'],
        emits: ['command'],
        render() {
            return h('div', [this.$slots.default && this.$slots.default(), ...this.items.map(item => h('button', {
                class: 'menu-item',
                onClick: () => !item.disabled && this.$emit('command', item.command),
            }, item.label))]);
        },
    }));
    app.component('fct-button', defineComponent({props: ['loading', 'disabled'], render() { return h('button', {class: 'fct-button'}, this.$slots.default && this.$slots.default()); }}));
    app.mount(root);
    await flush();
    return root;
};

const menuItem = (root, label) => byClass(root, 'menu-item').find(node => node.textContent.trim() === label);
const saveButton = root => byClass(root, 'fct-button').find(node => node.textContent.trim() === 'Save Template');

describe('Order Bump card edit page', () => {
    beforeEach(() => {
        rest.get.mockReset().mockResolvedValue({template: {name: 'compact', title: 'Compact Card', content: '<!-- wp:group /-->'}});
        rest.post.mockReset().mockResolvedValue({message: 'Saved'});
        rest.delete.mockReset().mockResolvedValue({message: 'Card deleted successfully'});
        common.$confirm.mockReset().mockResolvedValue('confirm');
        router.push.mockReset();
        notify.success.mockReset();
        notify.error.mockReset();
        frame.asked = 0;
        frame.answer = {content: '<!-- wp:group /-->', saved: true, after: 5};
    });

    it('opens the card in the block editor', async () => {
        await mountPage();

        expect(rest.get).toHaveBeenCalledWith('order_bump/templates/compact');
        expect(frame.props.editorParams).toEqual({block_type: 'order_bump_template', campaign_title: 'Compact Card', bid: 'compact'});
        expect(frame.props.modelValue).toBe('<!-- wp:group /-->');
    });

    const later = (ms) => new Promise(resolve => setTimeout(resolve, ms));

    it('posts the latest edit the editor answers with, not the older copy the page holds', async () => {
        frame.answer = {content: '<!-- wp:group {"className":"edited just now"} /-->', saved: true, after: 20};
        const root = await mountPage();

        saveButton(root).listeners.click();
        await flush();
        expect(rest.post).not.toHaveBeenCalled();
        expect(frame.asked).toBe(1);

        await later(60);
        await flush();

        expect(rest.post).toHaveBeenCalledTimes(1);
        expect(rest.post).toHaveBeenCalledWith('order_bump/templates/compact', {content: '<!-- wp:group {"className":"edited just now"} /-->'});
        expect(notify.success).toHaveBeenCalledWith('Saved');
        expect(frame.props.modelValue).toBe('<!-- wp:group {"className":"edited just now"} /-->');
    });

    it('still posts, and reports only the post, when the editor says its own save failed', async () => {
        frame.answer = {content: 'latest markup', saved: false, after: 10};
        const root = await mountPage();

        saveButton(root).listeners.click();
        await later(40);
        await flush();

        expect(rest.post).toHaveBeenCalledWith('order_bump/templates/compact', {content: 'latest markup'});
        expect(notify.success).toHaveBeenCalledWith('Saved');
    });

    it('fails the save and posts nothing when the editor never answers', async () => {
        vi.useFakeTimers();
        try {
            frame.answer = null;
            const root = await mountPage();

            saveButton(root).listeners.click();
            await vi.advanceTimersByTimeAsync(5500);
            await flush();

            expect(rest.post).not.toHaveBeenCalled();
            expect(notify.error).toHaveBeenCalledWith('The editor did not answer. Try saving again.');

            // The page is free again: a second try reaches the editor and posts.
            frame.answer = {content: 'latest markup', saved: true, after: 5};
            saveButton(root).listeners.click();
            await vi.advanceTimersByTimeAsync(10);
            await flush();
            expect(rest.post).toHaveBeenCalledWith('order_bump/templates/compact', {content: 'latest markup'});
        } finally {
            vi.useRealTimers();
        }
    });

    it('does not post a second time for a second click while the first save is waiting', async () => {
        frame.answer = {content: 'latest markup', saved: true, after: 30};
        const root = await mountPage();

        saveButton(root).listeners.click();
        saveButton(root).listeners.click();
        await later(70);
        await flush();

        expect(frame.asked).toBe(1);
        expect(rest.post).toHaveBeenCalledTimes(1);
    });

    it('says why when the save fails', async () => {
        rest.post.mockRejectedValue({data: {message: 'No template data provided'}});
        const root = await mountPage();

        saveButton(root).listeners.click();
        await later(40);
        await flush();

        expect(notify.error).toHaveBeenCalledWith('No template data provided');
    });

    it('puts the default back and loads the card again', async () => {
        const root = await mountPage();
        rest.post.mockResolvedValue({message: 'Reverted'});

        menuItem(root, 'Revert to Default').listeners.click();
        await flush();

        expect(rest.post).toHaveBeenCalledWith('order_bump/templates/compact/reset');
        expect(notify.success).toHaveBeenCalledWith('Reverted');
        expect(rest.get).toHaveBeenCalledTimes(2);
    });

    it('does not revert while a save waits for the editor', async () => {
        frame.answer = {content: 'latest markup', saved: true, after: 30};
        const root = await mountPage();

        saveButton(root).listeners.click();
        await flush();
        menuItem(root, 'Revert to Default').listeners.click();
        await flush();

        expect(rest.post).not.toHaveBeenCalledWith('order_bump/templates/compact/reset');
        await later(40);
        await flush();
    });

    it('does not save while a revert is pending', async () => {
        const root = await mountPage();
        let finish;
        rest.post.mockReset().mockImplementation(() => new Promise((resolve) => { finish = resolve; }));

        menuItem(root, 'Revert to Default').listeners.click();
        await flush();
        saveButton(root).listeners.click();
        await flush();

        expect(rest.post).toHaveBeenCalledTimes(1);
        expect(frame.asked).toBe(0);
        finish({message: 'Reverted'});
        await flush();
    });

    it('loads the other card when the address switches to it', async () => {
        const template = ref('compact');
        await mountPage(template);

        template.value = 'classic';
        await flush();

        expect(rest.get).toHaveBeenLastCalledWith('order_bump/templates/classic');
    });

    it('does not keep the old card content while the other card loads, and does not save it', async () => {
        const template = ref('compact');
        const root = await mountPage(template);
        let finish;
        rest.get.mockReturnValue(new Promise((resolve) => { finish = resolve; }));

        template.value = 'classic';
        await flush();

        expect(byClass(root, 'fake-editor')).toHaveLength(0);
        saveButton(root).listeners.click();
        await flush();
        expect(rest.post).not.toHaveBeenCalled();

        finish({template: {name: 'classic', title: 'Classic Card', content: '<!-- wp:group {"className":"classic"} /-->'}});
        await flush();

        expect(frame.props.modelValue).toBe('<!-- wp:group {"className":"classic"} /-->');
    });

    it('lets the newest answer win when the card is switched twice quickly', async () => {
        const template = ref('compact');
        await mountPage(template);
        const resolvers = {};
        rest.get.mockImplementation((url) => new Promise((resolve) => { resolvers[url.split('/').pop()] = resolve; }));

        template.value = 'classic';
        await flush();
        template.value = 'compact';
        await flush();
        resolvers.compact({template: {name: 'compact', title: 'Compact Card', content: 'compact markup'}});
        await flush();
        resolvers.classic({template: {name: 'classic', title: 'Classic Card', content: 'classic markup'}});
        await flush();

        expect(frame.props.modelValue).toBe('compact markup');
    });

    it('says why when the card cannot be loaded', async () => {
        rest.get.mockRejectedValue({data: {message: 'Template not found'}});
        await mountPage();

        expect(notify.error).toHaveBeenCalledWith('Template not found');
        expect(byClass(await mountPage(), 'fake-editor')).toHaveLength(0);
    });

    it('posts nothing when the card failed to load, so a read error cannot wipe the saved card', async () => {
        rest.get.mockRejectedValue({data: {message: 'Template not found'}});
        const root = await mountPage();

        saveButton(root).listeners.click();
        await flush();

        expect(rest.post).not.toHaveBeenCalled();
    });

    describe('Delete Template', () => {
        const asCustom = () => rest.get.mockResolvedValue({template: {name: 'custom_1_2', title: 'Black Friday', is_custom: true, content: '<!-- wp:group /-->'}});
        const menuLabels = root => byClass(root, 'menu-item').map(node => node.textContent.trim());

        it('is offered for a custom card only, right under Revert to Default', async () => {
            expect(menuLabels(await mountPage())).toEqual(['Revert to Default']);

            asCustom();
            expect(menuLabels(await mountPage(ref('custom_1_2')))).toEqual(['Revert to Default', 'Delete Template']);
        });

        it('asks first, deletes the card and goes back to the Design page', async () => {
            asCustom();
            const root = await mountPage(ref('custom_1_2'));

            menuItem(root, 'Delete Template').listeners.click();
            await flush();

            expect(common.$confirm).toHaveBeenCalledTimes(1);
            expect(rest.delete).toHaveBeenCalledWith('order_bump/templates/custom_1_2');
            expect(notify.success).toHaveBeenCalledWith('Card deleted successfully');
            expect(router.push).toHaveBeenCalledWith({name: 'order_bump_design'});
        });

        it('changes nothing when the delete is not confirmed', async () => {
            asCustom();
            common.$confirm.mockReset().mockRejectedValue('cancel');
            const root = await mountPage(ref('custom_1_2'));

            menuItem(root, 'Delete Template').listeners.click();
            await flush();

            expect(rest.delete).not.toHaveBeenCalled();
            expect(router.push).not.toHaveBeenCalled();
        });

        it('says why and stays on the page when the server refuses', async () => {
            asCustom();
            rest.delete.mockRejectedValue({data: {message: 'Template not found'}});
            const root = await mountPage(ref('custom_1_2'));

            menuItem(root, 'Delete Template').listeners.click();
            await flush();

            expect(notify.error).toHaveBeenCalledWith('Template not found');
            expect(router.push).not.toHaveBeenCalled();
        });

        it('sends one request for two clicks', async () => {
            asCustom();
            let finish;
            rest.delete.mockReturnValue(new Promise((resolve) => { finish = resolve; }));
            const root = await mountPage(ref('custom_1_2'));

            menuItem(root, 'Delete Template').listeners.click();
            await flush();
            menuItem(root, 'Delete Template').listeners.click();
            await flush();
            expect(rest.delete).toHaveBeenCalledTimes(1);

            finish({message: 'Card deleted successfully'});
            await flush();
        });
    });
});
