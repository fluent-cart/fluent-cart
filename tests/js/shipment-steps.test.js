import {describe, expect, it} from 'vitest';
import {rateHighlights, shipmentStepsReachable} from '@/Modules/Orders/Components/shipmentSteps';

describe('Create shipment steps', () => {
    it('opens Method only once an item ships', () => {
        expect(shipmentStepsReachable({hasItems: false, carriersReady: true, listsMethods: true})).toEqual([true, false, false]);
        expect(shipmentStepsReachable({hasItems: true, carriersReady: true, listsMethods: true})).toEqual([true, true, false]);
    });

    it('needs a picked rate before Review for a label carrier', () => {
        const state = {hasItems: true, carriersReady: true, buysLabel: true, customPrice: true, quoteFailed: true};
        expect(shipmentStepsReachable(state)[2]).toBe(false);
        expect(shipmentStepsReachable({...state, rateSelected: true})[2]).toBe(true);
    });

    it('needs a zone method or Custom price when the carrier lists methods', () => {
        const state = {hasItems: true, carriersReady: true, listsMethods: true};
        expect(shipmentStepsReachable(state)[2]).toBe(false);
        expect(shipmentStepsReachable({...state, rateSelected: true})[2]).toBe(true);
        expect(shipmentStepsReachable({...state, customPrice: true})[2]).toBe(true);
    });

    it('never needs a tracking number for a manual shipment', () => {
        expect(shipmentStepsReachable({hasItems: true, carriersReady: true})[2]).toBe(true);
        // No methods to pick from: a failed or empty quote must not block Manual.
        expect(shipmentStepsReachable({hasItems: true, carriersReady: true, listsMethods: true, quoteFailed: true})[2]).toBe(true);
    });

    it('waits for the carrier list', () => {
        expect(shipmentStepsReachable({hasItems: true, carriersReady: false})[2]).toBe(false);
    });
});

describe('rate highlights', () => {
    const rate = (id, cents, days, currency = 'USD') => ({rate_object_id: id, cost_cents: cents, estimated_days: days, currency});

    it('tags the cheapest and the fastest rate', () => {
        expect(rateHighlights([rate('a', 900, 5), rate('b', 1500, 2), rate('c', 1200, 3)]))
            .toEqual({cheapest: 'a', fastest: 'b'});
    });

    it('tags nothing it cannot tell apart', () => {
        expect(rateHighlights([rate('a', 900, 2)])).toEqual({cheapest: '', fastest: ''});
        expect(rateHighlights([rate('a', 900, 0), rate('b', 900, 0)])).toEqual({cheapest: '', fastest: ''});
    });

    it('never compares prices across currencies', () => {
        expect(rateHighlights([rate('a', 900, 3, 'USD'), rate('b', 500, 2, 'EUR')])).toEqual({cheapest: '', fastest: 'b'});
    });

    it('ignores rates without a delivery estimate for fastest', () => {
        expect(rateHighlights([rate('a', 900, 0), rate('b', 1500, 4), rate('c', 1200, 3)]).fastest).toBe('c');
    });
});
