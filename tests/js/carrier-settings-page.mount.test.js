import {beforeAll, beforeEach, describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick} from 'vue';
import ElementPlus, {ID_INJECTION_KEY} from 'element-plus';

/**
 * The carrier settings page as the Shippo design draws it: one card per section with a status badge, no repeated
 * carrier heading, a SaveBar for unsaved edits, status strips whose buttons run carrier actions at once, mode cards
 * that say which modes have a token, a grouped carrier select, read-only rows, notices and the manual webhook steps.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    globalThis.window.addEventListener = globalThis.window.addEventListener || (() => {});
    globalThis.window.removeEventListener = globalThis.window.removeEventListener || (() => {});
    globalThis.MouseEvent = globalThis.MouseEvent || class MouseEvent {};
});

const rest = vi.hoisted(() => ({put: vi.fn(), post: vi.fn()}));
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
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/CopyToClipboard.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Animation.vue', () => ({
    default: {props: ['visible'], setup: (props, {slots}) => () => (props.visible ? slots.default() : null)},
}));

import SingleCarrier from '@/Modules/Shipping/SingleCarrier.vue';
import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';

const URL = 'https://shop.test/?fluent-cart=fct_shipping_listener_webhook&carrier=shippo&fcs_token=SECRET123';

const fields = () => [
    {key: 'section_connection', type: 'section', label: 'Connection', badge: {tone: 'ok', text: 'Connected · Test mode', dot: true}},
    {key: 'is_active', type: 'toggle', label: 'Enable Shippo'},
    {
        key: 'connection_status', type: 'status', title: 'Connected to Shippo',
        items: [{label: 'Account', value: 'store-owner@example.com'}, {label: 'Carriers', value: '14'}],
        actions: [{action: 'test_connection', label: 'Test connection', working: {title: 'Checking the test token with Shippo', text: 'Usually takes under a second.', button: 'Testing'}}],
    },
    {
        key: 'sandbox_mode', type: 'mode', label: 'Connection mode', layout: 'row',
        options: [
            {value: 'no', label: 'Live', hint: 'Real labels, billed by Shippo.', tag: {tone: 'mute', text: 'No live token saved'},
                requires: 'live_token', missing_note: 'Add a live token before you save. Until then the store stays on Test.',
                fields: [{key: 'live_token', type: 'password', label: 'Live token'}]},
            {value: 'yes', label: 'Test', hint: 'Free labels that never ship.', tag: {tone: 'ok', text: 'Token ending acf9 · verified'},
                requires: 'test_token', fields: [{key: 'test_token', type: 'password', label: 'Test token'}]},
        ],
    },
    {key: 'section_labels', type: 'section', label: 'Labels', active_if: {key: 'is_active', value: 'yes'}},
    {
        key: 'default_carrier', type: 'select', label: 'Preferred carrier',
        options: [{value: '', label: 'Cheapest of all carriers', sub: 'Default'}],
        groups: [
            {label: 'Your own accounts', empty: 'None connected.', options: []},
            {label: 'Shippo accounts', options: [{value: 'usps', label: 'USPS', sub: '12 services'}, {value: 'dhl_express', label: 'DHL Express', sub: '22 services'}]},
        ],
        search_placeholder: 'Search carriers',
        footer: '2 carriers from your test account',
        refresh: {action: 'refresh_carriers', label: 'Refresh list'},
    },
    {key: 'units', type: 'readonly', label: 'Units', value: 'kg and cm', hint: 'From Store Settings.', link: {label: 'Change', route: '/settings/store-settings'}},
    {key: 'section_sender', type: 'section', label: 'Sender', aside: 'Printed on every label'},
    {key: 'phone_notice', type: 'notice', tone: 'warn', text: 'USPS labels need a sender phone.'},
    {key: 'section_tracking', type: 'section', label: 'Tracking updates', badge: {tone: 'ok', text: 'Webhook registered', dot: true}},
    {
        key: 'webhook_status', type: 'status', title: 'Shippo sends tracking updates to this store',
        items: [{label: 'Event', value: 'track_updated'}],
        note: "Webhook actions take effect right away. They don't need Save.",
        actions: [
            {action: 'check_webhook', label: 'Check status'},
            {action: 'rotate_secret', label: 'Rotate secret', variant: 'danger',
                confirm: {title: 'Rotate the webhook secret?', text: 'The current URL stops working right away.', button: 'Rotate'}},
        ],
    },
    {key: 'webhook_setup', type: 'webhook_setup', label: 'Set it up in Shippo yourself', steps: ['In Shippo, open Settings › API › Webhooks.', 'Add this URL for the **track_updated** event.']},
];

const shippo = (extra = {}) => ({
    slug: 'shippo',
    meta: {name: 'Shippo', description: 'Multi-carrier shipping.'},
    fields: fields(),
    is_enabled: true,
    settings: {is_active: 'yes', sandbox_mode: 'yes', live_token: '', test_token: '', default_carrier: ''},
    saved_secrets: {test_token: {ends_with: 'acf9'}},
    webhook_url: URL,
    notices: [],
    ...extra,
});

// Set after Vue loads: runtime-dom probes document.createElement on import. The select listens for outside clicks.
beforeAll(() => {
    globalThis.document = globalThis.document || {addEventListener: () => {}, removeEventListener: () => {}};
});

const flush = async () => {
    for (let tick = 0; tick < 6; tick++) {
        await Promise.resolve();
        await nextTick();
    }
};

let page;
const mountPage = async () => {
    const root = new FakeNode('div');
    const Host = defineComponent({setup: () => () => h(SingleCarrier, {ref: (instance) => { page = instance; }})});
    renderer.createApp(Host).use(ElementPlus).provide(ID_INJECTION_KEY, {prefix: 1, current: 0}).mount(root);
    await flush();
    return root;
};

const text = (node) => node.textContent.replace(/\s+/g, ' ').trim();
const buttonByText = (root, label) => root.findAll(node => node.tagName === 'button' && text(node) === label)[0];
const click = async (node) => { node.listeners.click(new MouseEvent()); await flush(); };

describe('Carrier settings page layout', () => {
    beforeEach(() => {
        carriers.list = [shippo()];
        rest.put.mockReset();
        rest.post.mockReset();
        notify.success.mockReset();
        notify.error.mockReset();
    });

    it('draws each section as its own card with its badge or aside, and no repeated carrier heading', async () => {
        const root = await mountPage();

        const cards = byClass(root, 'fct-carrier-card');
        expect(cards.map(card => text(byClass(card, 'fct-carrier-section__title')[0]))).toEqual(['Connection', 'Labels', 'Sender', 'Tracking updates']);
        expect(text(byClass(cards[0], 'fct-carrier-tag')[0])).toBe('Connected · Test mode');
        expect(byClass(cards[0], 'fct-carrier-dot')).toHaveLength(1);
        expect(text(cards[2])).toContain('Printed on every label');
        expect(text(root)).not.toContain('Multi-carrier shipping.');
        expect(root.findAll(node => node.tagName === 'h2' && text(node) === 'Shippo')).toHaveLength(0);
    });

    it('dims a card while its active_if field does not hold the value', async () => {
        carriers.list = [shippo({settings: {is_active: 'no', sandbox_mode: 'yes', default_carrier: ''}})];
        const root = await mountPage();

        const labels = byClass(root, 'fct-carrier-card').find(card => text(card).includes('Preferred carrier'));
        expect(labels.className).toContain('is-off');
        expect(byClass(root, 'fct-carrier-card')[0].className).not.toContain('is-off');
    });

    it('shows the SaveBar and enables Save only after an edit, and Discard puts the saved values back', async () => {
        const root = await mountPage();
        const bar = byClass(root, 'fct-save-bar')[0];
        expect(bar.className).not.toContain('is-active');
        expect(buttonByText(root, 'Save settings').attrs.disabled).toBeTruthy();

        page.$.setupState.formState.default_carrier = 'usps';
        await flush();
        expect(byClass(root, 'fct-save-bar')[0].className).toContain('is-active');
        expect(buttonByText(root, 'Save settings').attrs.disabled).toBeFalsy();

        await click(buttonByText(root, 'Discard'));
        expect(page.$.setupState.formState.default_carrier).toBe('');
        expect(byClass(root, 'fct-save-bar')[0].className).not.toContain('is-active');
    });
});

describe('Carrier status strips and actions', () => {
    beforeEach(() => {
        carriers.list = [shippo()];
        rest.post.mockReset();
        notify.success.mockReset();
        notify.error.mockReset();
    });

    it('shows the strip facts and runs a button\'s action at once, keeping unsaved edits', async () => {
        let resolve;
        rest.post.mockReturnValue(new Promise((done) => { resolve = done; }));
        const root = await mountPage();

        expect(text(byClass(root, 'fct-carrier-strip')[0])).toContain('Account store-owner@example.com');
        expect(byClass(root, 'fct-carrier-strip__note').map(text)).toEqual(["Webhook actions take effect right away. They don't need Save."]);
        page.$.setupState.formState.default_carrier = 'usps';
        await click(buttonByText(root, 'Test connection'));

        expect(rest.post).toHaveBeenCalledWith('shipping/carriers/shippo/actions/test_connection', {});
        const strip = byClass(root, 'fct-carrier-strip')[0];
        expect(text(strip)).toContain('Checking the test token with Shippo');
        expect(text(strip)).toContain('Testing');
        expect(text(byClass(byClass(root, 'fct-carrier-card')[0], 'fct-carrier-tag')[0])).toBe('Working');

        const after = shippo();
        after.fields[2].items = [{label: 'Account', value: 'other@example.com'}];
        resolve({message: 'Shippo accepted the token', ok: true, carrier: after});
        await flush();

        expect(notify.success).toHaveBeenCalledWith('Shippo accepted the token');
        expect(text(byClass(root, 'fct-carrier-strip')[0])).toContain('other@example.com');
        expect(page.$.setupState.formState.default_carrier).toBe('usps');
    });

    it('shows an error toast when the carrier refuses the action', async () => {
        rest.post.mockResolvedValue({message: "Shippo didn't accept the token", ok: false, carrier: shippo()});
        const root = await mountPage();

        await click(buttonByText(root, 'Test connection'));

        expect(notify.error).toHaveBeenCalledWith("Shippo didn't accept the token");
        expect(notify.success).not.toHaveBeenCalled();
    });

    it('asks before a confirm action and runs it only from the confirm button', async () => {
        rest.post.mockResolvedValue({message: 'Secret rotated. Shippo has the new URL.', ok: true, carrier: shippo()});
        const root = await mountPage();

        await click(buttonByText(root, 'Rotate secret'));
        expect(rest.post).not.toHaveBeenCalled();
        const pop = byClass(root, 'fct-carrier-pop')[0];
        expect(text(pop)).toContain('Rotate the webhook secret?');

        await click(buttonByText(pop, 'Rotate'));
        expect(rest.post).toHaveBeenCalledWith('shipping/carriers/shippo/actions/rotate_secret', {});
    });
});

describe('Carrier mode cards', () => {
    beforeEach(() => {
        carriers.list = [shippo()];
        rest.put.mockReset();
        notify.error.mockReset();
    });

    const modeCards = (root) => byClass(root, 'fct-radio-cards')[0].children.filter(node => node.tagName === 'li');
    const inputsIn = (node) => node.findAll(child => child.tagName === 'input');

    it('tags each mode with its token state and puts each mode\'s token input inside its own card', async () => {
        const root = await mountPage();

        expect(byClass(root, 'fct-radio-cards')[0].className).toContain('is-row');
        expect(byClass(root, 'fct-radio-cards-content').map(node => text(byClass(node, 'fct-carrier-tag')[0])))
            .toEqual(['No live token saved', 'Token ending acf9 · verified']);
        const [live, test] = modeCards(root);
        expect(text(live)).toContain('Live token');
        expect(text(test)).toContain('Test token');
        expect(modeCards(root).map(card => inputsIn(card).length)).toEqual([1, 1]);
        // The input sits in the card, outside the radio.
        modeCards(root).forEach(card => expect(inputsIn(byClass(card, 'fct-radio-cards-header')[0])).toHaveLength(0));
        expect(page.$.setupState.formState.sandbox_mode).toBe('yes');
    });

    it('keeps the browser from filling a saved login into a token field', async () => {
        const root = await mountPage();

        // Firefox filled the WP login here, which made the page unsaved and the SaveBar blocked every link.
        const tokens = root.findAll(node => node.tagName === 'input' && (node.type === 'password' || node.getAttribute('type') === 'password'));
        expect(tokens.length).toBeGreaterThan(0);
        expect(tokens.map(input => input.getAttribute('autocomplete'))).toEqual(tokens.map(() => 'new-password'));
    });

    it('blocks Save on a mode with no token saved or typed, with no banner in the card', async () => {
        const root = await mountPage();
        page.$.setupState.formState.sandbox_mode = 'no';
        await flush();

        expect(byClass(root, 'fct-carrier-mode-note')).toHaveLength(0);
        expect(text(modeCards(root)[0])).not.toContain('Add a live token');
        await click(buttonByText(root, 'Save settings'));
        expect(rest.put).not.toHaveBeenCalled();
        expect(notify.error).toHaveBeenCalledWith('Add a live token before you save. Until then the store stays on Test.');
    });

    it('keeps both token inputs in place when the mode changes', async () => {
        const root = await mountPage();

        page.$.setupState.formState.sandbox_mode = 'no';
        await flush();
        expect(modeCards(root).map(card => inputsIn(card).length)).toEqual([1, 1]);

        page.$.setupState.formState.sandbox_mode = 'yes';
        await flush();
        expect(modeCards(root).map(card => inputsIn(card).length)).toEqual([1, 1]);
    });

    it('draws no block under the cards in the row layout', async () => {
        const root = await mountPage();

        expect(byClass(root, 'fct-carrier-mode-below')).toHaveLength(0);
    });
});

describe('Carrier select, read-only rows, notices and manual webhook steps', () => {
    beforeEach(() => {
        carriers.list = [shippo()];
        rest.post.mockReset();
    });

    it('lists the leading option and each group with its empty text, and Refresh list runs the action', async () => {
        rest.post.mockResolvedValue({message: 'Carrier list updated from Shippo', ok: true, carrier: shippo()});
        const root = await mountPage();

        await click(byClass(root, 'fct-carrier-dd__btn')[0]);
        expect(byClass(root, 'fct-carrier-dd__group').map(text)).toEqual(['Your own accounts', 'Shippo accounts']);
        expect(byClass(root, 'fct-carrier-dd__empty').map(text)).toEqual(['None connected.']);
        expect(byClass(root, 'fct-carrier-dd__name').map(text)).toEqual(['Cheapest of all carriers', 'USPS', 'DHL Express']);
        expect(byClass(root, 'fct-carrier-mono').map(text)).toContain('DE');
        expect(text(byClass(root, 'fct-carrier-dd__foot')[0])).toContain('2 carriers from your test account');

        await click(buttonByText(root, 'Refresh list'));
        expect(rest.post).toHaveBeenCalledWith('shipping/carriers/shippo/actions/refresh_carriers', {});
    });

    it('picks an option from the list', async () => {
        const root = await mountPage();

        await click(byClass(root, 'fct-carrier-dd__btn')[0]);
        const dhl = byClass(root, 'fct-carrier-dd__opt').find(node => text(node).includes('DHL Express'));
        await click(dhl);

        expect(page.$.setupState.formState.default_carrier).toBe('dhl_express');
        expect(text(byClass(root, 'fct-carrier-dd__val')[0])).toBe('DE DHL Express');
    });

    it('draws a read-only row with its link and a notice in its tone', async () => {
        const root = await mountPage();

        const row = byClass(root, 'fct-carrier-ro')[0];
        expect(text(row)).toContain('kg and cm');
        expect(text(row)).toContain('Change');
        expect(byClass(root, 'fct-carrier-alert').find(node => text(node).includes('USPS labels need a sender phone.')).className).toContain('is-warn');
    });

    it('masks the webhook secret until Show and bolds marked words in the steps', async () => {
        const root = await mountPage();

        const setup = byClass(root, 'fct-carrier-man')[0];
        await click(byClass(setup, 'fct-carrier-man__toggle')[0]);
        const input = setup.findAll(node => node.tagName === 'input')[0];
        expect(input.value).not.toContain('SECRET123');
        expect(input.value).toContain('fcs_token=••••');

        await click(buttonByText(setup, 'Show'));
        expect(setup.findAll(node => node.tagName === 'input')[0].value).toBe(URL);
        expect(setup.findAll(node => node.tagName === 'b').map(text)).toEqual(['track_updated']);
    });

    it('opens the manual steps with a toggle button, or by itself when the carrier asks', async () => {
        let root = await mountPage();
        let setup = byClass(root, 'fct-carrier-man')[0];
        const toggle = byClass(setup, 'fct-carrier-man__toggle')[0];

        expect(toggle.tagName).toBe('button');
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
        expect(byClass(setup, 'fct-carrier-man__body')).toHaveLength(0);

        await click(toggle);
        expect(toggle.getAttribute('aria-expanded')).toBe('true');
        expect(byClass(setup, 'fct-carrier-man__body')).toHaveLength(1);

        carriers.list = [shippo({fields: fields().map(field => (field.type === 'webhook_setup' ? {...field, open: true} : field))})];
        root = await mountPage();
        setup = byClass(root, 'fct-carrier-man')[0];
        expect(byClass(setup, 'fct-carrier-man__body')).toHaveLength(1);
    });

    it('does not add the plain webhook URL box when a status strip owns the webhook', async () => {
        const noSetup = fields().filter(field => field.type !== 'webhook_setup');
        carriers.list = [shippo({fields: noSetup})];
        expect(byClass(await mountPage(), 'fct-carrier-webhook')).toHaveLength(1);

        carriers.list = [shippo({fields: noSetup.map(field => (field.key === 'webhook_status' ? {...field, shows_webhook: true} : field))})];
        expect(byClass(await mountPage(), 'fct-carrier-webhook')).toHaveLength(0);
    });

    it('does not add the plain webhook URL box when the manual setup already shows the URL', async () => {
        const root = await mountPage();

        expect(byClass(root, 'fct-carrier-man')).toHaveLength(1);
        expect(byClass(root, 'fct-carrier-webhook')).toHaveLength(0);
    });
});
