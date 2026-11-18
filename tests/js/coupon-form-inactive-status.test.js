import {describe, expect, it} from 'vitest';
import {couponStatusForDates} from '@/Modules/Coupons/couponStatus';

/**
 * A coupon stored as "inactive" is turned off (the MCP manage-coupon tool writes that
 * status). The coupon form recomputes the status from the dates when it opens, so opening
 * and saving such a coupon turned it back on.
 */

const now = new Date('2026-11-17T12:00:00Z');
const past = new Date('2026-11-01T00:00:00Z');
const future = new Date('2026-12-01T00:00:00Z');

describe('coupon form status', () => {
    it('shows an inactive coupon as disabled and leaves it off, whatever its dates', () => {
        expect(couponStatusForDates('inactive', past, null, now)).toBe('disabled');
        expect(couponStatusForDates('inactive', future, null, now)).toBe('disabled');
        expect(couponStatusForDates('inactive', past, past, now)).toBe('disabled');
        expect(couponStatusForDates('inactive', null, null, now)).toBe('disabled');
    });

    it('leaves a disabled coupon off', () => {
        expect(couponStatusForDates('disabled', past, future, now)).toBe('disabled');
    });

    it('derives the status of a live coupon from its dates, as before', () => {
        expect(couponStatusForDates('active', null, null, now)).toBe('active');
        expect(couponStatusForDates('active', future, null, now)).toBe('scheduled');
        expect(couponStatusForDates('scheduled', past, null, now)).toBe('active');
        expect(couponStatusForDates('active', past, past, now)).toBe('expired');
        expect(couponStatusForDates('expired', past, future, now)).toBe('active');
    });
});
