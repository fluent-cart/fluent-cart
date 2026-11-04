import {describe, expect, it, vi} from 'vitest';

/**
 * A label billed in another currency than the order's shows an approximate
 * order-currency figure next to the billed amount, and totals never add
 * amounts in different currencies together.
 */

const config = vi.hoisted(() => ({
    currency_signs: {USD: '$', EUR: '€', BDT: '৳', JPY: '¥'},
    currency_decimals: {USD: 2, EUR: 2, BDT: 2, JPY: 0},
    'shop.currency': 'USD',
    'shop.currency_sign': '$',
    'shop.currency_position': 'before',
    shop: {decimal_separator: 'dot'},
}));

vi.mock('@/utils/Config/AppConfig', () => ({
    default: {get: (key, fallback) => (key in config ? config[key] : fallback), onShopUpdate: () => {}},
}));
vi.mock('@/utils/translator/Translator', () => ({
    default: (text, ...args) => text.replace(/%(\d)\$s/g, (match, index) => args[index - 1]),
    translateNumber: (value) => value,
}));

import {
    formatOrderCurrencyEstimate,
    formatShipmentCostTotal,
    withOrderCurrencyEstimate,
} from '@/Modules/Orders/Components/shipmentCost';

const shipment = (cost, costCurrency, estimate = null) => ({
    meta: costCurrency ? {cost, cost_currency: costCurrency} : {cost},
    order_currency_estimate: estimate,
});

describe('the order-currency estimate', () => {
    it('drops the minor units of an estimate of 100 or more', () => {
        expect(formatOrderCurrencyEstimate(1104000, 'BDT')).toBe('৳11,040');
        expect(formatOrderCurrencyEstimate(407640, 'BDT')).toBe('৳4,076');
        expect(formatOrderCurrencyEstimate(407660, 'BDT')).toBe('৳4,077');
    });

    it('keeps the minor units of a small estimate', () => {
        expect(formatOrderCurrencyEstimate(4560, 'EUR')).toBe('€45.60');
    });

    it('is empty without an estimate or an order currency', () => {
        expect(formatOrderCurrencyEstimate(null, 'BDT')).toBe('');
        expect(formatOrderCurrencyEstimate(0, 'BDT')).toBe('');
        expect(formatOrderCurrencyEstimate(1104000, '')).toBe('');
    });

    it('follows the billed cost, marked approximate', () => {
        expect(withOrderCurrencyEstimate('$92.00', 1104000, 'BDT')).toBe('$92.00 (≈ ৳11,040)');
        expect(withOrderCurrencyEstimate('$92.00', null, 'BDT')).toBe('$92.00');
        expect(withOrderCurrencyEstimate('', 1104000, 'BDT')).toBe('');
    });
});

describe('the total shipping cost', () => {
    it('adds costs in one currency', () => {
        expect(formatShipmentCostTotal([shipment(50000), shipment(25000, 'BDT')], 'BDT')).toBe('৳750.00');
    });

    it('estimates a total billed in another currency', () => {
        expect(formatShipmentCostTotal([shipment(9200, 'USD', 1104000), shipment(800, 'USD', 96000)], 'BDT'))
            .toBe('$100.00 (≈ ৳12,000)');
    });

    it('never adds two currencies together; the order currency comes first', () => {
        expect(formatShipmentCostTotal([shipment(9200, 'USD', 1104000), shipment(50000)], 'BDT'))
            .toBe('৳500.00 + $92.00 (≈ ৳11,040)');
    });

    it('leaves a currency unestimated when one of its shipments has no estimate', () => {
        expect(formatShipmentCostTotal([shipment(9200, 'USD', 1104000), shipment(800, 'USD')], 'BDT')).toBe('$100.00');
    });

    it('shows no estimate when the label is in the order currency', () => {
        expect(formatShipmentCostTotal([shipment(9200, 'USD', 1104000)], 'USD')).toBe('$92.00');
    });

    it('is empty when no shipment has a cost', () => {
        expect(formatShipmentCostTotal([shipment(0, 'USD'), {meta: {}}, {}], 'BDT')).toBe('');
        expect(formatShipmentCostTotal([], 'BDT')).toBe('');
    });
});
