import {describe, expect, it} from 'vitest';
import {reservedSentence} from './support/reserved-words.js';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

import {shipmentHasCarrierLabel, shipmentLabelHref} from '@/Modules/Orders/Components/shipmentLabel';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

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

describe('Delete shipment confirm', () => {
    const source = fs.readFileSync(path.join(root, 'resources/admin/Modules/Orders/Components/ShipmentRecord.vue'), 'utf8');
    const confirm = source.match(/<div v-if="confirmingDelete"[\s\S]*?<\/div>\s*<\/div>/)[0];

    it('says the carrier label is voided when there is one', () => {
        expect(confirm).toContain('{{ deleteConfirmText }}');
        // The long sentence ships under its reservedWords.json key
        expect(source).toContain("translate('Order_Delete_Shipment', carrierLabel.value)");
        expect(reservedSentence('Order_Delete_Shipment')).toBe('Delete this shipment? Its %1$s label will be voided, and the carrier refunds it if eligible.');
        expect(source).toContain("translate('Delete this shipment?')");
    });

    it('widens the menu while it asks, so the label warning fits', () => {
        expect(source).toContain(':width="confirmingDelete ? (hasCarrierLabel ? 290 : 250) : 220"');
    });
});

describe('Download label link', () => {
    const source = fs.readFileSync(path.join(root, 'resources/admin/Modules/Orders/Components/ShipmentRecord.vue'), 'utf8');

    it('points at the label route with the REST nonce, so a plain tab can open it', () => {
        expect(shipmentLabelHref({url: 'https://shop.test/wp-json/fluent-cart/v2', nonce: 'n0nce'}, 12, 34))
            .toBe('https://shop.test/wp-json/fluent-cart/v2/orders/12/shipments/34/label?_wpnonce=n0nce');
        expect(shipmentLabelHref({url: 'https://shop.test/?rest_route=/fluent-cart/v2', nonce: 'a b'}, 1, 2))
            .toBe('https://shop.test/?rest_route=/fluent-cart/v2/orders/1/shipments/2/label&_wpnonce=a%20b');
        expect(shipmentLabelHref(null, 1, 2)).toBe('');
        expect(shipmentLabelHref({url: '', nonce: 'x'}, 1, 2)).toBe('');
    });

    it('is a real link that opens in a new tab, never a button', () => {
        const block = source.match(/<div v-if="shipment.has_label \|\| labelUrl"[\s\S]*?<\/div>/)[0];
        expect(block).not.toContain('<button');
        expect(block).not.toContain('@click');
        expect(block).toContain(':href="labelHref"');
        expect(block).toContain('target="_blank"');
        expect(block).toMatch(/rel="noopener[^"]*"/);
        expect(source).not.toContain('downloadLabel');
    });
});
