import translate, {translateNumber} from "@/utils/translator/Translator";
import dayjs from 'dayjs';
import utc from 'dayjs/plugin/utc.js';
import timezone from 'dayjs/plugin/timezone.js';
import AppConfig from "@/utils/Config/AppConfig";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";

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
    const date = dayjs.utc(dateTime).local().locale({
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


/**
 * Amount for a chart tooltip.
 *
 * Axis labels stay abbreviated ($1K, $5K) because they only have to give a
 * sense of scale. Tooltip amounts must not be: abbreviating them there mixes
 * three formats in one list ("$2.22K", "$1.2K", "$103.38") and rounds the rows
 * so they no longer add up to the total printed under them.
 *
 * Takes cents, like everything else that handles money here. Charts that plot
 * dollars (value / 100) pass `param.value * 100`.
 *
 * @param {number} amountInCents
 * @param {string|null} currencyName order currency, when the chart is not in the store currency
 */
export function chartTooltipAmount(amountInCents, currencyName = null) {
    return CurrencyFormatter.formatNumber(Math.round(Number(amountInCents) || 0), true, false, currencyName);
}

/**
 * Places a chart tooltip on whichever side of the hovered point has more room,
 * so it stops covering the bars and points it is describing. Pass as the
 * `position` of an ECharts tooltip, together with `confine: true`.
 */
export function chartTooltipPosition(point, params, dom, rect, size) {
    const gap = 16;
    const chartWidth = size.viewSize[0];
    const chartHeight = size.viewSize[1];
    const boxWidth = size.contentSize[0];
    const boxHeight = size.contentSize[1];

    const toLeft = point[0] - gap - boxWidth;
    const toRight = point[0] + gap;

    let left = point[0] > (chartWidth / 2) ? toLeft : toRight;

    if (left < gap) {
        left = toRight;
    } else if (left + boxWidth > chartWidth - gap) {
        left = toLeft;
    }

    left = Math.min(Math.max(gap, left), Math.max(gap, chartWidth - boxWidth - gap));

    const top = Math.min(
        Math.max(gap, point[1] - (boxHeight / 2)),
        Math.max(gap, chartHeight - boxHeight - gap)
    );

    return [left, top];
}

/**
 * Hover highlight for the category under the cursor.
 *
 * Bars get a shadow band, because the band is sized to the category and so
 * lands exactly on the hovered column. Lines keep the thin pointer: the same
 * band under a line chart with few categories covers a quarter of the plot.
 *
 * The band colour has to stay translucent — ECharts paints the axis pointer
 * over the series, so an opaque band hides the bar it is meant to highlight.
 */
export function chartAxisPointer(isDarkTheme, darkColor, lightColor = null, chartType = 'line') {
    if (chartType === 'bar') {
        return {
            type: 'shadow',
            shadowStyle: {
                color: isDarkTheme ? darkColor : 'rgba(103, 129, 168, 0.10)'
            }
        };
    }

    return {
        type: 'line',
        lineStyle: {
            type: 'solid',
            width: 2,
            color: isDarkTheme ? darkColor : lightColor
        }
    };
}
