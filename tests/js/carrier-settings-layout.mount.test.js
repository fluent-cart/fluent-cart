import {beforeEach, describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick} from 'vue';
import ElementPlus, {ID_INJECTION_KEY} from 'element-plus';

/**
 * How a carrier's settings form lays out its fields: a section entry is a heading, a field with show_if shows only
 * while another field holds the given value, a half-width field takes half a row, and an input shows its own
 * placeholder, falling back to the hint when it declares none. A webhook_url entry places the webhook URL; without
 * one, the URL follows the form.
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
    {key: 'connection', type: 'section', label: 'Connection', hint: 'How the store reaches the carrier.'},
    {key: 'is_active', type: 'toggle', label: 'Enable carrier'},
    {key: 'labels', type: 'section', label: 'Labels'},
    {key: 'enable_labels', type: 'toggle', label: 'Enable labels'},
    {key: 'default_carrier', type: 'text', label: 'Preferred carrier', hint: 'A carrier token.', placeholder: 'usps', show_if: {key: 'enable_labels', value: 'yes'}},
    {key: 'seller_phone', type: 'text', label: 'Seller phone', hint: 'Printed on labels.', placeholder: '', width: 'half', show_if: {key: 'enable_labels', value: 'yes'}},
    {key: 'seller_email', type: 'text', label: 'Seller email', hint: 'Printed on labels.', width: 'half', show_if: {key: 'enable_labels', value: 'yes'}},
];

const carrier = (enableLabels, extra = {}) => ({
    slug: 'shippo',
    meta: {name: 'Shippo', description: ''},
    fields,
    is_enabled: true,
    settings: {is_active: 'yes', enable_labels: enableLabels, default_carrier: '', seller_phone: '', seller_email: ''},
    saved_secrets: {},
    webhook_url: '',
    notices: [],
    ...extra,
});

const trackingFields = [
    {key: 'tracking', type: 'section', label: 'Tracking'},
    {key: 'webhook', type: 'webhook_url'},
    {key: 'rotate_webhook_secret', type: 'toggle', label: 'Rotate secret'},
];

const flush = async () => {
    for (let tick = 0; tick < 5; tick++) {
        await Promise.resolve();
        await nextTick();
    }
};

const mountPage = async () => {
    const root = new FakeNode('div');
    const Host = defineComponent({setup: () => () => h(SingleCarrier)});
    renderer.createApp(Host).use(ElementPlus).provide(ID_INJECTION_KEY, {prefix: 1, current: 0}).mount(root);
    await flush();
    return root;
};

const fieldBox = (root, label) => byClass(root, 'fct-carrier-field')
    .find(box => box.findAll(node => node.tagName === 'label' && node.textContent.trim() === label).length);
const inputOf = (root, label) => fieldBox(root, label).findAll(node => node.tagName === 'input')[0];

describe('Carrier settings layout', () => {
    beforeEach(() => {
        carriers.list = [carrier('yes')];
    });

    it('renders a section entry as a heading with its description, not as an input', async () => {
        const root = await mountPage();

        const titles = byClass(root, 'fct-carrier-section__title');
        expect(titles.map(node => node.textContent.trim())).toEqual(['Connection', 'Labels']);
        expect(titles[0].tagName).toBe('h3');
        expect(byClass(root, 'fct-carrier-section__hint')[0].textContent.trim()).toBe('How the store reaches the carrier.');
        expect(root.findAll(node => node.tagName === 'input' && node.attrs.name === 'connection')).toHaveLength(0);
        expect(byClass(root, 'fct-carrier-field').map(box => box.findAll(node => node.tagName === 'label')[0]?.textContent.trim()))
            .not.toContain('Connection');
    });

    it('shows a show_if field only while the other field holds the value', async () => {
        let root = await mountPage();
        expect(fieldBox(root, 'Preferred carrier')).toBeTruthy();

        carriers.list = [carrier('no')];
        root = await mountPage();
        expect(fieldBox(root, 'Preferred carrier')).toBeFalsy();
        expect(fieldBox(root, 'Seller phone')).toBeFalsy();
        expect(fieldBox(root, 'Enable labels')).toBeTruthy();
    });

    it('compares a show_if value as text, since saved settings come back as strings', async () => {
        carriers.list = [carrier('yes', {
            fields: [
                // A text input, as the fake DOM cannot mount a select's dropdown portal.
                {key: 'service_level', type: 'text', label: 'Service level'},
                {key: 'express_note', type: 'text', label: 'Express note', show_if: {key: 'service_level', value: 1}},
            ],
            settings: {service_level: '1', express_note: ''},
        })];
        const root = await mountPage();

        expect(fieldBox(root, 'Express note')).toBeTruthy();
    });

    it('applies show_if to a field inside a mode option', async () => {
        const modeCarrier = (enableLabels) => carrier(enableLabels, {
            fields: [
                {key: 'enable_labels', type: 'toggle', label: 'Enable labels'},
                {key: 'sandbox_mode', type: 'mode', label: 'Mode', options: [
                    {value: 'yes', label: 'Test', fields: [
                        {key: 'test_token', type: 'password', label: 'Test token'},
                        {key: 'test_label_note', type: 'text', label: 'Test label note', show_if: {key: 'enable_labels', value: 'yes'}},
                    ]},
                ]},
            ],
            settings: {enable_labels: enableLabels, sandbox_mode: 'yes', test_token: '', test_label_note: ''},
        });

        carriers.list = [modeCarrier('yes')];
        let root = await mountPage();
        const labelOf = (node, text) => node.findAll(child => child.tagName === 'label' && child.textContent.trim() === text);
        expect(labelOf(root, 'Test token')).toHaveLength(1);
        expect(labelOf(root, 'Test label note')).toHaveLength(1);

        carriers.list = [modeCarrier('no')];
        root = await mountPage();
        expect(labelOf(root, 'Test token')).toHaveLength(1);
        expect(labelOf(root, 'Test label note')).toHaveLength(0);
    });

    it('draws a heading and the webhook URL inside a mode option, never as text inputs', async () => {
        carriers.list = [carrier('yes', {
            fields: [
                {key: 'sandbox_mode', type: 'mode', label: 'Mode', options: [
                    {value: 'yes', label: 'Test', fields: [
                        {key: 'test_heading', type: 'section', label: 'Test account'},
                        {key: 'test_token', type: 'password', label: 'Test token'},
                        {key: 'test_webhook', type: 'webhook_url'},
                    ]},
                ]},
            ],
            settings: {sandbox_mode: 'yes', test_token: ''},
            webhook_url: 'https://shop.test/hook?t=1',
        })];
        const root = await mountPage();

        expect(byClass(root, 'fct-carrier-section__title').map(node => node.textContent.trim())).toEqual(['Test account']);
        expect(byClass(root, 'fct-carrier-webhook')).toHaveLength(1);
        expect(byClass(root, 'fct-carrier-webhook')[0].className).not.toContain('border-t');
        expect(root.findAll(node => node.tagName === 'label' && ['Test account', ''].includes(node.textContent.trim()) && node.attrs.for)).toHaveLength(0);
        expect(root.findAll(node => node.tagName === 'input' && (node.attrs.name === 'test_heading' || node.attrs.name === 'test_webhook'))).toHaveLength(0);
    });

    it('gives a half-width field half a row and every other field a full row', async () => {
        const root = await mountPage();

        expect(fieldBox(root, 'Seller phone').className).toContain('fct-carrier-field--half');
        expect(fieldBox(root, 'Seller email').className).toContain('fct-carrier-field--half');
        expect(fieldBox(root, 'Preferred carrier').className).not.toContain('fct-carrier-field--half');
    });

    it('shows a declared placeholder, none when it is empty, and the hint when there is none', async () => {
        const root = await mountPage();

        expect(inputOf(root, 'Preferred carrier').attrs.placeholder).toBe('usps');
        expect(inputOf(root, 'Seller phone').attrs.placeholder || '').toBe('');
        expect(inputOf(root, 'Seller email').attrs.placeholder).toBe('Printed on labels.');
    });

    it('puts a toggle on the same row as its label, with the hint under the label', async () => {
        const root = await mountPage();

        const box = fieldBox(root, 'Enable labels');
        expect(byClass(box, 'el-form-item__label')).toHaveLength(0);
        const row = byClass(box, 'fct-carrier-toggle')[0];
        expect(row).toBeTruthy();
        expect(byClass(row, 'el-switch')).toHaveLength(1);
        const label = row.findAll(node => node.tagName === 'label')[0];
        expect(label.textContent.trim()).toBe('Enable labels');
        expect(label.attrs.for).toBe(row.findAll(node => node.tagName === 'input')[0].attrs.id);
        expect(byClass(fieldBox(root, 'Preferred carrier'), 'el-form-item__label')).toHaveLength(1);
    });

    it('draws the webhook URL where a webhook_url entry stands, once, before the fields after it', async () => {
        carriers.list = [carrier('yes', {fields: [...fields, ...trackingFields], webhook_url: 'https://shop.test/hook?t=1'})];
        const root = await mountPage();

        const blocks = byClass(root, 'fct-carrier-webhook');
        expect(blocks).toHaveLength(1);
        expect(blocks[0].className).not.toContain('border-t');
        const order = root.findAll(() => true);
        expect(order.indexOf(blocks[0])).toBeLessThan(order.indexOf(fieldBox(root, 'Rotate secret')));
        expect(order.indexOf(blocks[0])).toBeGreaterThan(order.indexOf(byClass(root, 'fct-carrier-section__title')[2]));
        expect(root.findAll(node => node.tagName === 'input' && node.attrs.name === 'webhook')).toHaveLength(0);
    });

    it('draws the webhook URL after the form when no entry places it', async () => {
        carriers.list = [carrier('yes', {webhook_url: 'https://shop.test/hook?t=1'})];
        const root = await mountPage();

        const blocks = byClass(root, 'fct-carrier-webhook');
        expect(blocks).toHaveLength(1);
        expect(blocks[0].className).toContain('border-t');
        const order = root.findAll(() => true);
        expect(order.indexOf(blocks[0])).toBeGreaterThan(order.indexOf(fieldBox(root, 'Seller email')));
    });

    it('draws nothing for a webhook_url entry when the carrier has no webhook URL', async () => {
        carriers.list = [carrier('yes', {fields: [...fields, ...trackingFields]})];
        const root = await mountPage();

        expect(byClass(root, 'fct-carrier-webhook')).toHaveLength(0);
        expect(fieldBox(root, 'Rotate secret')).toBeTruthy();
    });
});
