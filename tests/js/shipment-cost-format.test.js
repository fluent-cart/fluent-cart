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

import CurrencyFormatter from '@/utils/support/CurrencyFormatter';
import {formatShipmentCost} from '@/Modules/Orders/Components/shipmentCost';

describe('shipment costs keep the currency decimals', () => {
    it('never drops a trailing zero from a rate', () => {
        expect(formatShipmentCost(660, 'USD')).toBe('$6.60');
        expect(formatShipmentCost(930, 'USD')).toBe('$9.30');
        expect(formatShipmentCost(700, 'EUR')).toBe('€7.00');
        expect(formatShipmentCost(1200, 'JPY')).toBe('¥12');
        expect(formatShipmentCost(0, 'USD')).toBe('');
    });

    it('leaves the shared formatNumber output as it was', () => {
        expect(CurrencyFormatter.formatNumber(660, true, false, 'USD')).toBe('$6.6');
        expect(CurrencyFormatter.formatNumber(2300, true, false, 'EUR')).toBe('€23');
    });
});

describe('fixed order amounts always show the currency decimals', () => {
    it('keeps trailing zeros on whole amounts', () => {
        expect(CurrencyFormatter.formatFixed(2300, true, false, 'EUR')).toBe('€23.00');
        expect(CurrencyFormatter.formatFixed(2500, true, false, 'USD')).toBe('$25.00');
        expect(CurrencyFormatter.formatFixed(0, true, false, 'EUR')).toBe('€0.00');
    });

    it('never shows half a fraction', () => {
        expect(CurrencyFormatter.formatFixed(660, true, false, 'EUR')).toBe('€6.60');
        expect(CurrencyFormatter.formatFixed(940, true, false, 'EUR')).toBe('€9.40');
        expect(CurrencyFormatter.formatFixed(4680, true, false, 'EUR')).toBe('€46.80');
        expect(CurrencyFormatter.formatFixed(2345, true, false, 'USD')).toBe('$23.45');
        expect(CurrencyFormatter.formatFixed(2350, false, false, 'USD')).toBe('23.50');
        expect(CurrencyFormatter.formatFixed(12300, true, false, 'JPY')).toBe('¥123');
    });
});
