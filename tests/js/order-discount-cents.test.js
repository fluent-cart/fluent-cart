import {describe, expect, it, vi} from 'vitest';

// cartService.js only imports formatNumber from productService.js, which it
// doesn't call from adjustTotalBasedOnDiscountChange. productService.js pulls
// in browser-only globals (document, navigator) via AppConfig/CurrencyFormatter
// at import time, so stub the whole module rather than the browser environment.
vi.mock('@/Bits/productService', () => ({
    formatNumber: (value) => value,
}));

const {adjustTotalBasedOnDiscountChange} = await import('../../resources/admin/Bits/cartService.js');

// Ticket #3753: a manual fixed discount of 9.95 was stored as 994 cents
// instead of 995. 9.95 * 100 is 994.9999999999999 in binary float, and the
// old code truncated that with parseInt() instead of rounding it.
describe('adjustTotalBasedOnDiscountChange - fixed amount discount', () => {
    it('rounds 9.45 to 945 cents rather than truncating to 944', () => {
        const order = {subtotal: 5000, tax_total: 0, shipping_total: 0, coupon_discount_total: 0};
        const discount = {type: 'amount', value: 9.45};

        adjustTotalBasedOnDiscountChange(order, discount);

        expect(order.manual_discount_total).toBe(945);
    });

    it('rounds 9.95 to 995 cents rather than truncating to 994', () => {
        const order = {subtotal: 5000, tax_total: 0, shipping_total: 0, coupon_discount_total: 0};
        const discount = {type: 'amount', value: 9.95};

        adjustTotalBasedOnDiscountChange(order, discount);

        expect(order.manual_discount_total).toBe(995);
    });

    it('rounds 19.99 to 1999 cents rather than truncating to 1998', () => {
        const order = {subtotal: 5000, tax_total: 0, shipping_total: 0, coupon_discount_total: 0};
        const discount = {type: 'amount', value: 19.99};

        adjustTotalBasedOnDiscountChange(order, discount);

        expect(order.manual_discount_total).toBe(1999);
    });

    it('applies the rounded discount to the order total', () => {
        const order = {subtotal: 5000, tax_total: 0, shipping_total: 0, coupon_discount_total: 0};
        const discount = {type: 'amount', value: 9.95};

        adjustTotalBasedOnDiscountChange(order, discount);

        expect(order.total_amount).toBe(5000 - 995);
    });
});

describe('adjustTotalBasedOnDiscountChange - percentage discount (unchanged behaviour)', () => {
    it('keeps the existing subtotal * value / 100 formula and truncation', () => {
        // subtotal is already in cents; 10.99% of 1099 cents is 120.7801,
        // which the pre-existing parseInt() truncates to 120 - this must
        // stay untouched by the fixed-amount rounding fix.
        const order = {subtotal: 1099, tax_total: 0, shipping_total: 0, coupon_discount_total: 0};
        const discount = {type: 'percentage', value: 10.99};

        adjustTotalBasedOnDiscountChange(order, discount);

        expect(order.manual_discount_total).toBe(120);
    });

    it('computes a whole-number percentage discount correctly', () => {
        const order = {subtotal: 10000, tax_total: 0, shipping_total: 0, coupon_discount_total: 0};
        const discount = {type: 'percentage', value: 25};

        adjustTotalBasedOnDiscountChange(order, discount);

        expect(order.manual_discount_total).toBe(2500);
    });
});
