import {describe, expect, it} from 'vitest';

import {
    resolveSeparators,
    normalizeTypingValue,
    parseNormalizedValue,
    dollarsToCents,
    csvValueToCents,
    centsToDollarValue,
} from '../../resources/admin/Bits/moneyInput.js';

// The shop's thousands separator is the only locale input the parser takes.
const DOT_DECIMAL = ',';   // en-US: 1,234.56
const COMMA_DECIMAL = '.'; // de-DE: 1.234,56

describe('resolveSeparators', () => {
    it('honours an explicit comma decimal separator', () => {
        expect(resolveSeparators('comma')).toEqual({decimal: ',', thousand: '.', locale: 'de-DE'});
    });

    it('honours an explicit dot decimal separator', () => {
        expect(resolveSeparators('dot')).toEqual({decimal: '.', thousand: ',', locale: 'en-US'});
    });

    it('falls back to the number format when nothing is configured', () => {
        expect(resolveSeparators('', 'dot_comma').decimal).toBe(',');
        expect(resolveSeparators('', '').decimal).toBe('.');
    });
});

describe('dollarsToCents in a dot-decimal shop', () => {
    it('converts a plain decimal price', () => {
        expect(dollarsToCents('19.99', DOT_DECIMAL)).toBe(1999);
    });

    it('rounds away binary float error rather than truncating', () => {
        // 19.99 * 100 is 1998.9999999999998; a bare cast would store 1998.
        expect(dollarsToCents('19.99', DOT_DECIMAL)).not.toBe(1998);
        expect(dollarsToCents('12.34', DOT_DECIMAL)).toBe(1234);
        expect(dollarsToCents('0.07', DOT_DECIMAL)).toBe(7);
    });

    it('reads a comma as a thousands separator', () => {
        expect(dollarsToCents('1,234.56', DOT_DECIMAL)).toBe(123456);
        expect(dollarsToCents('4,000', DOT_DECIMAL)).toBe(400000);
    });

    it('reads a lone dot as a decimal point, so 4.000 is four dollars', () => {
        expect(dollarsToCents('4.000', DOT_DECIMAL)).toBe(400);
    });

    it('handles a whole number with no separator', () => {
        expect(dollarsToCents('4000', DOT_DECIMAL)).toBe(400000);
    });
});

describe('dollarsToCents in a comma-decimal shop', () => {
    // These are the cases a naive "strip every comma" implementation gets wrong
    // by a factor of 100 — the reason parsing is shared rather than reinvented
    // at each boundary.
    it('reads a comma as the decimal separator', () => {
        expect(dollarsToCents('19,99', COMMA_DECIMAL)).toBe(1999);
    });

    it('reads a dot as the thousands separator', () => {
        expect(dollarsToCents('1.234,56', COMMA_DECIMAL)).toBe(123456);
        expect(dollarsToCents('4.000', COMMA_DECIMAL)).toBe(400000);
    });

    it('treats a lone comma group as a decimal, so 4,00 is four dollars', () => {
        expect(dollarsToCents('4,00', COMMA_DECIMAL)).toBe(400);
    });
});

describe('dollarsToCents edge cases', () => {
    it('returns an empty string for empty input rather than zero', () => {
        // '' must stay '' so a blank field clears rather than writing a 0 price.
        expect(dollarsToCents('', DOT_DECIMAL)).toBe('');
        expect(dollarsToCents(null, DOT_DECIMAL)).toBe('');
        expect(dollarsToCents(undefined, DOT_DECIMAL)).toBe('');
    });

    it('returns an empty string for non-numeric input', () => {
        expect(dollarsToCents('abc', DOT_DECIMAL)).toBe('');
    });

    it('strips currency symbols and stray text around the number', () => {
        expect(dollarsToCents('$19.99', DOT_DECIMAL)).toBe(1999);
        expect(dollarsToCents('19.99 USD', DOT_DECIMAL)).toBe(1999);
    });

    it('accepts a number as well as a string', () => {
        expect(dollarsToCents(19.99, DOT_DECIMAL)).toBe(1999);
    });
});

describe('normalizeTypingValue mid-typing states', () => {
    it('keeps a trailing separator so the caret does not jump while typing', () => {
        expect(normalizeTypingValue('19.', DOT_DECIMAL)).toBe('19.');
    });

    it('truncates beyond two decimal places', () => {
        expect(normalizeTypingValue('19.999', DOT_DECIMAL)).toBe('19.99');
    });

    it('strips leading zeros but keeps a single zero', () => {
        expect(normalizeTypingValue('007', DOT_DECIMAL)).toBe('7');
        expect(normalizeTypingValue('0.50', DOT_DECIMAL)).toBe('0.50');
    });

    it('returns an empty string when there is nothing numeric', () => {
        expect(normalizeTypingValue('', DOT_DECIMAL)).toBe('');
        expect(normalizeTypingValue('abc', DOT_DECIMAL)).toBe('');
    });
});

