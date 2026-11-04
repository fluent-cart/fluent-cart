import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';

/**
 * The smart date shows the time for a recent past date: earlier today shows the time ("2:50 PM"), and yesterday
 * within the last 24 hours shows "Yesterday, 11:30 PM". Older and future dates keep the date. "Today" is the store's
 * day, in the timezone the date renders in. Both bundles carry their own copy, so both are pinned.
 */

// 15:00 UTC on 3 Nov 2026
const NOW = '2026-11-03T15:00:00Z';

const dateConfig = vi.hoisted(() => ({datei18: {}}));

vi.mock('@/utils/Config/AppConfig', () => ({
    default: {
        get(key, defaultValue) {
            return key === 'datei18' ? dateConfig.datei18 : defaultValue;
        },
        onShopUpdate(callback) {
            callback();
        },
    },
}));

globalThis.window = globalThis.window || {};

const admin = await import('@/utils/dateFormats');
const profile = await import('../../resources/public/customer-profile/translator/Translator.js');
const {formatDate, formatSentenceDate} = await import('@/Bits/common');

function useTimezone(site) {
    const datei18 = {formats: {}, formats_source: 'fluent_cart', timezone: {source: 'wordpress', site}};
    dateConfig.datei18 = datei18;
    globalThis.window.fluentcart_customer_profile_vars = {datei18};
}

describe.each([['admin', admin], ['customer profile', profile]])('recentDateText (%s bundle)', (name, bundle) => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(NOW));
        useTimezone('UTC');
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('shows the time for earlier today', () => {
        expect(bundle.recentDateText('2026-11-03 14:50:00')).toBe('2:50 PM');
    });

    it('shows "Yesterday" with the time within the last 24 hours', () => {
        expect(bundle.recentDateText('2026-11-02 23:30:00')).toBe('Yesterday, 11:30 PM');
    });

    it('keeps the date for anything older than 24 hours, a future date and no date', () => {
        expect([
            bundle.recentDateText('2026-11-02 14:00:00'),
            bundle.recentDateText('2026-11-03 18:00:00'),
            bundle.recentDateText(''),
            bundle.recentDateText(null),
        ]).toEqual([null, null, null, null]);
    });

    it('says "Yesterday" only for the previous calendar day, across a spring-forward night', () => {
        // 00:15 on 9 March in New York; the 8th lasted 23 hours, so 23:30 on the 7th is under 24 hours old
        vi.setSystemTime(new Date('2026-03-09T04:15:00Z'));
        useTimezone('America/New_York');
        expect(bundle.recentDateText('2026-03-08 04:30:00')).toBe(null);
    });

    it('reads "today" in the store timezone', () => {
        // 21:00 in Dhaka: 01:00 UTC is 07:00 there, the same store day
        useTimezone('Asia/Dhaka');
        expect(bundle.recentDateText('2026-11-03 01:00:00')).toBe('7:00 AM');
    });
});

describe('customer profile dateTimeI18', () => {
    // A shipment with no carrier update, a missing date: an empty string, not "Invalid Date" or today
    it('renders an empty value as an empty string', () => {
        useTimezone('UTC');
        expect([profile.dateTimeI18(null), profile.dateTimeI18(''), profile.dateTimeI18(undefined)]).toEqual(['', '', '']);
    });
});

describe('admin formatDate', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(NOW));
        useTimezone('UTC');
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('shows the time alone for earlier today, with or without the time requested', () => {
        expect([formatDate('2026-11-03 14:50:00'), formatDate('2026-11-03 14:50:00', true)]).toEqual(['2:50 PM', '2:50 PM']);
    });

    it('keeps the date inside a sentence ("Created %1$s"), even earlier today', () => {
        expect(formatSentenceDate('2026-11-03 14:50:00')).toBe('Nov 03');
    });

    it('keeps the smart date for an older date', () => {
        expect(formatDate('2026-10-20 14:50:00')).toBe('Oct 20');
    });
});
