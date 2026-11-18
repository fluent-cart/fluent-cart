import {describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick} from 'vue';
import ElementPlus, {ID_INJECTION_KEY} from 'element-plus';
import {fctPlugin} from './support/fct-stubs.js';

/**
 * A shipment row expands into a details row: latest tracking, carrier and the
 * note to the customer. Empty values show a placeholder, not a blank cell.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
});

vi.mock('@/utils/http/Rest', () => ({default: {}}));
vi.mock('@/utils/Notify', () => ({default: {}}));
vi.mock('@/utils/translator/Translator', () => ({
    default: (text, ...args) => args.reduce((out, arg, index) => out.replace('%' + (index + 1) + '$s', arg), text),
}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {get: (key, fallback) => fallback}}));
vi.mock('@/Modules/Orders/Components/shipmentCost', () => ({formatShipmentCost: () => ''}));
vi.mock('@/Bits/common', () => ({formatDate: () => '', getErrorMessage: (errors, fallback) => fallback}));
vi.mock('@/Bits/Components/Permission/UserCan.vue', () => ({
    default: {props: ['permission'], setup: (props, {slots}) => () => (slots.default ? slots.default() : null)},
}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Buttons/IconButton.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Badge.vue', () => ({default: {render: () => null}}));
vi.mock('@/utils/Clipboard', () => ({default: {copy: vi.fn()}}));

import ShipmentRecord from '@/Modules/Orders/Components/ShipmentRecord.vue';
import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';

const stub = {render: () => null};

const mountRow = async (shipment) => {
    const root = new FakeNode('table');
    const Host = defineComponent({setup: () => () => h(ShipmentRecord, {shipment, orderId: 7, currency: 'USD'})});
    renderer.createApp(Host)
        .use(ElementPlus)
        .component('ElDropdown', stub)
        .component('ElPopconfirm', stub)
        .component('ElPopover', stub)
        .use(fctPlugin({FctDropdown: stub, FctPopover: stub}))
        .provide(ID_INJECTION_KEY, {prefix: 1, current: 0})
        .mount(root);
    await nextTick();
    return root;
};

const manual = {id: 5, status: 'dispatched', carrier_slug: 'manual', tracking_number: '', notes: '', meta: {}, items: []};

describe('Shipment row details', () => {
    it('toggles the details row from the expand button', async () => {
        const root = await mountRow(manual);
        const toggle = byClass(root, 'fct-shipments-table__expand')[0];
        const details = byClass(root, 'fct-shipments-table__details')[0];

        expect(toggle.attrs['aria-expanded']).toBe('false');
        expect(toggle.attrs['aria-controls']).toBe(details.attrs.id);
        expect(details.style.display).toBe('none');

        toggle.listeners.click();
        await nextTick();
        expect(toggle.attrs['aria-expanded']).toBe('true');
        expect(details.style.display).not.toBe('none');

        toggle.listeners.click();
        await nextTick();
        expect(toggle.attrs['aria-expanded']).toBe('false');
        expect(details.style.display).toBe('none');
    });

    it('toggles the details from a click anywhere on the row, but not from its controls', async () => {
        const root = await mountRow(manual);
        const row = byClass(root, 'fct-shipments-table__row')[0];
        const toggle = byClass(root, 'fct-shipments-table__expand')[0];
        // The fake DOM has no closest(); the event says whether a control was hit.
        const clickOn = control => ({target: {closest: () => (control ? {} : null)}});

        row.listeners.click(clickOn(true));
        await nextTick();
        expect(toggle.attrs['aria-expanded']).toBe('false');

        row.listeners.click(clickOn(false));
        await nextTick();
        expect(toggle.attrs['aria-expanded']).toBe('true');

        row.listeners.click(clickOn(false));
        await nextTick();
        expect(toggle.attrs['aria-expanded']).toBe('false');
    });

    it('shows None for an empty note and a dash for an empty tracking number', async () => {
        const root = await mountRow(manual);
        const details = byClass(root, 'fct-shipments-table__details')[0];

        expect(details.textContent).toContain('Note to customer');
        expect(byClass(details, 'fct-shipments-table__muted').map(node => node.textContent.trim())).toContain('None');
        expect(byClass(root, 'fct-shipments-table__empty')[0].textContent).toBe('—');
        expect(byClass(root, 'fct-shipment-tracking')).toHaveLength(0);
    });
});
