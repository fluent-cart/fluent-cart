import {afterEach, beforeAll, describe, expect, it, vi} from 'vitest';

vi.mock('@/utils/Config/AppConfig', () => ({
    default: {
        get(key, defaultValue) {
            const config = {
                currency_signs: {USD: '$'},
                currency_decimals: {USD: 2},
                shop: {currency: 'USD', currency_sign: '$', currency_position: 'before', decimal_separator: 'dot'},
                wp_locale: 'en_US',
            };
            return key in config ? config[key] : defaultValue;
        },
        onShopUpdate: (callback) => callback(),
    },
}));

vi.mock('@/utils/translator/Translator', () => ({
    default: (string, ...args) => {
        let next = 0;
        return string.replace(/%(\d+)\$s|%s/g, (match, position) => String(position ? args[position - 1] : args[next++]));
    },
    translateNumber: (value) => value,
}));

let CurrencyFormatter;
const quarter = (current, prevYear = 0) => ({current, prev_year: prevYear, yy_growth: null});
const year2026 = (q1, q2, q3, q4) => ({'Q1-2026': q1, 'Q2-2026': q2, 'Q3-2026': q3, 'Q4-2026': q4});

const narrative = (data, now = '2026-10-13T12:00:00Z', isDate = false) => {
    vi.useFakeTimers({toFake: ['Date']});
    vi.setSystemTime(new Date(now));
    return CurrencyFormatter.generateVolumeSummary(data, isDate)
        .replace(/<span class="fct-custom-tooltip-content">.*?<\/span>/g, '')
        .replace(/<[^>]+>/g, '')
        .replace(/\s+/g, ' ')
        .trim();
};

describe('Overview quarterly narrative', () => {
    beforeAll(async () => {
        CurrencyFormatter = (await import('../../resources/admin/utils/support/CurrencyFormatter.js')).default;
        CurrencyFormatter.setLocale();
    });

    afterEach(() => vi.useRealTimers());

    it('compares against the previous quarter, across the year boundary', () => {
        const q4 = narrative(year2026(quarter(100000), quarter(150000), quarter(200000), quarter(300000)));
        expect(q4).toContain('compared to Q3-2026');
        expect(q4).not.toContain('Q1-2026');

        const q1 = narrative({
            'Q2-2026': quarter(50000), 'Q3-2026': quarter(70000), 'Q4-2026': quarter(400000), 'Q1-2027': quarter(100000),
        }, '2027-02-10T12:00:00Z');
        expect(q1).toContain('compared to Q4-2026');
        expect(q1).not.toContain('Q2-2026');
    });

    it('divides the change by the previous quarter', () => {
        const text = narrative(year2026(quarter(1), quarter(1), quarter(200000), quarter(100000)));
        expect(text).toContain('a decrease of 50.0% compared to Q3-2026');
    });

    it('reads a zero previous quarter as up from nothing, and two zeros as no change', () => {
        expect(narrative(year2026(quarter(1), quarter(1), quarter(0), quarter(250000)))).toContain('up from nothing in Q3-2026');
        expect(narrative(year2026(quarter(1), quarter(1), quarter(0), quarter(0)))).toContain('no change compared to Q3-2026');
    });

    it('names the last sale, or the last two years, when nothing sold', () => {
        expect(narrative(year2026(quarter(0), quarter(0), quarter(0, 80000), quarter(0)))).toBe('No sales since Q3-2025.');
        expect(narrative(year2026(quarter(0), quarter(0), quarter(0), quarter(0)))).toBe('No sales in the last two years.');
    });

    it('prints no percentage when the quarterly prior-year figure is missing, but keeps a real zero', () => {
        const missing = narrative(year2026(quarter(1), quarter(1), quarter(200000), quarter(100000)));
        expect(missing).not.toMatch(/\b0\.0%/);

        const growth = (current) => ({current, prev_year: current, yy_growth: 0});
        const zero = narrative(year2026(quarter(1), quarter(1), growth(200000), growth(100000)));
        expect(zero.match(/\b0\.0%/g)).toHaveLength(2);
    });
});

describe('Overview monthly narrative', () => {
    beforeAll(async () => {
        CurrencyFormatter = (await import('../../resources/admin/utils/support/CurrencyFormatter.js')).default;
        CurrencyFormatter.setLocale();
    });

    afterEach(() => vi.useRealTimers());

    const months = (yoyGrowth) => ({
        '2026-09': {current: 50000, prev: yoyGrowth === null ? null : 50000, yoy_growth: yoyGrowth},
        '2026-10': {current: 20000, prev: null, yoy_growth: null},
    });

    it('prints no percentage when the monthly prior-year figure is missing, but keeps a real zero', () => {
        expect(narrative(months(null), undefined, true)).not.toMatch(/\b0\.0%/);
        expect(narrative(months('0.00'), undefined, true)).toMatch(/\b0\.0%/);
    });

    it('leaves the report payload untouched', () => {
        const data = months('12.50');
        const before = structuredClone(data);
        narrative(data, undefined, true);
        expect(data).toEqual(before);
    });
});
