import {beforeAll, beforeEach, describe, expect, it, vi} from 'vitest';

const currencyConfig = vi.hoisted(() => ({
    signs: {USD: '$', EUR: '€', JPY: '¥'},
    shop: {
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
            if (key === 'shop.currency_sign') {
                return currencyConfig.shop.currency_sign;
            }
            if (key === 'shop.currency_position') {
                return currencyConfig.shop.currency_position;
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
    translateNumber: (number) => String(number),
}));

vi.mock('@/utils/http/Rest', () => ({
    default: {
        get: (route, data) => Promise.resolve({route, data}),
    },
}));

class MemoryStorage {
    constructor() {
        this.values = new Map();
    }

    getItem(key) {
        return this.values.has(key) ? this.values.get(key) : null;
    }

    setItem(key, value) {
        this.values.set(key, String(value));
    }

    removeItem(key) {
        this.values.delete(key);
    }

    clear() {
        this.values.clear();
    }
}

let CurrencyFormatter;
let Model;
let Storage;

describe('admin storage, money, and date utilities', () => {
    beforeAll(async () => {
        vi.stubGlobal('navigator', {language: 'en-US', languages: ['en-US']});
        CurrencyFormatter = (await import('../../resources/admin/utils/support/CurrencyFormatter.js')).default;
        Model = (await import('../../resources/admin/utils/model/Model.js')).default;
    });

    beforeEach(async () => {
        vi.resetModules();
        vi.stubGlobal('localStorage', new MemoryStorage());
        vi.stubGlobal('window', {
            fluentCartAdminApp: {
                app_config: {version: '2.4.0-beta'},
                max_upload_size: 10485760,
            },
        });
        Storage = (await import('../../resources/admin/utils/Storage.js')).default;
        currencyConfig.shop.currency_sign = '$';
        currencyConfig.shop.currency_position = 'before';
        currencyConfig.shop.decimal_separator = 'dot';
        CurrencyFormatter.setLocale();
    });

    it('versions local storage keys and proxies exact reactive store state', async () => {
        Storage.set('filters', {status: ['paid'], minimum: 1001});
        Storage.set('collapsed', false);
        localStorage.setItem('fcart-2_4_0_beta_broken', '{not-json');

        expect([...localStorage.values.entries()]).toEqual([
            ['fcart-2_4_0_beta_filters', '{"status":["paid"],"minimum":1001}'],
            ['fcart-2_4_0_beta_collapsed', 'false'],
            ['fcart-2_4_0_beta_broken', '{not-json'],
        ]);
        expect(Storage.get('filters')).toEqual({status: ['paid'], minimum: 1001});
        expect(Storage.get('collapsed', true)).toBe(false);
        expect(Storage.get('missing', 'fallback')).toBe('fallback');
        expect(Storage.get('broken', 'safe-default')).toBe('safe-default');
        expect(Storage.serverMaxUploadSize()).toBe(10485760);
        expect([
            Storage.readableFileSizeFromBytes(0),
            Storage.readableFileSizeFromBytes(1536),
            Storage.readableFileSizeFromBytes(10485760),
            Storage.readableFileSizeFromBytes(null),
        ]).toEqual(['0 B', '1.50 KB', '10.00 MB', '']);

        Storage.remove('collapsed');
        expect(Storage.get('collapsed', 'removed')).toBe('removed');
        Storage.clear();
        expect(localStorage.values.size).toBe(0);

        class Phase25Store extends Model {
            data = {
                count: 1,
                label: 'orders',
            };

            getLabelAttribute(value) {
                return value.toUpperCase();
            }

            setCountAttribute(property, value) {
                this.data[property] = value * 2;
            }
        }

        const store = Phase25Store.init();
        expect(store.count).toBe(1);
        expect(store.label).toBe('ORDERS');
        store.count = 3;
        store.extra = 1001;
        expect({count: store.count, extra: store.extra}).toEqual({count: 6, extra: 1001});
        await expect(store.$get('orders', {page: 2})).resolves.toEqual({
            route: 'orders',
            data: {page: 2},
        });
    });

    it('formats integer cents and timezone-bearing dates at exact boundaries', async () => {
        expect(CurrencyFormatter.formatNumber(12345, true, false, 'USD')).toBe('$123.45');
        expect(CurrencyFormatter.formatNumber(-9876, false)).toBe('-98.76');
        expect(CurrencyFormatter.formatNumber(0, true, true, 'USD')).toBe('');
        expect(CurrencyFormatter.formatScaled(123456, true, false, 'USD')).toBe('$1.23K');
        expect(CurrencyFormatter.formatBulk([0, 12345], false, 'EUR')).toEqual(['€0', '€123.45']);

        currencyConfig.shop.currency_position = 'after';
        expect(CurrencyFormatter.formatNumber(1001, true, false, 'EUR')).toBe('10.01€');

        const DateSupport = (await import('../../resources/admin/utils/support/Date.js')).default;
        expect(DateSupport.withTimezone('2026-07-31T10:30:00-04:00'))
            .toBe('2026-07-31T14:30:00+00:00');
        expect(DateSupport.withTimezone('not-a-date')).toBeNull();
    });

    it('formats zero-decimal, negative, locale-specific, and large money strings exactly', () => {
        expect(CurrencyFormatter.formatNumber(12300, true, false, 'JPY')).toBe('¥123');
        expect(CurrencyFormatter.formatNumber(-12300, true, false, 'JPY')).toBe('¥-123');
        expect(CurrencyFormatter.formatNumber(-1001, true, false, 'USD')).toBe('$-10.01');
        expect(CurrencyFormatter.formatNumber(987654321099, true, false, 'USD'))
            .toBe('$9,876,543,210.99');
        expect(CurrencyFormatter.formatNumber(9007199254740900, false)).toBe('90,071,992,547,409');
        expect([
            CurrencyFormatter.formatScaled(100000, true, false, 'USD'),
            CurrencyFormatter.formatScaled(100000000, true, false, 'USD'),
            CurrencyFormatter.formatScaled(100000000000, true, false, 'USD'),
            CurrencyFormatter.formatScaled(-100000, true, false, 'USD'),
        ]).toEqual(['$1K', '$1M', '$1B', '$-1K']);

        currencyConfig.shop.currency_position = 'after';
        currencyConfig.shop.decimal_separator = 'comma';
        CurrencyFormatter.setLocale();
        expect(CurrencyFormatter.formatNumber(123456, true, false, 'EUR')).toBe('1.234,56€');
    });

    it('preserves explicit offsets across DST and leap-day date boundaries', async () => {
        const DateSupport = (await import('../../resources/admin/utils/support/Date.js')).default;

        expect([
            DateSupport.withTimezone('2026-03-08T01:30:00-05:00'),
            DateSupport.withTimezone('2026-03-08T03:30:00-04:00'),
            DateSupport.withTimezone('2024-02-29T23:59:59Z'),
        ]).toEqual([
            '2026-03-08T06:30:00+00:00',
            '2026-03-08T07:30:00+00:00',
            '2024-02-29T23:59:59+00:00',
        ]);
    });

    it('KNOWN-FAILURE — Model store proxy preserves false, zero, and empty-string state', () => {
        class Phase31FalsyStore extends Model {
            data = {
                enabled: false,
                count: 0,
                label: '',
            };
        }

        const store = Phase31FalsyStore.init();
        const observed = {
            enabled: store.enabled,
            count: store.count,
            label: store.label,
        };
        const expected = {enabled: false, count: 0, label: ''};

        if (JSON.stringify(observed) === JSON.stringify(expected)) {
            throw new Error('KNOWN-FAILURE unexpectedly passed; remove FIX-PLAN #31 and assert the store values normally.');
        }

        expect(observed).toEqual({enabled: undefined, count: undefined, label: undefined});
    });
});
