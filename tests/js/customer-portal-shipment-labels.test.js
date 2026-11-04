import {describe, expect, it, vi} from 'vitest';

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    globalThis.window.fluentcart_customer_profile_vars = {datei18: {}};
});

vi.mock('@/utils/support/Str', () => ({default: {headline: (text) => text}}));

import {
    defaultOpenIds,
    deliverySummary,
    expectedText,
    itemDeliveryStates,
    lastUpdate,
    shipmentLabel,
    shipmentStep,
} from '../../resources/public/customer-profile/utils/shipmentLabels.js';
import statusLabel from '../../resources/public/customer-profile/utils/statusLabels.js';

const shipment = (id, status, shippedAt, statusDate = '', items = []) => ({
    id, status, status_label: 'Admin ' + status, shipped_at: shippedAt, tracking_status_date: statusDate, items,
});

describe('customer shipment labels', () => {
    it('uses customer words, and the server label for a status added by a filter', () => {
        expect(shipmentLabel(shipment(1, 'dispatched'))).toBe('Shipped');
        expect(shipmentLabel(shipment(1, 'in_transit'))).toBe('On the way');
        expect(shipmentLabel(shipment(1, 'delivered'))).toBe('Delivered');
        expect(shipmentLabel(shipment(1, 'failed'))).toBe('Delivery problem');
        expect(shipmentLabel(shipment(1, 'returned'))).toBe('Returned to the store');
        expect(shipmentLabel(shipment(1, 'held_at_customs'))).toBe('Admin held_at_customs');
    });

    it('gives progress steps only to the three normal statuses', () => {
        expect(['dispatched', 'in_transit', 'delivered', 'failed', 'returned', 'held_at_customs']
            .map(status => shipmentStep(shipment(1, status)))).toEqual([1, 2, 3, 0, 0, 0]);
    });

    it('takes the carrier update as the last update, else the shipped date', () => {
        expect(lastUpdate(shipment(1, 'in_transit', '2026-10-29 11:20:00', '2026-11-02 07:48:00'))).toBe('2026-11-02 07:48:00');
        expect(lastUpdate(shipment(1, 'dispatched', '2026-11-01 17:05:00'))).toBe('2026-11-01 17:05:00');
    });

    it('labels the order shipping statuses the header badge shows', () => {
        expect(statusLabel('partially_shipped')).toBe('Partially Shipped');
        expect(statusLabel('delivered')).toBe('Delivered');
        expect(statusLabel('unshippable')).toBe('Unshippable');
    });
});

describe('shipment accordion default', () => {
    it('opens the shipment with the newest update', () => {
        expect(defaultOpenIds([
            shipment(47, 'in_transit', '2026-10-29 11:20:00', '2026-11-02 07:48:00'),
            shipment(48, 'dispatched', '2026-11-01 17:05:00'),
        ])).toEqual([47]);
    });

    it('also opens every problem, so an older one is not hidden', () => {
        expect(defaultOpenIds([
            shipment(1, 'failed', '2026-10-20 10:00:00', '2026-10-22 10:00:00'),
            shipment(2, 'delivered', '2026-10-27 09:30:00', '2026-10-31 14:05:00'),
            shipment(3, 'returned', '2026-10-21 10:00:00'),
        ]).sort()).toEqual([1, 2, 3]);
    });
});

describe('summary delivery lines', () => {
    it('lists each shipment an item is in, and the units not shipped yet', () => {
        const states = itemDeliveryStates([
            shipment(47, 'in_transit', '', '', [{order_item_id: 11, qty: 1}, {order_item_id: 12, qty: 2}]),
            shipment(48, 'failed', '', '', [{order_item_id: 12, qty: 1}]),
        ], [{order_item_id: 12, qty: 1}, {order_item_id: 13, qty: 1}]);

        expect(states[11]).toEqual([{text: 'Shipment 1 · On the way', tone: 'progress'}]);
        expect(states[12]).toEqual([
            {text: 'Shipment 1 · On the way', tone: 'progress'},
            {text: 'Shipment 2 · Delivery problem', tone: 'danger'},
            {text: 'Not shipped yet', tone: 'waiting'},
        ]);
        expect(states[13]).toEqual([{text: 'Not shipped yet', tone: 'waiting'}]);
    });

    it('sums a split order by status for Purchase History, with the units still to ship', () => {
        const shipped = [
            {status: 'in_transit', carrier_name: 'UPS', items: [{qty: 3}, {qty: 1}]},
            {status: 'dispatched', carrier_name: 'USPS', items: [{qty: 1}]},
        ];

        expect(deliverySummary(shipped, [{qty: 1}])).toEqual({type: 'partially_shipped', text: 'Partly shipped', line: '4 on the way · 1 shipped · 1 not shipped yet'});
        expect(deliverySummary(shipped, []).text).toBe('Shipped in parts');
    });

    it('adds up shipments that share a status', () => {
        const shipped = [
            {status: 'delivered', items: [{qty: 2}]},
            {status: 'in_transit', items: [{qty: 1}]},
            {status: 'delivered', items: [{qty: 1}]},
        ];

        expect(deliverySummary(shipped, []).line).toBe('3 delivered · 1 on the way');
    });

    it('shows one shipment as its status and carrier, and nothing without a shipment', () => {
        expect(deliverySummary([{status: 'delivered', carrier_name: 'USPS', items: [{qty: 2}]}], [])).toEqual({type: 'delivered', text: 'Delivered', line: 'USPS'});
        expect(deliverySummary([], [{qty: 1}])).toBeNull();
    });

    it('names a status added by a filter with the server label', () => {
        const held = [{status: 'held', status_label: 'Held at customs', items: [{qty: 3}]}, {status: 'delivered', items: [{qty: 1}]}];

        expect(deliverySummary(held, []).line).toBe('3 Held at customs · 1 delivered');
    });

    it('says out for delivery, and when a parcel is expected', () => {
        const ofd = {status: 'in_transit', tracking_substatus: 'out_for_delivery', tracking_eta: '2026-11-04 20:00:00'};

        expect(shipmentLabel(ofd)).toBe('Out for delivery');
        expect(expectedText(ofd)).toBe('Expected today');
        expect(expectedText({status: 'in_transit', tracking_eta: '2026-11-04 20:00:00'})).toMatch(/^Expected .+/);
        expect(expectedText({status: 'in_transit', tracking_eta: ''})).toBe('');
        expect(expectedText({status: 'delivered', tracking_eta: '2026-11-04 20:00:00'})).toBe('');
        expect(expectedText({status: 'failed', tracking_substatus: 'out_for_delivery'})).toBe('');
    });

    it('counts out for delivery apart, and adds the expected date to one shipment', () => {
        const split = [
            {status: 'in_transit', tracking_substatus: 'out_for_delivery', items: [{qty: 3}]},
            {status: 'in_transit', items: [{qty: 1}]},
        ];
        expect(deliverySummary(split, []).line).toBe('3 out for delivery · 1 on the way');

        const one = deliverySummary([{status: 'in_transit', carrier_name: 'USPS', tracking_eta: '2026-11-04 20:00:00', items: [{qty: 1}]}], []);
        expect(one.text).toBe('On the way');
        expect(one.line).toMatch(/^USPS · expected .+/);
        expect(deliverySummary([{status: 'in_transit', carrier_name: 'UPS', tracking_substatus: 'out_for_delivery', items: [{qty: 1}]}], []).line).toBe('UPS · expected today');
    });
});
