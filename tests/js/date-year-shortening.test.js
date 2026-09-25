import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import dayjs from 'dayjs';
import utc from 'dayjs/plugin/utc.js';
import timezone from 'dayjs/plugin/timezone.js';

dayjs.extend(utc);
dayjs.extend(timezone);

/**
 * Whether a day/month column may drop the year.
 *
 * Two rules, and the second one is the regression this file exists for:
 *
 *  1. Shortening only happens on the FluentCart format source. A store that
 *     follows the WordPress date format gets that format verbatim, year and
 *     all -- the store picked the pattern in Settings > General, and swapping
 *     in a yearless variant would override that choice with a guess.
 *  2. When shortening IS allowed, "is this the current year" has to be asked
 *     in the timezone the date is rendered in, not the browser's. The format
 *     source and the timezone source are independent settings, so a store can
 *     keep the FluentCart literals while rendering in the WordPress site
 *     timezone -- and on either side of New Year those two clocks sit in
 *     different years. The old code compared browser-local native Dates and
 *     dropped a year it needed.
 *
 * Both bundles carry their own copy of the helper (separate entry points,
 * separate localized vars), so both are pinned here.
 */

// Vitest pins TZ=UTC, so 30 seconds past UTC New Year the "browser" is in 2026
// while every negative-offset zone is still in 2025.
const NEW_YEAR_INSTANT = '2026-01-01T00:00:30Z';
const LAST_YEAR_DATE = '2025-12-20 12:00:00';

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

/**
 * The same payload shape on both surfaces, so one table of cases can drive both.
 */
function useConfig(formatsSource, timezoneSource, site) {
    const datei18 = {
        formats: {},
        formats_source: formatsSource,
        timezone: {source: timezoneSource, site},
    };

    dateConfig.datei18 = datei18;
    globalThis.window.fluentcart_customer_profile_vars = {datei18};
}

const bundles = [
    ['admin', admin],
    ['customer profile', profile],
];

describe.each(bundles)('resolveYearAwareFormat (%s bundle)', (name, bundle) => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(NEW_YEAR_INSTANT));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('never shortens on the WordPress format source, even inside the current year', () => {
        useConfig('wordpress', 'wordpress', 'UTC');

        expect(bundle.resolveYearAwareFormat(bundle.toStoreTimezone('2026-01-01T00:00:00Z')))
            .toBe('date');
    });

    it('shortens a current-year date on the FluentCart format source', () => {
        useConfig('fluent_cart', 'wordpress', 'UTC');

        expect(bundle.resolveYearAwareFormat(bundle.toStoreTimezone('2026-01-01T00:00:00Z')))
            .toBe('date_short');
    });

    it('keeps the year on an older date on the FluentCart format source', () => {
        useConfig('fluent_cart', 'wordpress', 'UTC');

        expect(bundle.resolveYearAwareFormat(bundle.toStoreTimezone(LAST_YEAR_DATE)))
            .toBe('date');
    });

    it('reads the current year in the store timezone, not the browser one', () => {
        // The store keeps the FluentCart literals (so shortening is live) but
        // renders in New York, which at this instant is still in 2025.
        useConfig('fluent_cart', 'wordpress', 'America/New_York');

        const storeYear = dayjs.utc(NEW_YEAR_INSTANT).tz('America/New_York').year();
        const browserYear = dayjs(NEW_YEAR_INSTANT).year();

        // Guard: the case is only meaningful while the two clocks disagree.
        expect(storeYear).not.toBe(browserYear);

        const rendered = bundle.toStoreTimezone(LAST_YEAR_DATE);

        // Dec 2025 IS the current year in New York, so the year is redundant.
        expect(rendered.year()).toBe(storeYear);
        expect(bundle.resolveYearAwareFormat(rendered)).toBe('date_short');

        // A browser-local comparison would have said 2025 !== 2026 and printed
        // a year the reader did not need. That is the bug this pins.
        expect(rendered.year()).not.toBe(browserYear);
    });

    it('falls back to shortening when no format source ships at all', () => {
        // An older bundle, or an add-on localizing its own vars, ships no
        // formats_source. That must keep the pre-settings behaviour.
        const datei18 = {formats: {}, timezone: {source: 'fluent_cart', site: ''}};
        dateConfig.datei18 = datei18;
        globalThis.window.fluentcart_customer_profile_vars = {datei18};

        expect(bundle.resolveYearAwareFormat(bundle.toStoreTimezone('2026-01-01T00:00:00Z')))
            .toBe('date_short');
    });
});
