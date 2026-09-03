import {describe, expect, it} from 'vitest';

import {priceRangeFormat} from '../../resources/public/product-page/Filter.js';

/**
 * Regression: the shop price-range slider rounded prices to one decimal place.
 *
 * The store's cheapest product was $0.99. The slider's `to` formatter ran
 * `parseFloat(v).toFixed(1)`, producing "1.0", and the slider's `update` handler
 * writes that formatted string straight back into the filter inputs
 * (`from.value = values[0]`) and into the URL. So touching the slider raised the
 * floor from 0.99 to 1.00 and dropped the cheapest product out of the results.
 *
 * Reproduced on https://fluentcart.test/shop/ (store min = 99 cents):
 *   before drag: input = "0.99"
 *   after drag:  input = "1.0", url = ?filters[price_range_from]=1.0
 *   and "Advanced Variation with subscription" (From $0.99) disappeared.
 *
 * Prices are stored as cents (see CLAUDE.md), so the formatter must keep two
 * decimals, and `from` must decode to a real Number without re-rounding.
 */
describe('shop price range slider number format', () => {

    describe('to() — the value written back into the filter inputs and URL', () => {
        it('preserves cents for a .99 minimum instead of rounding up to the next unit', () => {
            expect(priceRangeFormat.to(0.99)).toBe('0.99');
            expect(priceRangeFormat.to(11.99)).toBe('11.99');
        });

        it('does not round a .99 maximum up past the most expensive product', () => {
            expect(priceRangeFormat.to(99.99)).toBe('99.99');
        });

        it('keeps two decimals for whole and single-decimal amounts', () => {
            expect(priceRangeFormat.to(1190)).toBe('1190.00');
            expect(priceRangeFormat.to(8.5)).toBe('8.50');
        });

        it('never rounds a value upward, which would exclude the cheapest product', () => {
            for (const cents of [1, 49, 99, 101, 199, 1099, 119000]) {
                const amount = cents / 100;
                expect(parseFloat(priceRangeFormat.to(amount))).toBe(amount);
            }
        });
    });

    describe('from() — decoding a typed or set() value back to a number', () => {
        it('returns a Number, not a formatted string', () => {
            expect(typeof priceRangeFormat.from('0.99')).toBe('number');
        });

        it('does not re-round, so cents survive a set() round trip', () => {
            expect(priceRangeFormat.from('0.99')).toBe(0.99);
            expect(priceRangeFormat.from('11.99')).toBe(11.99);
            expect(priceRangeFormat.from(99.99)).toBe(99.99);
        });
    });

    describe('round trip — the slider set/update cycle must be lossless', () => {
        it('survives to(from(x)) for cent-precise prices', () => {
            for (const raw of ['0.99', '11.99', '99.99', '1190']) {
                expect(priceRangeFormat.to(priceRangeFormat.from(raw)))
                    .toBe(parseFloat(raw).toFixed(2));
            }
        });
    });
});
