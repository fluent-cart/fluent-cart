import dayjs from 'dayjs';
import utc from 'dayjs/plugin/utc.js';
import timezone from 'dayjs/plugin/timezone.js';
import advancedFormat from 'dayjs/plugin/advancedFormat.js';
import weekOfYear from 'dayjs/plugin/weekOfYear.js';

dayjs.extend(utc);
dayjs.extend(timezone);
// A WordPress date format may use an ordinal day ('jS') or a week number ('W'),
// which convert to Day.js tokens these plugins provide.
dayjs.extend(advancedFormat);
dayjs.extend(weekOfYear);

export default function translate(string) {

    // Optional-chain `trans` itself too — the localized vars object may
    // ship without it (e.g. the subscription purchase-history view loads
    // a leaner config), in which case `null[string]` would throw and
    // crash every Badge / status component on the page.
    string = window.fluentcart_customer_profile_vars?.trans?.[string] || string;

    // Prepare the arguments, excluding the first one (the string itself)
    const args = Array.prototype.slice.call(arguments, 1);

    if (args.length === 0) {
        return string;
    }

    // Regular expression to match %s, %d, or %1s, %2s,  %1$s etc.
    const regex = /%(\d*\$?)s|%d/g;

    // Replace function to handle each match found by the regex
    let argIndex = 0; // Keep track of the argument index for non-numbered placeholders
    string = string.replace(regex, (match, number) => {
        // If it's a numbered placeholder, use the number to find the corresponding argument
        if (number) {
            const index = parseInt(number, 10) - 1; // Convert to zero-based index
            return index < args.length ? args[index] : match; // Replace or keep the placeholder
        } else {
            // For non-numbered placeholders, use the next argument in the array
            return argIndex < args.length ? args[argIndex++] : match; // Replace or keep the placeholder
        }
    });

    return string;
}


export function pluralizeTranslate(singular, plural, count, empty = null) {
    let number = parseInt(count.toString().replace(/,/g, ''), 10);
    if (number > 1) {
        return translate(plural, count);
    }
    if (number === 0) {
        return translate(empty ?? singular, count);
    }
    return translate(singular, count);
}

export function translateNumber(number) {
    const config = window.fluentcart_customer_profile_vars.datei18;
    number = number.toString();
    const numbers = config.numericSystem || '0_1_2_3_4_5_6_7_8_9';
    const numberArr = numbers.split('_');
    const translated = number.split('').map((s) => {
        return numberArr[s] || s;
    });

    return translated.join('');

}

/**
 * Day.js patterns used when the store has not shipped `datei18.formats`.
 *
 * These are the literals this helper used before the formats became
 * configurable, so an older bundle keeps rendering exactly as it did.
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

    const datei18 = window.fluentcart_customer_profile_vars?.datei18 || {};

    // On the FluentCart source this dashboard keeps its own literals rather than
    // the converted PHP ones. The two were never identical -- PHP's 'h:i A' pads
    // the hour, this bundle's 'h:mm A' does not -- so reading the converted
    // formats here would quietly renumber every time on the page as 03:30 PM.
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
 * On the FluentCart source that is the customer's own browser timezone, which
 * is how this dashboard has always rendered dates. On the WordPress source it
 * is the site timezone from Settings > General, so the dashboard agrees with
 * the invoice and the e-mails.
 */
export function toStoreTimezone(dateTime) {
    const instance = dayjs.utc(dateTime);
    const timezone = window.fluentcart_customer_profile_vars?.datei18?.timezone || {};

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
 * The current instant, in the timezone this dashboard renders in.
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
    const datei18 = window.fluentcart_customer_profile_vars?.datei18 || {};

    if (datei18.formats_source === 'wordpress') {
        return 'date';
    }

    return dateObject.year() === storeNow().year() ? 'date_short' : 'date';
}

export function dateTimeI18(dateTime, format = 'date_short') {

    const dateObject = toStoreTimezone(dateTime);

    // Whether the compact format may drop the year is the store's call, and it
    // is decided in the timezone dateObject is already in rather than through a
    // browser-local native Date.
    if (format === 'date_short') {
        format = resolveYearAwareFormat(dateObject);
    }

    const datei18 = window.fluentcart_customer_profile_vars?.datei18 || {};
    const date = dateObject.locale({
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
    }).format(resolveDateFormat(format));

    return getDateTimeStringI18(date, 'mNumber');
}

export const getDateTimeStringI18 = function (str, type) {
    if (!str) {
        return str;
    }
    const config = window.fluentcart_customer_profile_vars.datei18;
    if (type === 'day') {
        return config.weekdays[str] || config.weekdaysShort[str] || str;
    }

    if (type === 'month') {
        return config.months[str] || config.monthsShort[str] || str;
    }
    if (type === 'mNumber') {


        str = str.toString();
        const number = str.split('').map((s) => {
            return s !== ' ' ? translateNumber(s) : s;

        });
        return number.join('');
    }

    return str;
}

