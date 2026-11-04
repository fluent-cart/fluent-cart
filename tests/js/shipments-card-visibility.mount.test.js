import {describe, expect, it, vi} from 'vitest';
import {defineComponent, h} from 'vue';

/**
 * The Shipments card shows only once a shipment exists, but a pending
 * label-void warning keeps it visible after the last shipment is deleted.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
});

vi.mock('@/utils/translator/Translator', async () => ({default: (await import('./support/reserved-words.js')).withReserved((text, arg) => text.replace('%s', arg))}));
vi.mock('@/Modules/Orders/Components/shipmentCost', () => ({formatShipmentCostTotal: () => ''}));
vi.mock('@/Modules/Orders/Components/ShipmentRecord.vue', () => ({
    default: {name: 'ShipmentRecord', props: ['shipment'], render: () => null},
}));
vi.mock('@/Bits/Components/Alert.vue', () => ({
    default: {name: 'Alert', props: ['content'], render: () => null},
}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/Orders/Components/UnconfirmedLabelNote.vue', () => ({
    default: {name: 'UnconfirmedLabelNote', props: ['entry', 'content', 'orderId'], emits: ['update', 'found', 'not-found', 'stale'], render: () => null},
}));

import Alert from '@/Bits/Components/Alert.vue';
import ShipmentRecord from '@/Modules/Orders/Components/ShipmentRecord.vue';
import * as Card from '@/Bits/Components/Card/Card.js';
import Shipments from '@/Modules/Orders/Components/Shipments.vue';
import UnconfirmedLabelNote from '@/Modules/Orders/Components/UnconfirmedLabelNote.vue';
import {FakeNode, renderer} from './support/fake-dom-renderer.js';

const findByType = (vnode, type, found = []) => {
    if (!vnode) {
        return found;
    }
    if (vnode.type === type) {
        found.push(vnode);
    }
    if (vnode.component) {
        findByType(vnode.component.subTree, type, found);
    }
    if (Array.isArray(vnode.children)) {
        vnode.children.forEach(child => findByType(child, type, found));
    }
    return found;
};

const mountCard = (shipments, voidWarning = '', extra = {}) => {
    const Host = defineComponent({
        setup: () => () => h(Shipments, {order: {id: 7, currency: 'USD', shipments, unconfirmed_shipments: extra.unconfirmed}, voidWarning, ...extra.props}),
    });
    const app = renderer.createApp(Host);
    app.mount(new FakeNode('div'));
    return (type) => findByType(app._instance.vnode, type);
};

describe('Shipments card visibility', () => {
    it('is not rendered when the order has no shipments', () => {
        const find = mountCard([]);
        expect(find(Card.Container)).toHaveLength(0);
    });

    it('is rendered once a shipment exists', () => {
        const find = mountCard([{id: 1, shipped_at: '2026-10-01 10:00:00'}]);
        expect(find(Card.Container)).toHaveLength(1);
        expect(find(ShipmentRecord)).toHaveLength(1);
    });

    it('keeps a pending void warning visible after the last shipment is deleted', () => {
        const find = mountCard([], 'The label could not be voided.');
        const alerts = find(Alert);
        expect(alerts).toHaveLength(1);
        expect(alerts[0].props.content).toBe('The label could not be voided.');
    });

    describe('Unconfirmed label purchase', () => {
        const pending = [{id: 'a1', carrier: 'shippo', carrier_name: 'Shippo', at: 1, can_recheck: true}];

        it('keeps the card up with a note per attempt, before any shipment exists', () => {
            const find = mountCard([], '', {unconfirmed: [...pending, {...pending[0], id: 'a2'}]});
            const notes = find(UnconfirmedLabelNote);

            expect(find(Card.Container)).toHaveLength(1);
            expect(notes).toHaveLength(2);
            expect(notes[0].component.props.entry).toEqual(pending[0]);
            expect(notes[1].component.props.entry.id).toBe('a2');
            expect(notes[0].component.props.content).toContain('Shippo did not confirm a label');
        });

        it('hands the page every list and refetches when a label may have been recorded', () => {
            const onShipmentUpdated = vi.fn();
            const onLabelNotFound = vi.fn();
            const onUnconfirmed = vi.fn();
            const find = mountCard([], '', {unconfirmed: pending, props: {onShipmentUpdated, onLabelNotFound, onUnconfirmed}});
            const note = find(UnconfirmedLabelNote)[0];

            note.props.onUpdate([]);
            note.props.onFound({shipment: {id: 1}});
            note.props.onStale();
            note.props.onNotFound('No label was found at the carrier. You can buy a new one.');

            expect(onUnconfirmed).toHaveBeenCalledWith([]);
            expect(onShipmentUpdated).toHaveBeenCalledTimes(2);
            expect(onLabelNotFound).toHaveBeenCalledWith('No label was found at the carrier. You can buy a new one.');
        });

        it('shows the page-owned "not found" notice', () => {
            const find = mountCard([], '', {props: {recheckNotice: 'No label was found at the carrier. You can buy a new one.'}});
            expect(find(Alert).map(alert => alert.props.content)).toEqual(['No label was found at the carrier. You can buy a new one.']);
        });
    });
});
