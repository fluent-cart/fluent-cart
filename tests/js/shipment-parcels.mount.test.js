import {describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick} from 'vue';
import ElementPlus, {ID_INJECTION_KEY} from 'element-plus';

/**
 * A multi-parcel label purchase stores every parcel in meta.parcels. The
 * details row lists each parcel's tracking number and label; the first parcel
 * keeps the shipment's own label link.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    globalThis.window.fluentCartRestVars = {rest: {url: 'https://shop.test/wp-json/fluent-cart/v2', nonce: 'n1'}};
});

const clipboard = vi.hoisted(() => ({copy: vi.fn()}));

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
vi.mock('@/utils/Clipboard', () => ({default: clipboard}));

import ShipmentRecord from '@/Modules/Orders/Components/ShipmentRecord.vue';
import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';

const stub = {render: () => null};

const mountRow = async (shipment) => {
    const root = new FakeNode('table');
    const Host = defineComponent({setup: () => () => h(ShipmentRecord, {shipment, orderId: 7, currency: 'USD'})});
    renderer.createApp(Host)
        .use(ElementPlus)
        .component('ElDropdown', stub)
        .component('ElPopover', stub)
        .provide(ID_INJECTION_KEY, {prefix: 1, current: 0})
        .mount(root);
    await nextTick();
    return root;
};

const storedLabelHref = 'https://shop.test/wp-json/fluent-cart/v2/orders/7/shipments/9/label?_wpnonce=n1';

const shippo = (meta) => ({
    id: 9,
    status: 'dispatched',
    carrier_slug: 'shippo',
    carrier_name: 'Shippo',
    tracking_number: 'TRK-1',
    tracking_url: 'https://track.test/TRK-1',
    has_label: true,
    items: [],
    meta: {transaction_id: 'tx1,tx2,tx3', label_url: 'https://labels.test/1.pdf', ...meta},
});

const threeParcels = [
    {transaction_id: 'tx1', tracking_number: 'TRK-1', tracking_url: 'https://track.test/TRK-1', label_url: 'https://labels.test/1.pdf'},
    {transaction_id: 'tx2', tracking_number: 'TRK-2', tracking_url: 'https://track.test/TRK-2', label_url: 'https://labels.test/2.pdf'},
    {transaction_id: 'tx3', tracking_number: '', tracking_url: 'javascript:alert(1)', label_url: 'javascript:alert(1)'},
];

const labelLink = item => byClass(item, 'fct-shipment-parcels__label')[0];
const trackingLink = item => byClass(item, 'fct-shipment-tracking__action').find(node => node.tagName === 'a');

describe('Shipment parcels', () => {
    it('lists each parcel with its tracking number and label link', async () => {
        const root = await mountRow(shippo({parcels: threeParcels}));
        const items = byClass(root, 'fct-shipment-parcels__item');

        expect(items).toHaveLength(3);
        expect(items.map(item => byClass(item, 'fct-shipment-parcels__name')[0].textContent)).toEqual(['Parcel 1', 'Parcel 2', 'Parcel 3']);

        // The first parcel keeps the stored PDF; the others link to their carrier-hosted labels.
        expect(labelLink(items[0]).attrs.href).toBe(storedLabelHref);
        expect(labelLink(items[1]).attrs.href).toBe('https://labels.test/2.pdf');
        expect(labelLink(items[1]).attrs['aria-label']).toBe('Download label for parcel 2');

        expect(items[1].textContent).toContain('TRK-2');
        expect(trackingLink(items[1]).attrs.href).toBe('https://track.test/TRK-2');
    });

    it('drops a parcel link that is not http(s) and marks a missing tracking number', async () => {
        const root = await mountRow(shippo({parcels: threeParcels}));
        const third = byClass(root, 'fct-shipment-parcels__item')[2];

        expect(labelLink(third)).toBeUndefined();
        expect(byClass(third, 'fct-shipment-tracking')).toHaveLength(0);
        expect(third.textContent).toContain('No tracking number');
    });

    it('copies a parcel tracking number', async () => {
        const root = await mountRow(shippo({parcels: threeParcels}));
        const second = byClass(root, 'fct-shipment-parcels__item')[1];
        const copy = byClass(second, 'fct-shipment-tracking__action').find(node => node.tagName === 'button');

        expect(copy.attrs['aria-label']).toBe('Copy tracking number for parcel 2');
        copy.listeners.click();
        expect(clipboard.copy).toHaveBeenCalledWith('TRK-2');
    });

    it('keeps the shipment label download for a multi-parcel shipment', async () => {
        const root = await mountRow(shippo({parcels: threeParcels}));
        const details = byClass(root, 'fct-shipments-table__details')[0];
        const links = details.findAll(node => node.tagName === 'a' && node.attrs.href === storedLabelHref);

        // The details button plus the first parcel's link.
        expect(links).toHaveLength(2);
    });

    it('lists no parcels for a single-label shipment', async () => {
        for (const meta of [{}, {parcels: threeParcels.slice(0, 1)}, {parcels: 'tx1'}]) {
            const root = await mountRow(shippo(meta));
            expect(byClass(root, 'fct-shipment-parcels')).toHaveLength(0);
        }
    });
});
