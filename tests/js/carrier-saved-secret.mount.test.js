import {beforeEach, describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick} from 'vue';
import ElementPlus, {ID_INJECTION_KEY} from 'element-plus';

/**
 * A carrier's mode cards after a save: each stored token shows as saved without
 * its value, and the tick marks the saved mode, not the card just clicked.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    // The page's SaveBar listens for beforeunload.
    globalThis.window.addEventListener = globalThis.window.addEventListener || (() => {});
    globalThis.window.removeEventListener = globalThis.window.removeEventListener || (() => {});
    globalThis.MouseEvent = globalThis.MouseEvent || class MouseEvent {};
});

const rest = vi.hoisted(() => ({put: vi.fn()}));
const notify = vi.hoisted(() => ({success: vi.fn(), error: vi.fn(), validationErrors: vi.fn()}));
const route = vi.hoisted(() => ({params: {slug: 'shippo'}}));
const carriers = vi.hoisted(() => ({list: []}));

vi.mock('@/utils/http/Rest', () => ({default: rest}));
vi.mock('@/utils/Notify', () => ({default: notify}));
vi.mock('@/utils/translator/Translator', () => ({
    default: (text, ...args) => args.reduce((out, arg, index) => out.replace('%' + (index + 1) + '$s', arg), text),
}));
vi.mock('@/utils/KeyboardShortcut', () => ({default: () => ({bind: () => {}, unbind: () => {}})}));
vi.mock('vue-router', () => ({useRoute: () => route, useRouter: () => ({push: () => {}}), onBeforeRouteLeave: () => {}}));
vi.mock('@/Modules/Shipping/useCarriers', () => ({
    fetchCarriers: () => Promise.resolve(carriers.list),
    invalidateCarriersCache: () => {},
}));
vi.mock('@/Modules/Shipping/CarrierZonesPanel.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({
    default: {props: ['name'], render() { return h('i', {class: 'icon-' + this.name}); }},
}));
vi.mock('@/Bits/Components/CopyToClipboard.vue', () => ({default: {render: () => null}}));
// The accordion transition needs requestAnimationFrame; render the open state directly.
vi.mock('@/Bits/Components/Animation.vue', () => ({
    default: {props: ['visible'], setup: (props, {slots}) => () => (props.visible ? slots.default() : null)},
}));

import SingleCarrier from '@/Modules/Shipping/SingleCarrier.vue';
import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';

const fields = [
    {
        key: 'sandbox_mode',
        type: 'mode',
        label: 'Connection Mode',
        options: [
            {value: 'no', label: 'Live', fields: [{key: 'live_token', type: 'password', label: 'Live Token'}]},
            {value: 'yes', label: 'Test', fields: [{key: 'test_token', type: 'password', label: 'Test Token'}]},
        ],
    },
];

const shippo = (mode, savedSecrets, isEnabled = true) => ({
    slug: 'shippo',
    meta: {name: 'Shippo', description: ''},
    fields,
    is_enabled: isEnabled,
    settings: {sandbox_mode: mode, live_token: '', test_token: ''},
    saved_secrets: savedSecrets,
    webhook_url: '',
    notices: [],
});

const flush = async () => {
    for (let tick = 0; tick < 5; tick++) {
        await Promise.resolve();
        await nextTick();
    }
};

const mountPage = async () => {
    const root = new FakeNode('div');
    const Host = defineComponent({setup: () => () => h(SingleCarrier)});
    // Element Plus needs an id provider outside a browser.
    renderer.createApp(Host).use(ElementPlus).provide(ID_INJECTION_KEY, {prefix: 1, current: 0}).mount(root);
    await flush();
    return root;
};

const cardTitled = (root, title) => root.findAll(node => node.tagName === 'li'
    && byClass(node, 'fct-radio-cards-title').some(span => span.textContent === title))[0];

const activeCards = root => byClass(root, 'fct-radio-cards-active')
    .map(badge => byClass(badge.parentNode, 'fct-radio-cards-title')[0].textContent);

const passwordInput = root => root.findAll(node => node.tagName === 'input' && node.attrs.type === 'password')[0];

describe('Carrier saved secrets and active mode', () => {
    beforeEach(() => {
        carriers.list = [shippo('no', {live_token: {ends_with: '2a9f'}})];
    });

    it('shows a stored token as saved, by its last characters only', async () => {
        const root = await mountPage();

        const input = passwordInput(root);
        expect(input).toBeTruthy();
        expect(input.value).toBe('');
        expect(input.attrs.placeholder).toContain('2a9f');

        const notes = byClass(root, 'fct-carrier-secret-saved');
        expect(notes).toHaveLength(1);
        expect(notes[0].textContent).toContain('Saved');
        expect(notes[0].textContent).toContain('2a9f');
        expect(String(input.attrs['aria-describedby'])).toContain(notes[0].attrs.id);
    });

    it('says nothing is saved for a token that was never stored', async () => {
        carriers.list = [shippo('yes', {live_token: {ends_with: '2a9f'}})];
        const root = await mountPage();

        const input = passwordInput(root);
        expect(input.attrs.placeholder || '').not.toContain('2a9f');
        expect(byClass(root, 'fct-carrier-secret-saved')).toHaveLength(0);
    });

    it('uses the field saved placeholder, with and without an ending', async () => {
        const named = {
            saved_placeholder: 'Leave blank to keep the saved API key',
            saved_placeholder_ending: 'Leave blank to keep the saved API key (ending %1$s)',
        };
        const withNamed = mode => ({
            ...shippo(mode, {live_token: {ends_with: '2a9f'}, test_token: {ends_with: ''}}),
            fields: [{...fields[0], options: fields[0].options.map(option => ({
                ...option, fields: option.fields.map(field => ({...field, ...named})),
            }))}],
        });

        carriers.list = [withNamed('no')];
        expect(passwordInput(await mountPage()).attrs.placeholder).toBe('Leave blank to keep the saved API key (ending 2a9f)');

        carriers.list = [withNamed('yes')];
        expect(passwordInput(await mountPage()).attrs.placeholder).toBe('Leave blank to keep the saved API key');
    });

    it('keeps the core token placeholder for a field without its own', async () => {
        expect(passwordInput(await mountPage()).attrs.placeholder).toBe('Leave blank to keep the saved token (ending 2a9f)');
    });

    it('ticks the saved mode, and moves the tick only once a save lands', async () => {
        const root = await mountPage();
        expect(activeCards(root)).toEqual(['Live']);
        const badge = byClass(cardTitled(root, 'Live'), 'fct-radio-cards-active')[0];
        expect(byClass(badge, 'icon-CheckCircleFill')).toHaveLength(1);
        expect(badge.textContent.trim()).toBe('Active');
        const liveHeader = byClass(cardTitled(root, 'Live'), 'fct-radio-cards-header')[0];
        expect(liveHeader.attrs['aria-describedby']).toBe(badge.attrs.id);

        const testHeader = byClass(cardTitled(root, 'Test'), 'fct-radio-cards-header')[0];
        testHeader.listeners.click(new MouseEvent());
        await flush();
        expect(cardTitled(root, 'Test').className).toContain('active');
        expect(activeCards(root)).toEqual(['Live']);

        rest.put.mockResolvedValue({
            message: 'Carrier settings saved successfully.',
            settings: {sandbox_mode: 'yes', live_token: '', test_token: ''},
            carrier: shippo('yes', {live_token: {ends_with: '2a9f'}, test_token: {ends_with: ''}}),
        });
        byClass(root, 'el-button--primary')[0].listeners.click(new MouseEvent());
        await flush();

        expect(activeCards(root)).toEqual(['Test']);
        const notes = byClass(cardTitled(root, 'Test'), 'fct-carrier-secret-saved');
        expect(notes).toHaveLength(1);
        expect(notes[0].textContent).toBe('Saved. Leave blank to keep it.');
    });

    it('ticks no mode while the carrier is saved as disabled, and ticks once a save enables it', async () => {
        carriers.list = [shippo('yes', {}, false)];
        const root = await mountPage();

        expect(activeCards(root)).toEqual([]);
        const testHeader = byClass(cardTitled(root, 'Test'), 'fct-radio-cards-header')[0];
        expect(testHeader.attrs['aria-describedby']).toBeFalsy();

        rest.put.mockResolvedValue({
            message: 'Carrier settings saved successfully.',
            settings: {sandbox_mode: 'yes', live_token: '', test_token: ''},
            carrier: shippo('yes', {test_token: {ends_with: '77ab'}}, true),
        });
        byClass(root, 'el-button--primary')[0].listeners.click(new MouseEvent());
        await flush();

        expect(activeCards(root)).toEqual(['Test']);
    });
});
