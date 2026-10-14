import {describe, expect, it, vi} from 'vitest';

vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {}}));
vi.mock('@/Bits/Components/VariationSelector.vue', () => ({default: {}}));
vi.mock('@/Bits/Components/Badge.vue', () => ({default: {}}));
vi.mock('@/utils/translator/Translator', () => ({default: text => text}));
vi.mock('@/utils/support/CurrencyFormatter', () => ({default: {}}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {get: () => ({JPY: 0, UGX: 0, USD: 2})}}));

import Refund from '@/Modules/Orders/Modals/Refund.vue';

describe('refund currency precision', () => {
    it.each([['JPY', 1], ['UGX', 1], ['USD', 0.01]])('uses the %s transaction step', (currency, step) => {
        expect(Refund.computed.refundStep.call({
            refundableTransactions: [{id: 1, currency}], refund: {transaction_id: 1}, order: {currency: 'USD'},
        })).toBe(step);
    });

    // Stripe stores the transaction currency in lowercase
    it.each([['jpy', 1], ['usd', 0.01]])('reads the step for a lowercase %s transaction', (currency, step) => {
        expect(Refund.computed.refundStep.call({
            refundableTransactions: [{id: 1, currency}], refund: {transaction_id: 1}, order: {currency: 'USD'},
        })).toBe(step);
    });

    it('keeps the typed amount when the refund request fails', async () => {
        const state = {
            order_id: 7, refund: {amount: 100, availableAmount: 1000}, refundStep: 1,
            refundTableItems: [], refundProcessing: false,
            $post: vi.fn().mockRejectedValue({message: 'Refund failed'}),
            handleError: vi.fn(), updateRefundable: vi.fn(),
        };
        await Refund.methods.processRefund.call(state);
        await new Promise(resolve => setTimeout(resolve, 0));
        expect(state.handleError).toHaveBeenCalled();
        expect(state.updateRefundable).not.toHaveBeenCalled();
        expect(state.refund.amount).toBe(100);
        expect(state.refundProcessing).toBe(false);
    });

    it('reloads the refundable amount after a successful refund', async () => {
        const state = {
            order_id: 7, refund: {amount: 100, availableAmount: 1000}, refundStep: 1,
            refundTableItems: [], refundProcessing: false,
            $post: vi.fn().mockResolvedValue({}), handleError: vi.fn(), updateRefundable: vi.fn(),
        };
        await Refund.methods.processRefund.call(state);
        await new Promise(resolve => setTimeout(resolve, 0));
        expect(state.updateRefundable).toHaveBeenCalledTimes(1);
        expect(state.refundProcessing).toBe(false);
    });

    it('rejects fractional yen before submitting and clears the busy flag', () => {
        const state = {
            refund: {amount: 100.5}, refundStep: 1, refundProcessing: false,
            handleMessage: vi.fn(), $post: vi.fn(),
        };
        Refund.methods.processRefund.call(state);
        expect(state.$post).not.toHaveBeenCalled();
        expect(state.handleMessage).toHaveBeenCalledWith('Refund amount must be a whole number for this currency.', 'error');
        expect(state.refundProcessing).toBe(false);
    });

    it.each([[1, 100, 10000], [0.01, 100.5, 10050]])('submits valid amounts at step %s', (step, amount, stored) => {
        const state = {
            order_id: 7, refund: {amount, availableAmount: 1000}, refundStep: step,
            refundTableItems: [], refundProcessing: false, $post: vi.fn().mockResolvedValue({}),
            handleError: vi.fn(), updateRefundable: vi.fn(),
        };
        Refund.methods.processRefund.call(state);
        expect(state.$post).toHaveBeenCalledWith('orders/7/refund', {
            refund_info: {amount: stored, availableAmount: 1000, refunded_items: []}, refunded_items: [],
        });
    });
});
