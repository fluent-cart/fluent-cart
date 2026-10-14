import {beforeAll, describe, expect, it, vi} from 'vitest';

// Decimal places must come from the currency being rendered (currency_decimals
// map), not the store currency. Regression cases: a JPY amount showed ".45"
// cents on a USD store, and formatScaled passed the resolved SIGN back into
// formatNumber's currencyName parameter, so per-currency lookups always missed.
const currencyConfig = vi.hoisted(() => ({
    signs: {USD: '$', EUR: '€', JPY: '¥'},
    decimals: {USD: 2, EUR: 2, JPY: 0, BHD: 3},
    shop: {
        currency: 'USD',
        currency_sign: '$',
        currency_position: 'before',
        decimal_separator: 'dot',
    },
    wpLocale: 'en_US',
}));

vi.mock('@/utils/Config/AppConfig', () => ({
    default: {
        get(key, defaultValue) {
            if (key === 'currency_signs') {
                return currencyConfig.signs;
            }
            if (key === 'currency_decimals') {
                return currencyConfig.decimals;
            }
            if (key === 'shop.currency_sign') {
                return currencyConfig.shop.currency_sign;
            }
            if (key === 'shop.currency_position') {
                return currencyConfig.shop.currency_position;
            }
            if (key === 'shop.currency') {
                return currencyConfig.shop.currency;
            }
            if (key === 'shop') {
                return currencyConfig.shop;
            }
            if (key === 'wp_locale') {
                return currencyConfig.wpLocale;
            }
            return defaultValue;
        },
        onShopUpdate(callback) {
            callback();
        },
    },
}));

vi.mock('@/utils/translator/Translator', () => ({
    default: (string) => string,
    translateNumber: (value) => value,
}));

let CurrencyFormatter;

describe('per-currency decimal places', () => {
    beforeAll(async () => {
        CurrencyFormatter = (await import('../../resources/admin/utils/support/CurrencyFormatter.js')).default;
        CurrencyFormatter.setLocale();
    });

    it('renders a zero-decimal currency without cent digits', () => {
        expect(CurrencyFormatter.formatNumber(740000, true, false, 'JPY')).toBe('¥7,400');
        expect(CurrencyFormatter.formatNumber(740050, true, false, 'JPY')).toBe('¥7,401');
    });

    it('keeps two decimals for two-decimal currencies on the same store', () => {
        expect(CurrencyFormatter.formatNumber(12345, true, false, 'USD')).toBe('$123.45');
        expect(CurrencyFormatter.formatNumber(12345, true, false, 'EUR')).toBe('€123.45');
    });

    it('falls back to 2 decimals for a currency missing from the map', () => {
        expect(CurrencyFormatter.formatNumber(12345, true, false, 'XYZ')).toBe('$123.45');
    });

    it('uses the store currency decimals when no currency is passed', () => {
        expect(CurrencyFormatter.formatNumber(12345)).toBe('$123.45');
    });

    it('formatScaled threads the currency code, not the resolved sign', () => {
        // scaled values keep their fraction digits even for zero-decimal currencies
        expect(CurrencyFormatter.formatScaled(740000, true, false, 'JPY')).toBe('¥7.4K');
        // unscaled fallthrough uses per-currency decimals
        expect(CurrencyFormatter.formatScaled(74000, true, false, 'JPY')).toBe('¥740');
    });

    it('formatBulk threads the currency code through', () => {
        expect(CurrencyFormatter.formatBulk([740000], false, 'JPY')).toEqual(['¥7,400']);
    });
});