describe('parseNormalizedValue', () => {
    it('returns NaN for empty and non-numeric input', () => {
        expect(parseNormalizedValue('', DOT_DECIMAL)).toBeNaN();
        expect(parseNormalizedValue(null, DOT_DECIMAL)).toBeNaN();
        expect(parseNormalizedValue('abc', DOT_DECIMAL)).toBeNaN();
    });

    it('drops a trailing separator before parsing', () => {
        expect(parseNormalizedValue('19.', DOT_DECIMAL)).toBe(19);
    });
});

describe('csvValueToCents', () => {
    // The CSV boundary must not repair a bad cell. BulkProductInsertService
    // validates `variants.*.item_price` as `nullable|numeric|min:0` and reports a
    // row error; converting a negative to a positive, or junk to '', would let a
    // row that used to be rejected through as a silently wrong price.
    it('converts a valid price like dollarsToCents does', () => {
        expect(csvValueToCents('19.99', DOT_DECIMAL)).toBe(1999);
        expect(csvValueToCents('1.234,56', COMMA_DECIMAL)).toBe(123456);
    });

    it('keeps a negative sign so the backend min:0 rule still rejects the row', () => {
        expect(csvValueToCents('-19.99', DOT_DECIMAL)).toBe(-1999);
        expect(csvValueToCents('-19,99', COMMA_DECIMAL)).toBe(-1999);
        expect(csvValueToCents('-4,000', DOT_DECIMAL)).toBe(-400000);
    });

    it('hands back a malformed cell verbatim so the numeric rule still rejects it', () => {
        // Returning '' here would pass `nullable` and be stored as 0.
        expect(csvValueToCents('abc', DOT_DECIMAL)).toBe('abc');
        expect(csvValueToCents('N/A', DOT_DECIMAL)).toBe('N/A');
        expect(csvValueToCents('-', DOT_DECIMAL)).toBe('-');
    });

    it('refuses to salvage a number out of a partially malformed cell', () => {
        // The dangerous case: sanitizeInput() would reduce all of these to a
        // valid-looking number, so a typo would import as a real price.
        expect(csvValueToCents('19.99abc', DOT_DECIMAL)).toBe('19.99abc');
        expect(csvValueToCents('abc19.99', DOT_DECIMAL)).toBe('abc19.99');
        expect(csvValueToCents('1e3', DOT_DECIMAL)).toBe('1e3');
        expect(csvValueToCents('19.99 USD', DOT_DECIMAL)).toBe('19.99 USD');
        expect(csvValueToCents('$19.99', DOT_DECIMAL)).toBe('$19.99');
    });

    it('rejects a misplaced or repeated sign', () => {
        expect(csvValueToCents('19-99', DOT_DECIMAL)).toBe('19-99');
        expect(csvValueToCents('--19.99', DOT_DECIMAL)).toBe('--19.99');
        expect(csvValueToCents('19.99-', DOT_DECIMAL)).toBe('19.99-');
    });

    it('treats a blank cell as blank, which nullable accepts', () => {
        expect(csvValueToCents('', DOT_DECIMAL)).toBe('');
        expect(csvValueToCents('   ', DOT_DECIMAL)).toBe('');
        expect(csvValueToCents(null, DOT_DECIMAL)).toBe('');
        expect(csvValueToCents(undefined, DOT_DECIMAL)).toBe('');
    });

    it('keeps zero as a real value rather than a blank', () => {
        expect(csvValueToCents('0', DOT_DECIMAL)).toBe(0);
        expect(csvValueToCents('0.00', DOT_DECIMAL)).toBe(0);
    });
});

describe('centsToDollarValue', () => {
    it('renders cents as a plain dot-decimal dollars string', () => {
        expect(centsToDollarValue(1999)).toBe('19.99');
        expect(centsToDollarValue(400000)).toBe('4000.00');
        expect(centsToDollarValue(7)).toBe('0.07');
    });

    it('passes empty values through untouched', () => {
        expect(centsToDollarValue('')).toBe('');
        expect(centsToDollarValue(null)).toBe('');
        expect(centsToDollarValue(undefined)).toBe('');
    });

    it('round-trips with dollarsToCents in both locales', () => {
        expect(dollarsToCents(centsToDollarValue(129900), DOT_DECIMAL)).toBe(129900);
        expect(dollarsToCents(centsToDollarValue(1999), COMMA_DECIMAL)).toBe(1999);
    });
});
