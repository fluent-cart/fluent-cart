import {beforeEach, describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick} from 'vue';

/**
 * Order Bumps → Design: the built-in cards show as previews, like the PDF template list.
 * One card is active everywhere; picking another saves at once.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    globalThis.window.addEventListener = globalThis.window.addEventListener || (() => {});
    globalThis.window.removeEventListener = globalThis.window.removeEventListener || (() => {});
});

const rest = vi.hoisted(() => ({get: vi.fn(), post: vi.fn(), delete: vi.fn()}));
const notify = vi.hoisted(() => ({success: vi.fn(), error: vi.fn()}));
const common = vi.hoisted(() => ({$confirm: vi.fn()}));

vi.mock('@/utils/http/Rest', () => ({default: rest}));
vi.mock('@/utils/Notify', () => ({default: notify}));
vi.mock('@/Bits/common', () => common);
vi.mock('@/utils/translator/Translator', () => ({default: text => text}));
vi.mock('@/utils/support/Asset', () => ({default: {productPlaceholder: () => 'placeholder.webp'}}));
vi.mock('@/Bits/Components/Layout/Page.vue', () => ({default: {props: ['title', 'breadcrumbs'], render() { return h('div', {class: 'fake-page'}, [this.$slots.actions && h('div', {class: 'fake-actions'}, this.$slots.actions()), this.$slots.default && this.$slots.default()]); }}}));
vi.mock('@/Modules/OrderBump/parts/AddOrderBumpTemplate.vue', () => ({default: {render: () => h('button', {class: 'fake-add-template'}, 'Add Template')}}));
vi.mock('@/Bits/Components/Layout/Section.vue', () => ({default: {props: ['title', 'description'], render() { return h('div', [h('div', {class: 'fake-heading'}, this.title), this.$slots.default && this.$slots.default()]); }}}));
vi.mock('@/Bits/Components/AdminNotice.vue', () => ({default: {render: () => null}}));

import OrderBumpDesign from '@/Modules/OrderBump/OrderBumpDesign.vue';
import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';

const flush = async () => {
    for (let tick = 0; tick < 5; tick++) {
        await Promise.resolve();
        await nextTick();
    }
};

const mountPage = async () => {
    const root = new FakeNode('div');
    const app = renderer.createApp(defineComponent({setup: () => () => h(OrderBumpDesign)}));
    app.component('fct-skeleton', defineComponent({
        props: ['variant'],
        render() { return this.variant ? h('span', {class: 'fake-skeleton-item'}) : h('div', {class: 'fake-skeleton'}, this.$slots.default && this.$slots.default()); }
    }));
    app.component('fct-button', defineComponent({
        props: ['disabled', 'loading', 'to'],
        render() { return h('button', {class: 'fct-button', 'data-loading': String(!!this.loading), 'data-to': JSON.stringify(this.to || null)}, this.$slots.default && this.$slots.default()); }
    }));
    app.mount(root);
    await flush();
    return root;
};

const cardFor = (root, title) => byClass(root, 'fct-order-bump-template-card')
    .find(card => byClass(card, 'fct-order-bump-template-title')[0].textContent === title);
const buttonsIn = (card, label) => byClass(card, 'fct-button').filter(button => button.textContent.trim() === label);

describe('Order Bump design page', () => {
    beforeEach(() => {
        rest.get.mockReset().mockResolvedValue({settings: {template: 'classic'}, templates: ['classic', 'compact', 'highlight', 'exclusive', 'callout', 'toggle']});
        rest.post.mockReset().mockResolvedValue({message: 'Saved', settings: {template: 'compact'}});
        rest.delete.mockReset().mockResolvedValue({message: 'Card deleted successfully'});
        notify.success.mockReset();
        notify.error.mockReset();
    });

    it('shows a skeleton of every card while the active card is loading, then the cards', async () => {
        let finish;
        rest.get.mockReturnValue(new Promise((resolve) => { finish = resolve; }));
        const root = await mountPage();

        expect(byClass(root, 'fake-skeleton')).toHaveLength(1);
        expect(byClass(root, 'fct-order-bump-template-card')).toHaveLength(6);
        expect(byClass(root, 'fct-order-bump-template-title')).toHaveLength(0);

        finish({settings: {template: 'classic'}, templates: ['classic', 'compact', 'highlight', 'exclusive', 'callout', 'toggle']});
        await flush();

        expect(byClass(root, 'fake-skeleton')).toHaveLength(0);
        expect(byClass(root, 'fct-order-bump-template-title')).toHaveLength(6);
        expect(byClass(root, 'fct-order-bump-template-active')).toHaveLength(1);
    });

    it('shows the cards when the active card cannot be loaded', async () => {
        rest.get.mockRejectedValue({data: {message: 'Failed'}});
        const root = await mountPage();

        expect(byClass(root, 'fake-skeleton')).toHaveLength(0);
        expect(byClass(root, 'fct-order-bump-template-title')).toHaveLength(6);
        expect(notify.error).toHaveBeenCalledWith('Failed');
    });

    it('has a page heading above the cards, besides the breadcrumb', async () => {
        const root = await mountPage();

        expect(byClass(root, 'fake-heading').map(node => node.textContent)).toEqual(['Manage']);
    });

    it('shows the six built-in cards, each marked Built-in and described', async () => {
        const root = await mountPage();
        const cards = byClass(root, 'fct-order-bump-template-card');

        expect(cards.map(card => byClass(card, 'fct-order-bump-template-title')[0].textContent))
            .toEqual(['Classic Card', 'Compact Card', 'Highlight Box', 'Exclusive Card', 'Callout Card', 'Toggle Card']);
        cards.forEach(card => {
            expect(byClass(card, 'fct-order-bump-template-badge')[0].textContent).toBe('Built-in');
            expect(byClass(card, 'fct-order-bump-template-used')[0].textContent.length).toBeGreaterThan(10);
        });
    });

    it('previews each card with its own design and the same sample offer', async () => {
        const root = await mountPage();

        expect(byClass(root, 'fct_order_promotion_title')).toHaveLength(1);
        expect(byClass(root, 'fct-placement-preview-drawer__bump')).toHaveLength(1);
        expect(byClass(root, 'fct_order_promotion_title')[0].textContent).toBe('Zipper Hoodie deal');
        expect(byClass(root, 'fct_cart_bump_badge')[0].textContent).toBe('Zipper Hoodie deal');
        ['fct-pp-highlight', 'fct-pp-excl', 'fct-bump-callout-preview', 'fct-pv-toggle'].forEach((root_class) => {
            expect(byClass(root, root_class)).toHaveLength(1);
        });
    });

    it('marks the saved card In Use and offers Use this layout on the other', async () => {
        rest.get.mockResolvedValue({settings: {template: 'compact'}, templates: ['classic', 'compact', 'highlight', 'exclusive', 'callout', 'toggle']});
        const root = await mountPage();

        expect(rest.get).toHaveBeenCalledWith('order_bump/settings');
        expect(byClass(cardFor(root, 'Compact Card'), 'fct-order-bump-template-active')[0].textContent.trim()).toBe('In Use');
        expect(buttonsIn(cardFor(root, 'Compact Card'), 'Use this layout')).toHaveLength(0);
        expect(byClass(cardFor(root, 'Classic Card'), 'fct-order-bump-template-active')).toHaveLength(0);
        expect(buttonsIn(cardFor(root, 'Classic Card'), 'Use this layout')).toHaveLength(1);
    });

    it('shows loading on the picked card while it saves, then moves the badge to it', async () => {
        let finish;
        rest.post.mockReturnValue(new Promise((resolve) => { finish = resolve; }));
        const root = await mountPage();

        buttonsIn(cardFor(root, 'Compact Card'), 'Use this layout')[0].listeners.click();
        await flush();

        expect(buttonsIn(cardFor(root, 'Compact Card'), 'Use this layout')[0].attrs['data-loading']).toBe('true');
        expect(byClass(cardFor(root, 'Classic Card'), 'fct-order-bump-template-active')).toHaveLength(1);

        finish({message: 'Saved', settings: {template: 'compact'}});
        await flush();

        expect(rest.post).toHaveBeenCalledWith('order_bump/settings', {template: 'compact'});
        expect(notify.success).toHaveBeenCalledWith('Saved');
        expect(byClass(cardFor(root, 'Compact Card'), 'fct-order-bump-template-active')).toHaveLength(1);
        expect(byClass(cardFor(root, 'Classic Card'), 'fct-order-bump-template-active')).toHaveLength(0);
    });

    it('keeps the old card and says why when the server refuses', async () => {
        rest.post.mockRejectedValue({data: {message: 'Please choose a valid card.'}});
        const root = await mountPage();

        buttonsIn(cardFor(root, 'Compact Card'), 'Use this layout')[0].listeners.click();
        await flush();

        expect(notify.error).toHaveBeenCalledWith('Please choose a valid card.');
        expect(byClass(cardFor(root, 'Classic Card'), 'fct-order-bump-template-active')).toHaveLength(1);
    });

    it('links the Customize button of each card to the editor for that card, with no hover overlay', async () => {
        const root = await mountPage();

        const titles = {classic: 'Classic Card', compact: 'Compact Card', highlight: 'Highlight Box', exclusive: 'Exclusive Card', callout: 'Callout Card', toggle: 'Toggle Card'};
        expect(byClass(root, 'fct-order-bump-template-overlay')).toHaveLength(0);
        expect(byClass(root, 'fct-button').filter(button => button.textContent.trim() === 'Edit Template')).toHaveLength(0);
        Object.keys(titles).forEach((key) => {
            const edit = buttonsIn(cardFor(root, titles[key]), 'Customize');
            expect(edit).toHaveLength(1);
            expect(JSON.parse(edit[0].attrs['data-to'])).toEqual({name: 'order_bump_template_edit', params: {template: key}});
        });
    });

    describe('customized cards', () => {
        const withCustomized = (keys) => rest.get.mockResolvedValue({
            settings: {template: 'classic'},
            templates: ['classic', 'compact', 'highlight', 'exclusive', 'callout', 'toggle'],
            customized: keys,
        });
        const badgeOf = (root, title) => byClass(cardFor(root, title), 'fct-order-bump-template-badge')[0].textContent;

        it('marks only an edited card Customized, and offers Reset on it alone', async () => {
            withCustomized(['compact']);
            const root = await mountPage();

            expect(badgeOf(root, 'Compact Card')).toBe('Customized');
            expect(badgeOf(root, 'Classic Card')).toBe('Built-in');
            expect(buttonsIn(cardFor(root, 'Compact Card'), 'Customize')).toHaveLength(1);
            expect(buttonsIn(cardFor(root, 'Compact Card'), 'Reset')).toHaveLength(1);
            expect(buttonsIn(cardFor(root, 'Classic Card'), 'Customize')).toHaveLength(1);
            expect(buttonsIn(cardFor(root, 'Classic Card'), 'Reset')).toHaveLength(0);
        });

        it('sends Customize on an edited card to its editor', async () => {
            withCustomized(['compact']);
            const root = await mountPage();
            const customize = buttonsIn(cardFor(root, 'Compact Card'), 'Customize')[0];

            expect(JSON.parse(customize.attrs['data-to'])).toEqual({name: 'order_bump_template_edit', params: {template: 'compact'}});
        });

        it('asks first, then resets the card and takes the Customized mark off', async () => {
            withCustomized(['compact']);
            common.$confirm.mockReset().mockResolvedValue('confirm');
            rest.post.mockResolvedValue({message: 'Reverted'});
            const root = await mountPage();

            buttonsIn(cardFor(root, 'Compact Card'), 'Reset')[0].listeners.click();
            await flush();

            expect(common.$confirm).toHaveBeenCalledTimes(1);
            expect(rest.post).toHaveBeenCalledWith('order_bump/templates/compact/reset');
            expect(notify.success).toHaveBeenCalledWith('Reverted');
            expect(badgeOf(root, 'Compact Card')).toBe('Built-in');
            expect(buttonsIn(cardFor(root, 'Compact Card'), 'Reset')).toHaveLength(0);
        });

        it('changes nothing when the reset is not confirmed', async () => {
            withCustomized(['compact']);
            common.$confirm.mockReset().mockRejectedValue('cancel');
            const root = await mountPage();

            buttonsIn(cardFor(root, 'Compact Card'), 'Reset')[0].listeners.click();
            await flush();

            expect(rest.post).not.toHaveBeenCalled();
            expect(badgeOf(root, 'Compact Card')).toBe('Customized');
        });

        it('keeps the mark and says why when the reset fails', async () => {
            withCustomized(['compact']);
            common.$confirm.mockReset().mockResolvedValue('confirm');
            rest.post.mockRejectedValue({data: {message: 'Template not found'}});
            const root = await mountPage();

            buttonsIn(cardFor(root, 'Compact Card'), 'Reset')[0].listeners.click();
            await flush();

            expect(notify.error).toHaveBeenCalledWith('Template not found');
            expect(badgeOf(root, 'Compact Card')).toBe('Customized');
        });
    });

    describe('custom cards', () => {
        const withCustom = (active = 'classic') => rest.get.mockResolvedValue({
            settings: {template: active},
            templates: ['classic', 'compact', 'highlight', 'exclusive', 'callout', 'toggle', 'custom_1_2'],
            custom: [{key: 'custom_1_2', title: 'Black Friday'}],
            customized: [],
        });
        const badgeOf = (root, title) => byClass(cardFor(root, title), 'fct-order-bump-template-badge')[0].textContent;

        it('has the Add Template button in the page actions', async () => {
            const root = await mountPage();

            expect(byClass(byClass(root, 'fake-actions')[0], 'fake-add-template')).toHaveLength(1);
        });

        it('lists a custom card after the built-in ones, marked Custom, with Customize and Delete but no Reset', async () => {
            withCustom();
            const root = await mountPage();

            expect(byClass(root, 'fct-order-bump-template-card')).toHaveLength(7);
            expect(badgeOf(root, 'Black Friday')).toBe('Custom');
            const card = cardFor(root, 'Black Friday');
            expect(buttonsIn(card, 'Customize')).toHaveLength(1);
            expect(JSON.parse(buttonsIn(card, 'Customize')[0].attrs['data-to'])).toEqual({name: 'order_bump_template_edit', params: {template: 'custom_1_2'}});
            expect(buttonsIn(card, 'Delete')).toHaveLength(1);
            expect(buttonsIn(card, 'Reset')).toHaveLength(0);
            expect(buttonsIn(cardFor(root, 'Classic Card'), 'Delete')).toHaveLength(0);
            expect(byClass(card, 'fct-order-bump-template-used')).toHaveLength(0);
            expect(byClass(cardFor(root, 'Classic Card'), 'fct-order-bump-template-used')).toHaveLength(1);
        });

        it('can be chosen like any other card', async () => {
            withCustom();
            rest.post.mockResolvedValue({message: 'Saved', settings: {template: 'custom_1_2'}});
            const root = await mountPage();

            buttonsIn(cardFor(root, 'Black Friday'), 'Use this layout')[0].listeners.click();
            await flush();

            expect(rest.post).toHaveBeenCalledWith('order_bump/settings', {template: 'custom_1_2'});
            expect(byClass(cardFor(root, 'Black Friday'), 'fct-order-bump-template-active')).toHaveLength(1);
        });

        it('asks first, then deletes the card and removes it from the page', async () => {
            withCustom();
            common.$confirm.mockReset().mockResolvedValue('confirm');
            const root = await mountPage();

            buttonsIn(cardFor(root, 'Black Friday'), 'Delete')[0].listeners.click();
            await flush();

            expect(common.$confirm).toHaveBeenCalledTimes(1);
            expect(rest.delete).toHaveBeenCalledWith('order_bump/templates/custom_1_2');
            expect(notify.success).toHaveBeenCalledWith('Card deleted successfully');
            expect(cardFor(root, 'Black Friday')).toBeUndefined();
            expect(byClass(root, 'fct-order-bump-template-card')).toHaveLength(6);
        });

        it('moves In Use to the Classic card when the card in use is deleted', async () => {
            withCustom('custom_1_2');
            common.$confirm.mockReset().mockResolvedValue('confirm');
            const root = await mountPage();
            expect(byClass(cardFor(root, 'Black Friday'), 'fct-order-bump-template-active')).toHaveLength(1);

            buttonsIn(cardFor(root, 'Black Friday'), 'Delete')[0].listeners.click();
            await flush();

            expect(byClass(cardFor(root, 'Classic Card'), 'fct-order-bump-template-active')).toHaveLength(1);
        });

        it('keeps the card when the delete is not confirmed or the server refuses', async () => {
            withCustom();
            common.$confirm.mockReset().mockRejectedValue('cancel');
            const root = await mountPage();

            buttonsIn(cardFor(root, 'Black Friday'), 'Delete')[0].listeners.click();
            await flush();
            expect(rest.delete).not.toHaveBeenCalled();

            common.$confirm.mockReset().mockResolvedValue('confirm');
            rest.delete.mockRejectedValue({data: {message: 'Template not found'}});
            buttonsIn(cardFor(root, 'Black Friday'), 'Delete')[0].listeners.click();
            await flush();

            expect(notify.error).toHaveBeenCalledWith('Template not found');
            expect(cardFor(root, 'Black Friday')).toBeDefined();
        });

        it('does not let a card be chosen while it is being deleted, nor deleted while a card is being chosen', async () => {
            withCustom();
            common.$confirm.mockReset().mockResolvedValue('confirm');
            let finishDelete;
            rest.delete.mockReturnValue(new Promise((resolve) => { finishDelete = resolve; }));
            const root = await mountPage();

            buttonsIn(cardFor(root, 'Black Friday'), 'Delete')[0].listeners.click();
            await flush();
            buttonsIn(cardFor(root, 'Black Friday'), 'Use this layout')[0].listeners.click();
            await flush();
            expect(rest.post).not.toHaveBeenCalled();

            finishDelete({message: 'Card deleted successfully'});
            await flush();

            // And the other way round: a delete waits while a choice is saving.
            withCustom();
            let finishSave;
            rest.post.mockReturnValue(new Promise((resolve) => { finishSave = resolve; }));
            rest.delete.mockReset();
            const again = await mountPage();
            buttonsIn(cardFor(again, 'Compact Card'), 'Use this layout')[0].listeners.click();
            await flush();
            buttonsIn(cardFor(again, 'Black Friday'), 'Delete')[0].listeners.click();
            await flush();
            expect(common.$confirm).toHaveBeenCalledTimes(1);
            expect(rest.delete).not.toHaveBeenCalled();

            finishSave({message: 'Saved', settings: {template: 'compact'}});
            await flush();
        });

        it('offers Reset on an edited custom card, which puts back the Classic design', async () => {
            rest.get.mockResolvedValue({
                settings: {template: 'classic'},
                templates: ['classic', 'custom_1_2'],
                custom: [{key: 'custom_1_2', title: 'Black Friday'}],
                customized: ['custom_1_2'],
            });
            common.$confirm.mockReset().mockResolvedValue('confirm');
            rest.post.mockResolvedValue({message: 'Reverted'});
            const root = await mountPage();

            buttonsIn(cardFor(root, 'Black Friday'), 'Reset')[0].listeners.click();
            await flush();

            expect(common.$confirm.mock.calls[0][0]).toContain('to the Classic design');
            expect(rest.post).toHaveBeenCalledWith('order_bump/templates/custom_1_2/reset');
            expect(buttonsIn(cardFor(root, 'Black Friday'), 'Reset')).toHaveLength(0);
        });
    });
});
