import {beforeEach, describe, expect, it, vi} from 'vitest';
import dayjs from 'dayjs';

/**
 * The admin bundle renders dates in one of two timezones, chosen by the store's
 * `timezone_source` setting and delivered in the localized `datei18` payload:
 *
 *   fluent_cart (default) — the viewer's own browser timezone, which is how the
 *                           admin has always rendered dates.
 *   wordpress             — the site timezone from Settings > General, so every
 *                           admin sees one clock wherever they are sitting.
 *
 * These cases pin both, plus the two shapes wp_timezone_string() can return: an
 * IANA name ('Europe/Berlin') and a manual UTC offset ('+05:30'). Day.js .tz()
 * rejects the offset form, so it has to route through .utcOffset() instead —
 * that split is the part most likely to regress.
 */

const dateConfig = vi.hoisted(() => ({
    datei18: {}
}));

vi.mock('@/utils/Config/AppConfig', () => ({
    default: {
        get(key, defaultValue) {
            if (key === 'datei18') {
                return dateConfig.datei18;
            }
            return defaultValue;
        },
        // Utils pulls in CurrencyFormatter, whose static initializer subscribes
        // here at module-eval time.
        onShopUpdate(callback) {
            callback();
        },
    },
}));

vi.mock('@/utils/translator/Translator', () => ({
    default: (string) => string,
    translateNumber: (value) => value,
}));

const {toStoreTimezone, dateTimeI18, resolveDateFormat} = await import('@/utils/Utils');

/**
 * The payload TransStrings::dateTimeStrings() ships, narrowed to what these
 * cases read. `formats` carries converted Day.js patterns, not WordPress ones.
 */
function useTimezone(source, site, formats = {date_time: 'YYYY-MM-DD HH:mm'}) {
    dateConfig.datei18 = {
        formats,
        // These cases are about the timezone axis, so pin the format axis to the
        // WordPress source — otherwise resolveDateFormat() correctly ignores the
        // patterns above and the assertions would be testing the legacy literals.
        formats_source: 'wordpress',
        timezone: {source, site},
    };
}

/**
 * 2026-08-16 13:30 UTC as the machine running these tests would render it.
 * Computed rather than hard-coded so the suite is not pinned to one CI zone.
 */
function browserRendering() {
    return dayjs(Date.UTC(2026, 7, 16, 13, 30)).format('YYYY-MM-DD HH:mm');
}

describe('toStoreTimezone', () => {
    beforeEach(() => {
        dateConfig.datei18 = {};
    });

    it('renders in the site timezone when the store follows WordPress', () => {
        useTimezone('wordpress', 'Europe/Berlin');

        // 13:30 UTC is 15:30 in Berlin (CEST, UTC+2).
        expect(toStoreTimezone('2026-08-16 13:30:00').format('YYYY-MM-DD HH:mm'))
            .toBe('2026-08-16 15:30');
    });

    it('renders a negative-offset site timezone backwards across midnight', () => {
        useTimezone('wordpress', 'America/New_York');

        // 01:30 UTC is still the previous day in New York.
        expect(toStoreTimezone('2026-08-16 01:30:00').format('YYYY-MM-DD HH:mm'))
            .toBe('2026-08-15 21:30');
    });

    it('accepts a manual UTC offset, which Day.js .tz() cannot take', () => {
        useTimezone('wordpress', '+05:30');

        expect(toStoreTimezone('2026-08-16 13:30:00').format('YYYY-MM-DD HH:mm'))
            .toBe('2026-08-16 19:00');
    });

    it('accepts a negative manual UTC offset', () => {
        useTimezone('wordpress', '-03:00');

        expect(toStoreTimezone('2026-08-16 13:30:00').format('YYYY-MM-DD HH:mm'))
            .toBe('2026-08-16 10:30');
    });

    it('falls back to the browser timezone when the site zone is unusable', () => {
        useTimezone('wordpress', 'Not/AZone');

        const expected = browserRendering();

        expect(toStoreTimezone('2026-08-16 13:30:00').format('YYYY-MM-DD HH:mm'))
            .toBe(expected);
    });

    it('uses the browser timezone on the FluentCart source, ignoring the site zone', () => {
        useTimezone('fluent_cart', 'Europe/Berlin');

        const expected = browserRendering();

        expect(toStoreTimezone('2026-08-16 13:30:00').format('YYYY-MM-DD HH:mm'))
            .toBe(expected);
    });

    it('actually renders a different clock on each source', () => {
        // Guard against a false pass: if the machine running this suite happened
        // to sit in the zone under test, every assertion above would agree by
        // coincidence. Pick whichever far-away zone differs from this machine.
        const site = browserRendering().endsWith('13:30') ? 'Asia/Tokyo' : 'UTC';

        useTimezone('wordpress', site);
        const wordpress = toStoreTimezone('2026-08-16 13:30:00').format('YYYY-MM-DD HH:mm');

        useTimezone('fluent_cart', site);
        const fluentCart = toStoreTimezone('2026-08-16 13:30:00').format('YYYY-MM-DD HH:mm');

        expect(wordpress).not.toBe(fluentCart);
        expect(fluentCart).toBe(browserRendering());
    });

    it('uses the browser timezone when the payload carries no timezone block at all', () => {
        // An older bundle, or an add-on localizing its own vars, ships no
        // `timezone` key. That must render exactly as it did before.
        dateConfig.datei18 = {formats: {date_time: 'YYYY-MM-DD HH:mm'}, formats_source: 'wordpress'};

        const expected = browserRendering();

        expect(toStoreTimezone('2026-08-16 13:30:00').format('YYYY-MM-DD HH:mm'))
            .toBe(expected);
    });

    it('uses the browser timezone when the source is wordpress but no site zone shipped', () => {
        useTimezone('wordpress', '');

        const expected = browserRendering();

        expect(toStoreTimezone('2026-08-16 13:30:00').format('YYYY-MM-DD HH:mm'))
            .toBe(expected);
    });
});

