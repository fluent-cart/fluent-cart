import {describe, expect, it, vi} from 'vitest';

const config = vi.hoisted(() => ({
    currency_signs: {USD: '$', EUR: '€', JPY: '¥'},
    currency_decimals: {USD: 2, EUR: 2, JPY: 0},
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

import {shippingQuoteNote} from '@/Modules/Orders/shippingQuoteNote';

const strip = (value) => value.replace(/[\u2068\u2069]/g, '');

const eurOrder = (overrides = {}) => ({
    currency: 'EUR',
    checkout_shipping: {carrier: 'shippo'},
    config: {
        shipping_carrier: 'shippo',
        shipping_quote: {carrier: 'shippo', rate_id: 'r1', source_cost: 666, source_currency: 'USD'},
        fx: {currency: 'EUR', base_currency: 'USD', rate: '0.920000000000', source: 'manual'},
    },
    ...overrides,
});

describe('shippingQuoteNote', () => {
    it('shows the carrier quote and the frozen rate', () => {
        const note = shippingQuoteNote(eurOrder());
        expect(strip(note.carrierRate)).toBe('Carrier rate: $6.66 USD');
        expect(strip(note.exchangeRate)).toBe('Exchange rate 1 USD = 0.92 EUR');
    });

    it('reads a JSON-string config', () => {
        const order = eurOrder();
        order.config = JSON.stringify(order.config);
        expect(strip(shippingQuoteNote(order).carrierRate)).toBe('Carrier rate: $6.66 USD');
    });

    it('is hidden when the order is in the carrier currency', () => {
        const order = eurOrder();
        order.currency = 'USD';
        order.config.fx = {currency: 'USD', base_currency: 'USD', rate: '1', source: 'base'};
        expect(shippingQuoteNote(order)).toBeNull();
    });

    it('is hidden without a quote, or when the quote is for another carrier', () => {
        const noQuote = eurOrder();
        delete noQuote.config.shipping_quote;
        expect(shippingQuoteNote(noQuote)).toBeNull();

        const changed = eurOrder({checkout_shipping: {carrier: 'shipstation'}});
        expect(shippingQuoteNote(changed)).toBeNull();
        expect(shippingQuoteNote(null)).toBeNull();
    });

    it('leaves out an untrusted or missing rate', () => {
        const unknown = eurOrder();
        unknown.config.fx = {currency: 'EUR', base_currency: 'USD', rate: '1.000000000000', source: 'unknown'};
        expect(shippingQuoteNote(unknown).exchangeRate).toBe('');

        const noFx = eurOrder();
        delete noFx.config.fx;
        const note = shippingQuoteNote(noFx);
        expect(strip(note.carrierRate)).toBe('Carrier rate: $6.66 USD');
        expect(note.exchangeRate).toBe('');
    });
});
