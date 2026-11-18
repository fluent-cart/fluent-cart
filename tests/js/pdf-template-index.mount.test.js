import {beforeEach, describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick} from 'vue';

/**
 * Settings → PDF Templates: a built-in template an admin edited is marked Customized. Every card has a Customize
 * button, and an edited built-in card also has a Reset button that puts the factory design back.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    globalThis.window.addEventListener = globalThis.window.addEventListener || (() => {});
    globalThis.window.removeEventListener = globalThis.window.removeEventListener || (() => {});
});

const rest = vi.hoisted(() => ({get: vi.fn(), post: vi.fn(), delete: vi.fn()}));
const common = vi.hoisted(() => ({$confirm: vi.fn()}));
const notify = vi.hoisted(() => ({success: vi.fn(), error: vi.fn()}));

vi.mock('@/utils/http/Rest', () => ({default: rest}));
vi.mock('@/utils/Notify', () => ({default: notify}));
vi.mock('@/Bits/common', () => common);
vi.mock('vue-router', () => ({useRouter: () => ({push: vi.fn()})}));
vi.mock('@/utils/translator/Translator', () => ({default: (text, ...args) => args.reduce((out, arg) => out.replace('%s', arg), text)}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {get: () => false}}));
vi.mock('@/Bits/Components/AdminNotice.vue', () => ({default: {render: () => null}}));
vi.mock('../Parts/SettingsPage.vue', () => ({default: {render() { return h('div', this.$slots.default && this.$slots.default()); }}}));
vi.mock('@/Modules/Settings/Parts/SettingsPage.vue', () => ({default: {render() { return h('div', this.$slots.default && this.$slots.default()); }}}));
vi.mock('@/Bits/Components/Layout/Section.vue', () => ({
    default: {
        props: ['title', 'description', 'divided'],
        render() {
            return h('div', {class: 'section', 'data-divided': String(!!this.divided)}, [
                this.title && h('h3', {class: 'section-title'}, this.title),
                this.description && h('p', {class: 'section-description'}, this.description),
                this.$slots.default && this.$slots.default(),
            ]);
        },
    },
}));

import PdfTemplateIndex from '@/Modules/Settings/PdfTemplate/PdfTemplateIndex.vue';
import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';

const flush = async () => {
    for (let tick = 0; tick < 6; tick++) {
        await Promise.resolve();
        await nextTick();
    }
};

const factory = {order_receipt: {pdf_settings: [{pdf_structure: {content: 'FACTORY', meta: {}}}]}};

const templates = (customized, customEdited = false) => ({
    order_receipt: {title: 'Order Receipt', is_default: true, is_customized: customized},
    renewal_receipt: {title: 'Renewal Receipt', is_default: true, is_customized: false},
    custom_1: {title: 'custom', is_default: false, is_customized: customEdited},
});

const passThrough = defineComponent({render() { return h('div', this.$slots.default && this.$slots.default()); }});

const mountPage = async () => {
    const root = new FakeNode('div');
    const app = renderer.createApp(defineComponent({setup: () => () => h(PdfTemplateIndex)}));
    ['fct-skeleton', 'fct-dialog', 'fct-input'].forEach(name => app.component(name, passThrough));
    // The popover shows its reference (the trash button); the confirmation itself is not needed here.
    app.component('fct-popover', defineComponent({render() { return h('div', this.$slots.reference && this.$slots.reference()); }}));
    // Shows its content only while enabled; the tests read both.
    app.component('fct-tooltip', defineComponent({
        props: ['content', 'disabled'],
        render() { return h('span', {class: 'fake-tooltip', 'data-content': this.content, 'data-disabled': String(!!this.disabled)}, this.$slots.default && this.$slots.default()); },
    }));
    app.component('fct-button', defineComponent({
        props: ['to', 'loading', 'disabled'],
        emits: ['click'],
        render() {
            return h('button', {
                class: ['fct-button', this.$attrs.class].filter(Boolean).join(' '),
                'aria-label': this.$attrs['aria-label'],
                'data-loading': String(!!this.loading),
                'data-to': this.to ? this.to.params.template : null,
                onClick: () => !this.disabled && this.$emit('click'),
            }, this.$slots.default && this.$slots.default());
        },
    }));
    app.mount(root);
    await flush();

    return root;
};

const card = (root, title) => byClass(root, 'fct-pdf-template-card').find(node => byClass(node, 'fct-pdf-template-title')[0].textContent.trim() === title);
const button = (node, label) => byClass(node, 'fct-button').find(b => b.textContent.trim() === label);
const badge = node => byClass(node, 'fct-pdf-badge')[0];

describe('PDF templates page', () => {
    beforeEach(() => {
        rest.get.mockReset().mockImplementation(url => Promise.resolve(
            url === 'settings/pdf-templates/factory-default'
                ? {templates: factory}
                : {templates: templates(true), has_fluent_pdf: true}
        ));
        rest.post.mockReset().mockResolvedValue({});
        common.$confirm.mockReset().mockResolvedValue('confirm');
        notify.success.mockReset();
        notify.error.mockReset();
    });

    it('shows a loading card for each built-in template in the same grid while the list loads', async () => {
        rest.get.mockImplementation(() => new Promise(() => {}));
        const root = await mountPage();

        const loading = byClass(root, 'fct-pdf-template-card--loading');
        expect(loading).toHaveLength(4);
        expect(byClass(root, 'fct-pdf-template-grid')).toHaveLength(1);
        expect(byClass(root, 'fct-pdf-template-card')).toHaveLength(4); // only the loading cards, no real ones yet
        // The loading preview takes its height from the same stylesheet as the real preview, so the two stay equal.
        expect(byClass(root, 'fct-pdf-template-skeleton')).toHaveLength(4);
    });

    it('puts the title and the description in the card header, with no divider, while the list loads and after', async () => {
        const expectHeader = (root) => {
            expect(byClass(root, 'section-title').map(node => node.textContent.trim())).toEqual(['Manage']);
            expect(byClass(root, 'section-description').map(node => node.textContent.trim())).toEqual(['Settings_Customize_Pdf']);
            expect(byClass(root, 'section')[0].attrs['data-divided']).toBe('false');
            expect(byClass(root, 'fct-pdf-template-desc')).toHaveLength(0); // the header carries the text now
        };

        rest.get.mockImplementation(() => new Promise(() => {}));
        expectHeader(await mountPage());

        rest.get.mockImplementation(url => Promise.resolve(
            url === 'settings/pdf-templates/factory-default' ? {templates: factory} : {templates: templates(true), has_fluent_pdf: true}
        ));
        expectHeader(await mountPage());
    });

    it('marks an edited built-in template as Customized and leaves the others Built-in', async () => {
        const root = await mountPage();

        expect(badge(card(root, 'Order Receipt')).textContent.trim()).toBe('Customized');
        expect(String(badge(card(root, 'Order Receipt')).className)).toContain('fct-pdf-badge--customized');
        expect(badge(card(root, 'Renewal Receipt')).textContent.trim()).toBe('Built-in');
        expect(badge(card(root, 'custom'))).toBeUndefined();
    });

    it('gives every card a Customize button that opens its editor', async () => {
        const root = await mountPage();

        expect(button(card(root, 'Order Receipt'), 'Customize').attrs['data-to']).toBe('order_receipt');
        expect(button(card(root, 'Renewal Receipt'), 'Customize').attrs['data-to']).toBe('renewal_receipt');
        expect(button(card(root, 'custom'), 'Customize').attrs['data-to']).toBe('custom_1');
    });

    // Cut-off is read from the node's scroll and client widths, which the fake DOM does not have, so they come from here.
    const widths = {cutOff: ['Order Receipt']};
    const defineWidths = () => {
        const isTitle = node => String(node.className).split(/\s+/).includes('fct-pdf-template-title');
        Object.defineProperty(FakeNode.prototype, 'scrollWidth', {configurable: true, get() { return isTitle(this) && widths.cutOff.includes(this.textContent.trim()) ? 300 : 80; }});
        Object.defineProperty(FakeNode.prototype, 'clientWidth', {configurable: true, get() { return 120; }});
    };
    const clearWidths = () => {
        delete FakeNode.prototype.scrollWidth;
        delete FakeNode.prototype.clientWidth;
    };

    it('turns the title tooltip on before any hover, for a title that is cut off only', async () => {
        defineWidths();
        try {
            const root = await mountPage();
            const tooltipOf = title => byClass(card(root, title), 'fake-tooltip')[0];

            expect(tooltipOf('Order Receipt').attrs['data-content']).toBe('Order Receipt');
            expect(tooltipOf('Order Receipt').attrs['data-disabled']).toBe('false'); // no mouseenter has happened
            expect(tooltipOf('Renewal Receipt').attrs['data-disabled']).toBe('true');
        } finally {
            clearWidths();
        }
    });

    it('measures the titles again when the window is resized', async () => {
        const handlers = {};
        const original = [window.addEventListener, window.removeEventListener];
        window.addEventListener = (type, fn) => { handlers[type] = fn; };
        window.removeEventListener = () => {};
        defineWidths();
        try {
            widths.cutOff = ['Order Receipt'];
            const root = await mountPage();
            const tooltipOf = title => byClass(card(root, title), 'fake-tooltip')[0];
            expect(tooltipOf('Order Receipt').attrs['data-disabled']).toBe('false');

            widths.cutOff = ['Renewal Receipt']; // a wider window: the first title fits now, the second is cut off
            handlers.resize();
            await flush();

            expect(tooltipOf('Order Receipt').attrs['data-disabled']).toBe('true');
            expect(tooltipOf('Renewal Receipt').attrs['data-disabled']).toBe('false');
        } finally {
            clearWidths();
            widths.cutOff = ['Order Receipt'];
            [window.addEventListener, window.removeEventListener] = original;
        }
    });

    it('has no hover overlay or Edit Template button, because Customize does that', async () => {
        const root = await mountPage();

        expect(byClass(root, 'fct-pdf-template-overlay')).toHaveLength(0);
        expect(byClass(root, 'fct-button').filter(b => b.textContent.trim() === 'Edit Template')).toHaveLength(0);
        expect(byClass(root, 'fct-button').filter(b => b.textContent.trim() === 'Customize')).toHaveLength(3);
    });

    it('puts the delete button in the actions row, at the end, and only on a template an admin added', async () => {
        const root = await mountPage();
        const actions = node => byClass(node, 'fct-pdf-template-actions')[0];
        const deleteIn = node => byClass(node, 'fct-pdf-delete-btn');

        expect(deleteIn(actions(card(root, 'custom')))).toHaveLength(1);
        const trash = deleteIn(card(root, 'custom'))[0];
        expect(trash.textContent.trim()).toBe(''); // an icon, not text
        expect(trash.findAll(node => node.tagName === 'svg')).toHaveLength(1);
        expect(trash.attrs['aria-label']).toBe('Delete'); // so a screen reader still hears what it does
        expect(deleteIn(card(root, 'custom'))).toHaveLength(1);
        expect(byClass(actions(card(root, 'custom')), 'fct-pdf-template-delete')).toHaveLength(1);
        expect(deleteIn(card(root, 'Order Receipt'))).toHaveLength(0);
        expect(deleteIn(card(root, 'Renewal Receipt'))).toHaveLength(0);
    });

    it('offers Reset only on an edited built-in template', async () => {
        const root = await mountPage();

        expect(button(card(root, 'Order Receipt'), 'Reset')).toBeDefined();
        expect(String(button(card(root, 'Order Receipt'), 'Reset').className)).toContain('fct-pdf-template-reset'); // styled apart from Customize
        expect(String(button(card(root, 'Order Receipt'), 'Customize').className)).not.toContain('fct-pdf-template-reset');
        expect(button(card(root, 'Renewal Receipt'), 'Reset')).toBeUndefined();
        expect(button(card(root, 'custom'), 'Reset')).toBeUndefined();
    });

    it('puts the factory design back after a confirmation, then loads the list again', async () => {
        const root = await mountPage();
        rest.get.mockClear();
        rest.get.mockImplementation(url => Promise.resolve(
            url === 'settings/pdf-templates/factory-default' ? {templates: factory} : {templates: templates(false), has_fluent_pdf: true}
        ));

        button(card(root, 'Order Receipt'), 'Reset').listeners.click();
        await flush();

        expect(common.$confirm).toHaveBeenCalledTimes(1);
        expect(rest.post).toHaveBeenCalledWith('settings/pdf-templates/receipt/order_receipt', {pdf_structure: {content: 'FACTORY', meta: {}}});
        expect(rest.get).toHaveBeenCalledWith('settings/pdf-templates/receipt');
        expect(notify.success).toHaveBeenCalled();
        expect(badge(card(root, 'Order Receipt')).textContent.trim()).toBe('Built-in');
    });

    it('changes nothing when the admin cancels the question', async () => {
        const root = await mountPage();
        common.$confirm.mockRejectedValue('cancel');

        button(card(root, 'Order Receipt'), 'Reset').listeners.click();
        await flush();

        expect(rest.post).not.toHaveBeenCalled();
        expect(badge(card(root, 'Order Receipt')).textContent.trim()).toBe('Customized');
    });

    it('marks an edited template an admin added, and resets it to the order receipt design', async () => {
        const answers = {
            list: {templates: templates(false, true), has_fluent_pdf: true},
            factory: {templates: factory},
        };
        rest.get.mockImplementation(url => Promise.resolve(url === 'settings/pdf-templates/factory-default' ? answers.factory : answers.list));
        const root = await mountPage();

        expect(badge(card(root, 'custom')).textContent.trim()).toBe('Customized');
        expect(button(card(root, 'custom'), 'Reset')).toBeDefined();
        expect(badge(card(root, 'Order Receipt')).textContent.trim()).toBe('Built-in');

        button(card(root, 'custom'), 'Reset').listeners.click();
        await flush();

        expect(common.$confirm.mock.calls[0][0]).toBe('Reset "custom" to the Order Receipt design? Your changes to it will be lost.');
        expect(rest.post).toHaveBeenCalledWith('settings/pdf-templates/receipt/custom_1', {pdf_structure: {content: 'FACTORY', meta: {}}});
    });

    it('saves nothing and shows an error when the factory has no design for the template', async () => {
        const root = await mountPage();
        rest.get.mockImplementation(url => Promise.resolve(
            url === 'settings/pdf-templates/factory-default' ? {templates: {}} : {templates: templates(true), has_fluent_pdf: true}
        ));

        button(card(root, 'Order Receipt'), 'Reset').listeners.click();
        await flush();

        expect(rest.post).not.toHaveBeenCalled();
        expect(notify.error).toHaveBeenCalledWith('Failed to revert template');
        expect(notify.success).not.toHaveBeenCalled();
        expect(button(card(root, 'Order Receipt'), 'Reset').attrs['data-loading']).toBe('false'); // not left stuck loading
    });

    it('saves nothing and shows the error when the factory design cannot be loaded', async () => {
        const root = await mountPage();
        rest.get.mockImplementation(url => url === 'settings/pdf-templates/factory-default'
            ? Promise.reject({data: {message: 'offline'}})
            : Promise.resolve({templates: templates(true), has_fluent_pdf: true}));

        button(card(root, 'Order Receipt'), 'Reset').listeners.click();
        await flush();

        expect(rest.post).not.toHaveBeenCalled();
        expect(notify.error).toHaveBeenCalledWith('offline');
        expect(badge(card(root, 'Order Receipt')).textContent.trim()).toBe('Customized');
    });
});