describe('dateTimeI18 through the store timezone', () => {
    beforeEach(() => {
        dateConfig.datei18 = {};
    });

    it('formats in the site timezone using the store pattern', () => {
        useTimezone('wordpress', 'Europe/Berlin', {date_time: 'DD.MM.YYYY HH:mm'});

        expect(dateTimeI18('2026-08-16 13:30:00', 'date_time')).toBe('16.08.2026 15:30');
    });

    it('crosses the site-timezone midnight rather than the browser one', () => {
        useTimezone('wordpress', 'Europe/Berlin', {date: 'YYYY-MM-DD'});

        // 22:30 UTC is already the next day in Berlin.
        expect(dateTimeI18('2026-08-16 22:30:00', 'date')).toBe('2026-08-17');
    });
});

/**
 * The FluentCart format source must reproduce what this bundle rendered before
 * these settings existed — which is NOT what PHP rendered. PHP's legacy time
 * literal is 'h:i A' (zero-padded); the bundle's is 'h:mm A' (not). Resolving
 * the converted PHP patterns on the FluentCart source would renumber every time
 * in the admin from '3:30 PM' to '03:30 PM'.
 */
describe('format source back-compat', () => {
    beforeEach(() => {
        dateConfig.datei18 = {};
    });

    it('keeps the unpadded hour on the FluentCart source', () => {
        dateConfig.datei18 = {
            formats_source: 'fluent_cart',
            // The converted PHP patterns, which must be ignored here.
            formats: {date_time: 'MMM DD, YYYY hh:mm A'},
            timezone: {source: 'wordpress', site: 'UTC'},
        };

        expect(dateTimeI18('2026-08-16 15:30:00', 'date_time')).toBe('Aug 16, 2026 3:30 PM');
    });

    it('follows the converted pattern on the WordPress source', () => {
        dateConfig.datei18 = {
            formats_source: 'wordpress',
            formats: {date_time: 'MMM DD, YYYY hh:mm A'},
            timezone: {source: 'wordpress', site: 'UTC'},
        };

        expect(dateTimeI18('2026-08-16 15:30:00', 'date_time')).toBe('Aug 16, 2026 03:30 PM');
    });

    it('keeps the legacy literals when no source ships at all', () => {
        dateConfig.datei18 = {formats: {date_time: 'MMM DD, YYYY hh:mm A'}};

        expect(resolveDateFormat('date_time')).toBe('MMM DD, YYYY h:mm A');
    });

    it('still passes an unrecognised pattern straight through', () => {
        dateConfig.datei18 = {formats_source: 'wordpress', formats: {}};

        expect(resolveDateFormat('YYYY/MM/DD')).toBe('YYYY/MM/DD');
    });
});
