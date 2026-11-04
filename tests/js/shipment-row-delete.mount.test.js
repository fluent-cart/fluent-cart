import {beforeEach, describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick} from 'vue';
import ElementPlus, {ID_INJECTION_KEY} from 'element-plus';

/**
 * Delete sits in the ⋯ menu and asks inside the menu before it deletes. The
 * question names the label void when the shipment has a bought label.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    globalThis.MouseEvent = globalThis.MouseEvent || class MouseEvent {};
});

const rest = vi.hoisted(() => ({delete: vi.fn()}));

vi.mock('@/utils/http/Rest', () => ({default: rest}));
vi.mock('@/utils/Notify', () => ({default: {success: vi.fn(), error: vi.fn()}}));
vi.mock('@/utils/translator/Translator', async () => ({
    default: (await import('./support/reserved-words.js')).withReserved((text, ...args) => args.reduce((out, arg, index) => out.replace('%' + (index + 1) + '$s', arg), text)),
}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {get: (key, fallback) => fallback}}));
vi.mock('@/utils/permission/Permission', () => ({default: {resolve: () => true}}));
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

const flush = async () => {
    for (let tick = 0; tick < 4; tick++) {
        await Promise.resolve();
        await nextTick();
    }
};

// Renders the menu body in place, as if the ⋯ popover were open.
const PopoverStub = {setup: (props, {slots}) => () => h('div', slots.default ? slots.default() : [])};

const mountRow = async (shipment) => {
    const root = new FakeNode('table');
    const Host = defineComponent({setup: () => () => h(ShipmentRecord, {shipment, orderId: 7, currency: 'USD'})});
    renderer.createApp(Host)
        .use(ElementPlus)
        .component('ElDropdown', {render: () => null})
        .component('ElPopover', PopoverStub)
        .provide(ID_INJECTION_KEY, {prefix: 1, current: 0})
        .mount(root);
    await flush();
    return root;
};

const labelled = {id: 9, status: 'shipped', carrier_slug: 'shippo', carrier_name: 'Shippo', tracking_number: '1Z', meta: {transaction_id: 'tx_1'}, items: []};
const manual = {id: 5, status: 'dispatched', carrier_slug: 'manual', tracking_number: '', meta: {}, items: []};

const deleteItem = root => byClass(root, 'is-danger')[0];

// @click.stop needs an event it can stop.
const clickEvent = () => Object.assign(new MouseEvent(), {stopPropagation: () => {}});

describe('Delete in the shipment menu', () => {
    beforeEach(() => {
        rest.delete.mockReset();
        rest.delete.mockResolvedValue({message: 'Deleted'});
    });

    it('offers Delete even when the shipment has no other actions', async () => {
        const root = await mountRow(manual);
        expect(deleteItem(root).textContent.trim()).toBe('Delete shipment');
    });

    it('asks before deleting and names the label void', async () => {
        const root = await mountRow(labelled);
        deleteItem(root).listeners.click(clickEvent());
        await flush();

        expect(rest.delete).not.toHaveBeenCalled();
        expect(byClass(root, 'fct-shipment-menu__confirm')[0].textContent)
            .toBe('Delete this shipment? Its Shippo label will be voided, and the carrier refunds it if eligible.');

        const confirm = byClass(root, 'el-button').filter(node => node.textContent.trim() === 'Yes, Delete')[0];
        confirm.listeners.click(new MouseEvent());
        await flush();

        expect(rest.delete).toHaveBeenCalledWith('orders/7/shipments/9');
    });

    it('goes back to the actions on Cancel', async () => {
        const root = await mountRow(manual);
        deleteItem(root).listeners.click(clickEvent());
        await flush();

        byClass(root, 'el-button').filter(node => node.textContent.trim() === 'Cancel')[0].listeners.click(new MouseEvent());
        await flush();

        expect(byClass(root, 'fct-shipment-menu__confirm')).toHaveLength(0);
        expect(deleteItem(root)).toBeTruthy();
        expect(rest.delete).not.toHaveBeenCalled();
    });
});
