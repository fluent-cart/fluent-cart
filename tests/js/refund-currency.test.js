import {describe, expect, it, vi} from 'vitest';

vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {}}));
vi.mock('@/Bits/Components/VariationSelector.vue', () => ({default: {}}));
vi.mock('@/Bits/Components/Badge.vue', () => ({default: {}}));
vi.mock('@/utils/translator/Translator', () => ({default: text => text, _x: text => text}));
vi.mock('@/utils/support/CurrencyFormatter', () => ({default: {}}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {get: () => ({JPY: 0, UGX: 0, USD: 2})}}));

import Refund from '@/Modules/Orders/Modals/Refund.vue';

// `this` as Vue builds it: the component's methods and computed values (as getters), under the test's own data
// and stubs. Assigning a computed key stores a plain value, as a test that pins one expects.
const makeState = (data) => {
    // The component always has its order prop
    const state = {order: {}, ...Refund.methods, ...data};
    Object.entries(Refund.computed || {}).forEach(([key, def]) => {
        if (key in data) {
            return;
        }
        const get = typeof def === 'function' ? def : def.get;
        Object.defineProperty(state, key, {
            configurable: true, enumerable: true,
            get: () => get.call(state),
            set: value => Object.defineProperty(state, key, {value, writable: true, configurable: true, enumerable: true}),
        });
    });
    return state;
};

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
        const state = makeState({
            order_id: 7, refund: {amount: 100, availableAmount: 1000}, refundStep: 1,
            refundableItems: [], hasRefundSelection: false, refundProcessing: false,
            submitRefund(payload) { return Refund.methods.submitRefund.call(this, payload); },
            $post: vi.fn().mockRejectedValue({message: 'Refund failed'}),
            handleError: vi.fn(), updateRefundable: vi.fn(),
        });
        await Refund.methods.processRefund.call(state);
        await new Promise(resolve => setTimeout(resolve, 0));
        expect(state.handleError).toHaveBeenCalled();
        expect(state.updateRefundable).not.toHaveBeenCalled();
        expect(state.refund.amount).toBe(100);
        expect(state.refundProcessing).toBe(false);
    });

    it('reloads the refundable amount after a successful refund', async () => {
        const state = makeState({
            order_id: 7, refund: {amount: 100, availableAmount: 1000}, refundStep: 1,
            refundableItems: [], hasRefundSelection: false, refundProcessing: false,
            submitRefund(payload) { return Refund.methods.submitRefund.call(this, payload); },
            $post: vi.fn().mockResolvedValue({}), handleError: vi.fn(), updateRefundable: vi.fn(),
        });
        await Refund.methods.processRefund.call(state);
        await new Promise(resolve => setTimeout(resolve, 0));
        expect(state.updateRefundable).toHaveBeenCalledTimes(1);
        expect(state.refundProcessing).toBe(false);
    });

    it('rejects fractional yen before submitting and clears the busy flag', () => {
        const state = makeState({
            refund: {amount: 100.5}, refundStep: 1, refundableItems: [], hasRefundSelection: false, refundProcessing: false,
            handleMessage: vi.fn(), $post: vi.fn(),
        });
        Refund.methods.processRefund.call(state);
        expect(state.$post).not.toHaveBeenCalled();
        expect(state.handleMessage).toHaveBeenCalledWith('Refund amount must be a whole number for this currency.', 'error');
        expect(state.refundProcessing).toBe(false);
    });

    it.each([[1, 100, 10000], [0.01, 100.5, 10050]])('submits valid amounts at step %s', (step, amount, stored) => {
        const state = makeState({
            order_id: 7, refund: {amount, availableAmount: 1000}, refundStep: step,
            refundableItems: [], hasRefundSelection: false, refundProcessing: false,
            submitRefund(payload) { return Refund.methods.submitRefund.call(this, payload); }, $post: vi.fn().mockResolvedValue({}),
            handleError: vi.fn(), updateRefundable: vi.fn(),
        });
        Refund.methods.processRefund.call(state);
        expect(state.$post).toHaveBeenCalledWith('orders/7/refund', {
            refund_info: {amount: stored, availableAmount: 1000, shipping_amount: 0, manageStock: false}, refunded_items: [],
        });
    });
    it('opens with every refundable line and the remaining shipping selected', () => {
        const state = makeState({
            order: {shipping_total: 500, shipping_refunded_amount: 0, shipping_refundable: 500, total_paid: 3800, total_refund: 1000, order_items: [
                {id: 1, quantity: 2, line_total: 2000, tax_amount: 0, refund_total: 0},
                {id: 2, quantity: 1, line_total: 1000, tax_amount: 0, refund_total: 1000},
                {id: 3, payment_type: 'signup_fee', quantity: 2, line_total: 300, tax_amount: 0, refund_total: 0},
            ]},
            itemLineInclusive: () => false,
        });
        state.shippingRemaining = Refund.computed.shippingRemaining.call(state);
        state.buildRefundableItems = items => Refund.methods.buildRefundableItems.call(state, items);
        expect(Refund.watch.showRefundModal.immediate).toBe(true);
        Refund.watch.showRefundModal.handler.call(state, true);

        // The signup fee posts its real units (RefundPlanner still takes the 1 that older modals post)
        expect(state.refundableItems.map(i => [i.id, i.isSelected, i.refund_qty])).toEqual([[1, true, 2], [2, false, 0], [3, true, 2]]);
        expect(state.includeShipping).toBe(true);
        expect(state.shippingRefundAmount).toBe(5);

        state.refundableTransactions = [{id: 9, refundable: 2800}];
        state.refund = {transaction_id: 9};
        expect(Refund.computed.computedRefundAmount.call(state)).toBe(28);
    });

    it('prices one unit of a discounted yen line in whole yen', () => {
        const state = makeState({ order: {currency: 'JPY', total_paid: 50000}, itemLineInclusive: () => true});
        state.refundableItems = Refund.methods.buildRefundableItems.call(state, [
            {id: 1, quantity: 3, line_total: 50000, tax_amount: 0, refund_total: 0},
        ]);
        state.refundableItems[0].refund_qty = 1;
        state.refundableTransactions = [{id: 9, refundable: 50000}];
        state.refund = {transaction_id: 9};

        expect(state.refundableItems[0].unitAmount).toBe(16700);
        expect(Refund.computed.computedRefundAmount.call(state)).toBe(167);
    });

    it('shows a tax-exclusive yen unit as price plus tax equal to the charge', () => {
        const state = makeState({ order: {currency: 'JPY'}, itemLineInclusive: () => false});
        const [item] = Refund.methods.buildRefundableItems.call(state, [
            {id: 1, quantity: 3, line_total: 50000, tax_amount: 5000, refund_total: 0},
        ]);

        expect(item.unitAmount).toBe(18300);
        expect(item.baseUnit + item.unitTax).toBe(item.unitAmount);
    });

    it('prices the units left after an earlier unit refund', () => {
        const state = makeState({ order: {currency: 'USD'}, itemLineInclusive: () => true});
        const [item] = Refund.methods.buildRefundableItems.call(state, [
            {id: 1, quantity: 2, line_total: 20000, tax_amount: 0, refund_total: 12000, line_meta: {refunded_quantity: 1}},
        ]);

        expect(item.maxQty).toBe(1);
        expect(item.unitAmount).toBe(8000);
    });

    it('never charges more than the line has left for a partial unit refund', () => {
        const state = makeState({ order: {currency: 'USD', total_paid: 100000}, itemLineInclusive: () => true});
        state.refundableItems = Refund.methods.buildRefundableItems.call(state, [
            {id: 1, quantity: 10, line_total: 100000, tax_amount: 0, refund_total: 99975},
        ]);
        state.refundableItems[0].refund_qty = 9;
        state.refundableTransactions = [{id: 9, refundable: 100000}];
        state.refund = {transaction_id: 9};

        expect(Refund.computed.computedRefundAmount.call(state)).toBe(0.25);
    });

    it('shows the whole selection when it spans more than the selected payment', () => {
        const state = makeState({
            order: {currency: 'USD', total_paid: 10000, total_refund: 0},
            refundableItems: [
                {isSelected: true, refund_qty: 1, maxQty: 1, remainingBase: 5000, unitAmount: 5000},
                {isSelected: true, refund_qty: 1, maxQty: 1, remainingBase: 5000, unitAmount: 5000},
            ],
            refundableTransactions: [{id: 9, refundable: 5000}, {id: 10, refundable: 5000}],
            refund: {transaction_id: 9},
        });

        expect(Refund.computed.computedRefundAmount.call(state)).toBe(100);
    });

    it('caps the selection at what is left on the order', () => {
        const state = makeState({
            order: {currency: 'USD', total_paid: 7000, total_refund: 0},
            refundableItems: [
                {isSelected: true, refund_qty: 1, maxQty: 1, remainingBase: 5000, unitAmount: 5000},
                {isSelected: true, refund_qty: 1, maxQty: 1, remainingBase: 5000, unitAmount: 5000},
            ],
            refundableTransactions: [{id: 9, refundable: 7000}],
            refund: {transaction_id: 9},
        });

        expect(Refund.computed.computedRefundAmount.call(state)).toBe(70);
    });

    it('blocks a line whose units are all refunded', () => {
        const state = makeState({ order: {currency: 'USD'}, itemLineInclusive: () => true});
        const [item] = Refund.methods.buildRefundableItems.call(state, [
            {id: 1, quantity: 2, line_total: 20000, tax_amount: 0, refund_total: 15000, line_meta: {refunded_quantity: 2}},
        ]);

        expect(item.maxQty).toBe(0);
        expect(item.isSelected).toBe(false);
    });
});
