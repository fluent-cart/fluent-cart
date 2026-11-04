import {describe, expect, it, vi} from 'vitest';

vi.mock('@/utils/translator/Translator', () => ({
    default: (text, ...args) => args.reduce((out, arg, i) => out.replace('%' + (i + 1) + '$s', arg), text),
    translateNumber: (value) => String(value),
}));

import {orderTypeText, isInstallmentOrder} from '@/Modules/Orders/Components/orderSaleType';

describe('order type label on the Orders list', () => {
    it('names installment orders apart from subscriptions and renewals', () => {
        expect(orderTypeText({type: 'renewal', sale_type: 'installment_payment'})).toBe('Installment payment');
        expect(orderTypeText({type: 'subscription', sale_type: 'installment'})).toBe('Installment sign-up');
        expect(orderTypeText({type: 'renewal', sale_type: 'renewal'})).toBe('Renewal');
        expect(isInstallmentOrder({sale_type: 'installment_payment'})).toBe(true);
        expect(isInstallmentOrder({sale_type: 'renewal'})).toBe(false);
    });

    it('falls back to the stored type, and never shows blank', () => {
        expect(orderTypeText({type: 'payment'})).toBe('One-time');
        expect(orderTypeText({type: 'custom_type'})).not.toBe('');
    });
    it('numbers an installment order within its plan', () => {
        expect(orderTypeText({type: 'subscription', sale_type: 'installment', installment_number: 1, installment_total: 6})).toBe('Installment 1/6');
        expect(orderTypeText({type: 'renewal', sale_type: 'installment_payment', installment_number: '3', installment_total: '6'})).toBe('Installment 3/6');
    });
});
