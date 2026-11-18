import {describe, expect, it, vi} from 'vitest';

vi.mock('@/Bits/productService', () => ({formatNumber: value => value}));

import {recalculatePayout} from '@/Bits/cartService';

describe('shipping when the product subtotal is zero', () => {
    it.each([
        ['manual', 'physical', 1000, 100],
        ['manual', 'digital', 0, 0],
        ['zone', 'physical', 0, 0],
        [undefined, 'physical', 0, 0],
    ])('recalculates a %s order with a %s item', (source, type, shipping, tax) => {
        const order = {
            config: {shipping_source: source},
            order_items: [{unit_price: 0, quantity: 2, fulfillment_type: type, discount_total: 0}],
            manual_discount_total: 0,
            shipping_total: 1000,
            shipping_tax: 100,
            tax_total: 0,
        };

        recalculatePayout(order, false);

        expect(order.shipping_total).toBe(shipping);
        expect(order.shipping_tax).toBe(tax);
        expect(order.total_amount).toBe(shipping + tax);
    });
});
