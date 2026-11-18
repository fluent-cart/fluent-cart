import {describe, expect, it} from 'vitest';
import {shipmentHasCarrierLabel, shipmentLabelHref} from '@/Modules/Orders/Components/shipmentLabel';

describe('shipmentHasCarrierLabel', () => {
    it('is true only for a carrier shipment with a bought label', () => {
        expect(shipmentHasCarrierLabel({carrier_slug: 'shippo', meta: {transaction_id: 'tx_1'}})).toBe(true);
        expect(shipmentHasCarrierLabel({carrier_slug: 'shippo', meta: {}})).toBe(false);
        expect(shipmentHasCarrierLabel({carrier_slug: 'shippo', meta: null})).toBe(false);
        expect(shipmentHasCarrierLabel({carrier_slug: 'manual', meta: {transaction_id: 'tx_1'}})).toBe(false);
        expect(shipmentHasCarrierLabel({carrier_slug: '', meta: {transaction_id: 'tx_1'}})).toBe(false);
        expect(shipmentHasCarrierLabel(null)).toBe(false);
    });
});

describe('Download label link', () => {
    it('points at the label route with the REST nonce, so a plain tab can open it', () => {
        expect(shipmentLabelHref({url: 'https://shop.test/wp-json/fluent-cart/v2', nonce: 'n0nce'}, 12, 34))
            .toBe('https://shop.test/wp-json/fluent-cart/v2/orders/12/shipments/34/label?_wpnonce=n0nce');
        expect(shipmentLabelHref({url: 'https://shop.test/?rest_route=/fluent-cart/v2', nonce: 'a b'}, 1, 2))
            .toBe('https://shop.test/?rest_route=/fluent-cart/v2/orders/1/shipments/2/label&_wpnonce=a%20b');
        expect(shipmentLabelHref(null, 1, 2)).toBe('');
        expect(shipmentLabelHref({url: '', nonce: 'x'}, 1, 2)).toBe('');
    });
});
