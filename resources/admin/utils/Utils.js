import translate, {translateNumber} from "@/utils/translator/Translator";
import dayjs from 'dayjs';
import utc from 'dayjs/plugin/utc.js';
import timezone from 'dayjs/plugin/timezone.js';
import AppConfig from "@/utils/Config/AppConfig";

dayjs.extend(utc);
dayjs.extend(timezone);

export default class Utils {
    static debounce(callback, wait = 300, context = null) {
        let timeout;
        return (...args) => {
            clearTimeout(timeout);
            timeout = setTimeout(() => {
                callback.bind(context)(...args)
            }, wait);
        };
    }
}


export function dateTimeI18(dateTime, format = 'MMM DD') {

    const datei18 = AppConfig.get('datei18');
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
    if(!str) {
        return str;
    }
    const config = AppConfig.get('datei18');
    if (type === 'day') {
        return config.weekdays[str] || config.weekdaysShort[str] || str;
    }

    if (type === 'month') {
        return config.months[str] || config.monthsShort[str] || str;
    }
    if (type === 'mNumber') {


        str = str.toString();
        return translateNumber(str);
    }

    return str;
}

export function isEmpty(obj) {
    if (obj === undefined || obj === null) {
        return true;
    }
    if (typeof obj === 'string') {
        return obj.length === 0;
    }
    if (Array.isArray(obj)) {
        return obj.length === 0;
    }
    if (typeof obj === 'object') {
        return Object.values(obj).length === 0;
    }
}

export function each(obj, callback) {
    if (typeof obj === 'undefined' || obj === null) {
        return;
    }

    if (Array.isArray(obj)) {
        obj.forEach((value, index) => {
            callback(value, index);
        })
    } else if (typeof obj === 'object') {
        Object.keys(obj).forEach((key, index) => {
            let value = obj[key];
            callback(value, key);
        })
    }

}

export function isObject(obj) {
    return (typeof obj === 'undefined' || obj === null) ?
        false : typeof obj === 'object';
}

export function chunk(array, size) {
    if (!Array.isArray(array) || size < 1) return [];

    const result = [];
    for (let i = 0; i < array.length; i += size) {
        result.push(array.slice(i, i + size));
    }
    return result;
}

export function parseAddress(object, type = 'billing', shouldExcludeName = false) {
    if (!object) return '';
    if (object.formatted_address) {
        object = object.formatted_address;
    }
    let address = [
        shouldExcludeName ? ' ' : object['name'],
        object['address_1'],
        object['address_2'],
        object['city'],
        object['state'],
        object['postcode'],
        object['country'],
    ];

    let newAddress = address.filter((item) => {
        return item !== null && typeof item !== 'undefined' && item.toString().trim().length > 0;
    }).join(', ');

    /* translators: %s is the address type */
    return newAddress || translate(
        'No %s address provided',
        type
    );
}

// Usage example

