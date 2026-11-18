import {describe, expect, it} from 'vitest';
import {defaultShipmentCarrier, orderCarrierSlug} from '@/Modules/Orders/Components/shipmentCarrierDefault';

const manual = {slug: 'manual', name: 'Manual', is_enabled: true, supports_labels: false, supports_rates: true};
const shippo = {slug: 'shippo', name: 'Shippo', is_enabled: true, supports_labels: true, supports_rates: true};
const carriers = [manual, shippo];

const shippoOrder = {checkout_shipping: {carrier: 'shippo'}, config: {shipping_carrier: 'shippo'}};

describe('orderCarrierSlug', () => {
    it('reads the checkout carrier, falling back to the order config', () => {
        expect(orderCarrierSlug(shippoOrder)).toBe('shippo');
        expect(orderCarrierSlug({config: {shipping_carrier: 'shippo'}})).toBe('shippo');
        expect(orderCarrierSlug({config: '{"shipping_carrier":"shippo"}'})).toBe('shippo');
        expect(orderCarrierSlug({config: 'not json'})).toBe('');
        expect(orderCarrierSlug({checkout_shipping: null, config: []})).toBe('');
        expect(orderCarrierSlug(null)).toBe('');
    });
});

describe('defaultShipmentCarrier', () => {
    it('starts on the carrier the customer paid for', () => {
        expect(defaultShipmentCarrier(shippoOrder, carriers, 'manual')).toBe('shippo');
    });

    it('keeps Manual when the order has no carrier or a manual one', () => {
        expect(defaultShipmentCarrier({}, carriers, 'manual')).toBe('manual');
        expect(defaultShipmentCarrier({config: {shipping_carrier: 'manual'}}, carriers, 'manual')).toBe('manual');
    });

    it('keeps Manual when the order carrier can no longer quote or buy labels', () => {
        const disabled = [manual, {...shippo, is_enabled: false, supports_labels: false, supports_rates: false}];
        const noLabels = [manual, {...shippo, supports_labels: false, supports_rates: false}];

        expect(defaultShipmentCarrier(shippoOrder, disabled, 'manual')).toBe('manual');
        expect(defaultShipmentCarrier(shippoOrder, noLabels, 'manual')).toBe('manual');
        expect(defaultShipmentCarrier(shippoOrder, [manual], 'manual')).toBe('manual');
    });

    it('accepts a rates-only order carrier', () => {
        const ratesOnly = [manual, {...shippo, supports_labels: false}];

        expect(defaultShipmentCarrier(shippoOrder, ratesOnly, 'manual')).toBe('shippo');
    });

    it('falls back to the first carrier when the fallback is not offered', () => {
        expect(defaultShipmentCarrier({}, [shippo], 'manual')).toBe('shippo');
        expect(defaultShipmentCarrier({}, [], 'manual')).toBe('manual');
    });
});
