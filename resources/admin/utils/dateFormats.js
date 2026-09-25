import dayjs from 'dayjs';
import utc from 'dayjs/plugin/utc.js';
import timezone from 'dayjs/plugin/timezone.js';
import advancedFormat from 'dayjs/plugin/advancedFormat.js';
import weekOfYear from 'dayjs/plugin/weekOfYear.js';
import AppConfig from "@/utils/Config/AppConfig";

dayjs.extend(utc);
dayjs.extend(timezone);
// A WordPress date format may use an ordinal day ('jS') or a week number ('W'),
// which convert to Day.js tokens these plugins provide.
dayjs.extend(advancedFormat);
dayjs.extend(weekOfYear);

/**
 * Date format and timezone resolution for the admin bundle.
 *
 * Split out of Utils.js so that CurrencyFormatter can read the store's formats
 * without the two importing each other -- Utils imports CurrencyFormatter, so a
 * CurrencyFormatter -> Utils edge closes a cycle and CurrencyFormatter's static
 * initializer then runs against a half-built module.
 *
 * Utils re-exports everything here, so existing `@/utils/Utils` imports are
 * unaffected.
 */

/**
 * Day.js patterns used when the store has not shipped `datei18.formats`.
 *
 * These are the literals this helper used before the formats became
 * configurable, so an older bundle -- or an add-on reading the same localized
 * var -- keeps rendering exactly as it did.
 */
const LEGACY_DATE_FORMATS = {
    date: 'MMM DD, YYYY',
    date_short: 'MMM DD',
    time: 'h:mm A',
    date_time: 'MMM DD, YYYY h:mm A',
    month_year: 'MMM YYYY',
    month_long: 'MMMM'
};

const EN_WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const EN_WEEKDAYS_SHORT = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const EN_MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const EN_MONTHS_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/**
 * Resolve a named format key to the store's Day.js pattern.
 *
 * A key ('date', 'date_short', 'time', 'date_time', 'month_year', 'month_long')
 * resolves to the pattern derived from the WordPress date/time settings. Any
 * other string is treated as a raw Day.js pattern and passed through, so
 * callers that still hand over a literal keep working.
 */
export function resolveDateFormat(format = 'date_short') {
    if (!Object.prototype.hasOwnProperty.call(LEGACY_DATE_FORMATS, format)) {
        return format;
    }

    const datei18 = AppConfig.get('datei18') || {};

    // On the FluentCart source the bundle keeps its own literals rather than the
    // converted PHP ones. The two were never identical -- PHP's 'h:i A' pads the
    // hour, this bundle's 'h:mm A' does not -- so reading the converted formats
    // here would quietly renumber every time in the admin as 03:30 PM.
    if (datei18.formats_source !== 'wordpress') {
        return LEGACY_DATE_FORMATS[format];
    }

    return (datei18.formats || {})[format] || LEGACY_DATE_FORMATS[format];
}

/**
 * Day.js reads `monthsShort[index] || monthsShort(instance, format)` -- a falsy
 * entry makes it call the array as a function and throw. Backfill any hole from
 * the English list so a partial translation can never crash a date.
 */
function localeNames(source, fallback) {
    const values = Object.values(source || {});

    return fallback.map((name, index) => values[index] || name);
}

/**
 * The store's translated month/weekday names as a Day.js locale object.
 *
 * Exported so that every date path in the admin -- this helper and the
 * hand-rolled formatter in Bits/common.js -- renders the same names.
 */
export function fluentDayjsLocale() {
    const datei18 = AppConfig.get('datei18') || {};

    return {
        name: 'fluent_date_time',
        weekdays: localeNames(datei18.weekdays, EN_WEEKDAYS),
        weekdaysShort: localeNames(datei18.weekdaysShort, EN_WEEKDAYS_SHORT),
        months: localeNames(datei18.months, EN_MONTHS),
        monthsShort: localeNames(datei18.monthsShort, EN_MONTHS_SHORT),
        meridiem: (hour, minute, isLowercase) => {
            const amText = datei18.am || 'AM';
            const pmText = datei18.pm || 'PM';
            const result = hour < 12 ? amText : pmText;
            return isLowercase ? result.toLowerCase() : result;
        }
    };
}

/**
 * A manual UTC offset from the WordPress timezone setting, e.g. '+05:30'.
 *
 * wp_timezone_string() returns one of these when the site is configured with an
 * offset rather than a city. Day.js .tz() only takes IANA names, so an offset
 * has to go through .utcOffset() instead.
 */
const UTC_OFFSET_PATTERN = /^[+-]\d{2}:\d{2}$/;

/**
 * Move a GMT value into the timezone the store renders in.
 *
 * On the FluentCart source that is the viewer's own browser timezone, which is
 * how the admin has always rendered dates. On the WordPress source it is the
 * site timezone from Settings > General, so every admin sees one clock no
 * matter where they are sitting.
 */
export function toStoreTimezone(dateTime) {
    const instance = dayjs.utc(dateTime);
    const timezone = (AppConfig.get('datei18') || {}).timezone || {};

    if (timezone.source !== 'wordpress' || !timezone.site) {
        return instance.local();
    }

    try {
        return UTC_OFFSET_PATTERN.test(timezone.site)
            ? instance.utcOffset(timezone.site)
            : instance.tz(timezone.site);
    } catch (e) {
        // An unusable zone must not take a whole table down with it.
        return instance.local();
    }
}

/**
 * The current instant, in the timezone the store renders in.
 *
 * Anything comparing a rendered date against "now" has to read now through the
 * same zone the date went through, or the two sides are on different clocks.
 */
export function storeNow() {
    return toStoreTimezone(new Date().toISOString());
}

/**
 * Pick the day/month format key for a date: 'date_short' drops the year.
 *
 * Shortening only happens on the FluentCart source. When the store follows the
 * WordPress date format, that format is rendered verbatim and always carries
 * its year -- the store chose the pattern in Settings > General, and quietly
 * swapping in a yearless variant would override that choice with a guess.
 *
 * On the FluentCart source the year still has to be read in the zone the date
 * is rendered in rather than the browser's. The format source and the timezone
 * source are independent settings, so a store can keep the FluentCart literals
 * while rendering in the WordPress site timezone -- and on either side of New
 * Year those two zones sit in different years, which is what made the old
 * native-Date comparison drop a year it needed.
 *
 * @param {object} dateObject A Day.js instance already in the store timezone.
 */
export function resolveYearAwareFormat(dateObject) {
    const datei18 = AppConfig.get('datei18') || {};

    if (datei18.formats_source === 'wordpress') {
        return 'date';
    }

    return dateObject.year() === storeNow().year() ? 'date_short' : 'date';
}
