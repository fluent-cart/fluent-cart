import {describe, expect, it, vi} from 'vitest';

vi.mock('@/Bits/cartService', () => ({recalculatePayout: vi.fn()}));
vi.mock('@/Bits/Components/Buttons/IconButton.vue', () => ({default: {}}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {}}));
vi.mock('@/utils/translator/Translator', () => ({default: text => text}));
vi.mock('@/utils/Notify', () => ({default: {error: vi.fn(), validationErrors: vi.fn()}}));

import Coupon from '@/Modules/Orders/Coupon.vue';

function editOrderCoupon() {
    let settle;
    const state = {
        ...Coupon.data.call({appliedCouponsAttributes: [3], hasCouponAttributes: true, couponsAttributes: [{id: 3, code: 'SAVED', amount: 100}]}),
        action: 'edit_order',
        busy: false,
        priceCoupons: null,
        order: {currency: 'USD', uuid: 'u1', order_items: []},
        $post: vi.fn(() => new Promise(resolve => { settle = resolve; })),
        $emit: vi.fn(),
    };
    for (const name in Coupon.methods) {
        state[name] = Coupon.methods[name].bind(state);
    }
    return {state, settle: response => settle(response)};
}

const response = {applied_coupons: {SAVED: {id: 3, discount: 100}, FIRST: {id: 4, discount: 50}}, calculated_items: {}};

describe('edit order coupon actions', () => {
    it('blocks a second Apply while the first is pending', () => {
        const {state} = editOrderCoupon();
        state.newCoupon = 'FIRST';
        state.applyCoupon();
        state.newCoupon = 'SECOND';
        state.applyCoupon();

        expect(state.$post).toHaveBeenCalledTimes(1);
        expect(state.applying).toBe(true);
    });

    it('blocks Remove while an Apply is pending', () => {
        const {state} = editOrderCoupon();
        state.newCoupon = 'FIRST';
        state.applyCoupon();
        state.cancelCoupon(3, {id: 3, code: 'SAVED'});

        expect(state.$post).toHaveBeenCalledTimes(1);
    });

    it('sends the first coupon with the next Apply once it lands', async () => {
        const {state, settle} = editOrderCoupon();
        state.newCoupon = 'FIRST';
        state.applyCoupon();
        settle(response);
        await new Promise(resolve => setTimeout(resolve, 0));

        expect(state.applying).toBe(false);
        state.newCoupon = 'SECOND';
        state.applyCoupon();

        expect(state.$post).toHaveBeenCalledTimes(2);
        expect(state.$post.mock.calls[1][1].applied_coupons).toEqual([3, 4]);
    });

    it('allows another Apply after a rejected one', async () => {
        const {state} = editOrderCoupon();
        state.$post.mockReturnValueOnce(Promise.reject({status_code: 422}));
        state.newCoupon = 'BAD';
        state.applyCoupon();
        await new Promise(resolve => setTimeout(resolve, 0));

        expect(state.applying).toBe(false);
    });

    it('allows another action after a rejected Remove', async () => {
        const {state} = editOrderCoupon();
        state.$post.mockReturnValueOnce(Promise.reject({status_code: 500, data: {}}));
        state.cancelCoupon(3, {id: 3, code: 'SAVED'});
        await new Promise(resolve => setTimeout(resolve, 0));

        expect(state.applying).toBe(false);
    });
});
