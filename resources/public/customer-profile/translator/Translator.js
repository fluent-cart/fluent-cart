import dayjs from 'dayjs';
import utc from 'dayjs/plugin/utc.js';
import timezone from 'dayjs/plugin/timezone.js';

dayjs.extend(utc);
dayjs.extend(timezone);

export default function translate(string) {

    string = window.fluentcart_customer_profile_vars?.trans[string] || string;

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

export function dateTimeI18(dateTime, format = 'MMM DD') {

    if (format === 'MMM DD') {
        const dateObject = new Date(dateTime);
        if(dateObject.getFullYear() !== new Date().getFullYear()) {
            format = 'MMM DD, YYYY';
        }
    }

    const datei18 = window.fluentcart_customer_profile_vars.datei18;
    const date = dayjs(dateTime).locale({
        name: 'fluent_date_time',
        weekdays: Object.values(datei18.weekdays),
        weekdaysShort: Object.values(datei18.weekdaysShort),
        months: Object.values(datei18.months),
        monthsShort: Object.values(datei18.monthsShort),
        meridiem: (hour, minute, isLowercase) => {
            const amText = datei18.am || 'AM';
            const pmText = datei18.pm || 'PM';
            const result = hour < 12 ? amText : pmText;
            return isLowercase ? result.toLowerCase() : result;
        }
    }).format(format);

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

