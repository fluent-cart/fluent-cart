import {ElMessage, ElMessageBox, ElNotification} from "element-plus";
import dayjs from "dayjs";
import AppConfig from "@/utils/Config/AppConfig";
import Str from "@/utils/support/Str";
import utc from 'dayjs/plugin/utc';
import timezone from 'dayjs/plugin/timezone';
import Storage from "@/utils/Storage";
import translate from "@/utils/translator/Translator";

export const calculatePercent = function (currentValue, compareValue, strict = false) {
    if (currentValue == 0) {
        return -100;
    }

    if (!compareValue) {
        return '';
    }

    let percent = (currentValue - compareValue) / compareValue * 100;

    if (strict) {
        return percent.toFixed(2);
    }

    return parseInt(percent);
}

export const calculateGrowth = function (currentValue, compareValue) {
    if (compareValue === 0 || compareValue === null || compareValue === undefined) {
        return currentValue === 0 ? 0 : 100;
    }

    const growth = ((currentValue - compareValue) / compareValue) * 100;
    return Math.round(growth * 100) / 100; // round to 2 decimal places
};

const getMsg = (res, def = '') => {

    let msg = '';

    if (typeof res === 'string') {
        msg = res;
    } else if (res && res.message) {
        msg = res.message;
    } else {
        msg = Str.objectToString(res);
    }

    if (!msg) {
        msg = def || translate('Nothing....');
    }

    return msg;
}
/**
 * Handle errors and notify with a custom class.
 *
 * @param {any} response - The response object.
 * @param {string} [custom_class=''] - The custom CSS class for styling notifications. Default is an empty string.
 * @param {int} duration - The duration for displaying notification. Default is 2000
 */
export const handleError = (response, custom_class = '', duration = 1000) => {

    let errorMessage = getMsg(response, translate('Something is wrong!'));

    $notify({
        offset: 20,
        type: 'error',
        title: translate('Error'),
        message: errorMessage,
        dangerouslyUseHTMLString: true,
        customClass: custom_class,
        duration: duration
    });
}

/**
 * Handle success and notify with a custom class.
 *
 * @param {any} response - The response object.
 * @param {string} [custom_class=''] - The custom CSS class for styling notifications. Default is an empty string.
 * @param {int} duration
 */
export const handleSuccess = (response, custom_class = '', duration = 1000) => {

    let msg = getMsg(response, translate('Okay...!'));

    $notify({
        offset: 20,
        type: 'success',
        title: translate('Success'),
        message: msg,
        dangerouslyUseHTMLString: true,
        customClass: custom_class,
        duration: duration
    });
}

export const handleResponse = (response) => {

    let msg = getMsg(response, translate('Okay...!'));

    $notify({
        type: 'info',
        title: translate('Info'),
        message: msg,
        dangerouslyUseHTMLString: true,
        duration: 2000
    });
}

dayjs.extend(utc);
dayjs.extend(timezone);

let currentTimezone = dayjs.tz.guess();

function hasExplicitTimezone(value) {
    return /([Zz]|[+-]\d{2}:\d{2}|[+-]\d{4})/.test(value);
}

export const formatDate = (
    datetimeValue,
    withTime = false,
    toTimezone = null,
    fromTimezone = 'UTC',
    onlyTime = false,
) => {
    if (!datetimeValue) return '';

    const targetTimezone = toTimezone || currentTimezone;
    const sourceDate = parseDateTime(datetimeValue, fromTimezone, targetTimezone);

    if (onlyTime) {
        return formatTime(sourceDate);
    }

    const dateFormat = buildDateFormat(sourceDate);

    return withTime
        ? `${dateFormat} at ${formatTime(sourceDate)}`
        : dateFormat;
};

const parseDateTime = (datetimeValue, fromTimezone, targetTimezone) => {
    // Handle epoch timestamps (seconds or milliseconds)
    if (isEpochTimestamp(datetimeValue)) {
        const timestamp = Number(datetimeValue);
        const milliseconds = timestamp < 1e12 ? timestamp * 1000 : timestamp;
        return dayjs(milliseconds).tz(targetTimezone);
    }

    // Handle dates with explicit timezone info
    if (hasExplicitTimezone(datetimeValue)) {
        return dayjs(datetimeValue).tz(targetTimezone);
    }

    // Handle dates without timezone (assume fromTimezone)
    return dayjs.tz(datetimeValue, fromTimezone).tz(targetTimezone);
};

const isEpochTimestamp = (value) => {
    return typeof value === 'number' || /^\d+$/.test(value);
};

const buildDateFormat = (sourceDate) => {
    const year = sourceDate.year();
    const day = sourceDate.date().toString().padStart(2, '0');
    const monthName = sourceDate.format('MMM');

    const isCurrentYear = year === dayjs().year();

    return isCurrentYear
        ? `${monthName} ${day}`
        : `${day} ${monthName}, ${year}`;
};

const formatTime = (sourceDate) => {
    const hours = sourceDate.format('h');
    const minutes = sourceDate.format('mm');
    const period = sourceDate.format('a');

    return `${hours}:${minutes} ${period}`;
};


// Helper function to get month name
const getMonthName = (monthIndex) => {
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return months[monthIndex];
}

export const $notify = ElNotification;
export const $confirm = ElMessageBox.confirm;


// Capitalizes the only first letter of a given string.


/**
 * Formats the file size to display the current uploaded size relative to the total file size.
 *
 * The function converts both the current and total file sizes from bytes to either MB or KB, depending on the size.
 * It then returns a string formatted as "currentSize of totalSize", where the size is dynamically shown in either
 * megabytes (MB) or kilobytes (KB).
 *
 * @param {number} currentSize - The current size (in bytes) that has been uploaded so far.
 * @param {number} totalSize - The total file size (in bytes) of the file being uploaded.
 * @returns {string} A formatted string like "500KB of 1 MB" or "1.25 MB of 5 MB", based on the file size.
 *
 * Example usage:
 * formatFileSize(524288, 1048576) // Output: "512 KB of 1 MB"
 * formatFileSize(2097152, 5242880) // Output: "2.00 MB of 5 MB"
 */
export const formatFileSize = (currentSize, totalSize) => {
    return Storage.readableFileSizeFromBytes(currentSize) + ' of ' + Storage.readableFileSizeFromBytes(totalSize);
};

/**
 * Checks whether a given string represents a valid URL.
 * @param {string} string - The string to be checked for URL validity.
 * @returns {boolean} - True if the string is a valid URL with either 'http' or 'https' protocol, otherwise false.
 */
export const isValidURL = (string) => {
    try {
        const newUrl = new URL(string);
        return newUrl.protocol === 'http:' || newUrl.protocol === 'https:';
    } catch {
        return false;
    }
}

export const countryList = [
    {value: 'AF', label: 'Afghanistan'},
    {value: 'AX', label: 'Åland Islands'},
    {value: 'AL', label: 'Albania'},
    {value: 'DZ', label: 'Algeria'},
    {value: 'AS', label: 'American Samoa'},
    {value: 'AD', label: 'Andorra'},
    {value: 'AO', label: 'Angola'},
    {value: 'AI', label: 'Anguilla'},
    {value: 'AQ', label: 'Antarctica'},
    {value: 'AG', label: 'Antigua and Barbuda'},
    {value: 'AR', label: 'Argentina'},
    {value: 'AM', label: 'Armenia'},
    {value: 'AW', label: 'Aruba'},
    {value: 'AU', label: 'Australia'},
    {value: 'AT', label: 'Austria'},
    {value: 'AZ', label: 'Azerbaijan'},
    {value: 'BS', label: 'Bahamas'},
    {value: 'BH', label: 'Bahrain'},
    {value: 'BD', label: 'Bangladesh'},
    {value: 'BB', label: 'Barbados'},
    {value: 'BY', label: 'Belarus'},
    {value: 'BE', label: 'Belgium'},
    {value: 'PW', label: 'Belau'},
    {value: 'BZ', label: 'Belize'},
    {value: 'BJ', label: 'Benin'},
    {value: 'BM', label: 'Bermuda'},
    {value: 'BT', label: 'Bhutan'},
    {value: 'BO', label: 'Bolivia'},
    {value: 'BQ', label: 'Bonaire, Saint Eustatius and Saba'},
    {value: 'BA', label: 'Bosnia and Herzegovina'},
    {value: 'BW', label: 'Botswana'},
    {value: 'BV', label: 'Bouvet Island'},
    {value: 'BR', label: 'Brazil'},
    {value: 'IO', label: 'British Indian Ocean Territory'},
    {value: 'VG', label: 'British Virgin Islands'},
    {value: 'BN', label: 'Brunei'},
    {value: 'BG', label: 'Bulgaria'},
    {value: 'BF', label: 'Burkina Faso'},
    {value: 'BI', label: 'Burundi'},
    {value: 'KH', label: 'Cambodia'},
    {value: 'CM', label: 'Cameroon'},
    {value: 'CA', label: 'Canada'},
    {value: 'CV', label: 'Cape Verde'},
    {value: 'KY', label: 'Cayman Islands'},
    {value: 'CF', label: 'Central African Republic'},
    {value: 'TD', label: 'Chad'},
    {value: 'CL', label: 'Chile'},
    {value: 'CN', label: 'China'},
    {value: 'CX', label: 'Christmas Island'},
    {value: 'CC', label: 'Cocos (Keeling) Islands'},
    {value: 'CO', label: 'Colombia'},
    {value: 'KM', label: 'Comoros'},
    {value: 'CG', label: 'Congo (Brazzaville)'},
    {value: 'CD', label: 'Congo (Kinshasa)'},
    {value: 'CK', label: 'Cook Islands'},
    {value: 'CR', label: 'Costa Rica'},
    {value: 'HR', label: 'Croatia'},
    {value: 'CU', label: 'Cuba'},
    {value: 'CW', label: 'Curaçao'},
    {value: 'CY', label: 'Cyprus'},
    {value: 'CZ', label: 'Czech Republic'},
    {value: 'DK', label: 'Denmark'},
    {value: 'DJ', label: 'Djibouti'},
    {value: 'DM', label: 'Dominica'},
    {value: 'DO', label: 'Dominican Republic'},
    {value: 'EC', label: 'Ecuador'},
    {value: 'EG', label: 'Egypt'},
    {value: 'SV', label: 'El Salvador'},
    {value: 'GQ', label: 'Equatorial Guinea'},
    {value: 'ER', label: 'Eritrea'},
    {value: 'EE', label: 'Estonia'},
    {value: 'ET', label: 'Ethiopia'},
    {value: 'FK', label: 'Falkland Islands'},
    {value: 'FO', label: 'Faroe Islands'},
    {value: 'FJ', label: 'Fiji'},
    {value: 'FI', label: 'Finland'},
    {value: 'FR', label: 'France'},
    {value: 'GF', label: 'French Guiana'},
    {value: 'PF', label: 'French Polynesia'},
    {value: 'TF', label: 'French Southern Territories'},
    {value: 'GA', label: 'Gabon'},
    {value: 'GM', label: 'Gambia'},
    {value: 'GE', label: 'Georgia'},
    {value: 'DE', label: 'Germany'},
    {value: 'GH', label: 'Ghana'},
    {value: 'GI', label: 'Gibraltar'},
    {value: 'GR', label: 'Greece'},
    {value: 'GL', label: 'Greenland'},
    {value: 'GD', label: 'Grenada'},
    {value: 'GP', label: 'Guadeloupe'},
    {value: 'GU', label: 'Guam'},
    {value: 'GT', label: 'Guatemala'},
    {value: 'GG', label: 'Guernsey'},
    {value: 'GN', label: 'Guinea'},
    {value: 'GW', label: 'Guinea-Bissau'},
    {value: 'GY', label: 'Guyana'},
    {value: 'HT', label: 'Haiti'},
    {value: 'HM', label: 'Heard Island and McDonald Islands'},
    {value: 'HN', label: 'Honduras'},
    {value: 'HK', label: 'Hong Kong'},
    {value: 'HU', label: 'Hungary'},
    {value: 'IS', label: 'Iceland'},
    {value: 'IN', label: 'India'},
    {value: 'ID', label: 'Indonesia'},
    {value: 'IR', label: 'Iran'},
    {value: 'IQ', label: 'Iraq'},
    {value: 'IE', label: 'Ireland'},
    {value: 'IM', label: 'Isle of Man'},
    {value: 'IL', label: 'Israel'},
    {value: 'IT', label: 'Italy'},
    {value: 'CI', label: 'Ivory Coast'},
    {value: 'JM', label: 'Jamaica'},
    {value: 'JP', label: 'Japan'},
    {value: 'JE', label: 'Jersey'},
    {value: 'JO', label: 'Jordan'},
    {value: 'KZ', label: 'Kazakhstan'},
    {value: 'KE', label: 'Kenya'},
    {value: 'KI', label: 'Kiribati'},
    {value: 'KW', label: 'Kuwait'},
    {value: 'KG', label: 'Kyrgyzstan'},
    {value: 'LA', label: 'Laos'},
    {value: 'LV', label: 'Latvia'},
    {value: 'LB', label: 'Lebanon'},
    {value: 'LS', label: 'Lesotho'},
    {value: 'LR', label: 'Liberia'},
    {value: 'LY', label: 'Libya'},
    {value: 'LI', label: 'Liechtenstein'},
    {value: 'LT', label: 'Lithuania'},
    {value: 'LU', label: 'Luxembourg'},
    {value: 'MO', label: 'Macao S.A.R., China'},
    {value: 'MK', label: 'Macedonia'},
    {value: 'MG', label: 'Madagascar'},
    {value: 'MW', label: 'Malawi'},
    {value: 'MY', label: 'Malaysia'},
    {value: 'MV', label: 'Maldives'},
    {value: 'ML', label: 'Mali'},
    {value: 'MT', label: 'Malta'},
    {value: 'MH', label: 'Marshall Islands'},
    {value: 'MQ', label: 'Martinique'},
    {value: 'MR', label: 'Mauritania'},
    {value: 'MU', label: 'Mauritius'},
    {value: 'YT', label: 'Mayotte'},
    {value: 'MX', label: 'Mexico'},
    {value: 'FM', label: 'Micronesia'},
    {value: 'MD', label: 'Moldova'},
    {value: 'MC', label: 'Monaco'},
    {value: 'MN', label: 'Mongolia'},
    {value: 'ME', label: 'Montenegro'},
    {value: 'MS', label: 'Montserrat'},
    {value: 'MA', label: 'Morocco'},
    {value: 'MZ', label: 'Mozambique'},
    {value: 'MM', label: 'Myanmar'},
    {value: 'NA', label: 'Namibia'},
    {value: 'NR', label: 'Nauru'},
    {value: 'NP', label: 'Nepal'},
    {value: 'NL', label: 'Netherlands'},
    {value: 'NC', label: 'New Caledonia'},
    {value: 'NZ', label: 'New Zealand'},
    {value: 'NI', label: 'Nicaragua'},
    {value: 'NE', label: 'Niger'},
    {value: 'NG', label: 'Nigeria'},
    {value: 'NU', label: 'Niue'},
    {value: 'NF', label: 'Norfolk Island'},
    {value: 'MP', label: 'Northern Mariana Islands'},
    {value: 'KP', label: 'North Korea'},
    {value: 'NO', label: 'Norway'},
    {value: 'OM', label: 'Oman'},
    {value: 'PK', label: 'Pakistan'},
    {value: 'PS', label: 'Palestinian Territory'},
    {value: 'PA', label: 'Panama'},
    {value: 'PG', label: 'Papua New Guinea'},
    {value: 'PY', label: 'Paraguay'},
    {value: 'PE', label: 'Peru'},
    {value: 'PH', label: 'Philippines'},
    {value: 'PN', label: 'Pitcairn'},
    {value: 'PL', label: 'Poland'},
    {value: 'PT', label: 'Portugal'},
    {value: 'PR', label: 'Puerto Rico'},
    {value: 'QA', label: 'Qatar'},
    {value: 'RE', label: 'Reunion'},
    {value: 'RO', label: 'Romania'},
    {value: 'RU', label: 'Russia'},
    {value: 'RW', label: 'Rwanda'},
    {value: 'BL', label: 'Saint Barthélemy'},
    {value: 'SH', label: 'Saint Helena'},
    {value: 'KN', label: 'Saint Kitts and Nevis'},
    {value: 'LC', label: 'Saint Lucia'},
    {value: 'MF', label: 'Saint Martin (French part)'},
    {value: 'SX', label: 'Saint Martin (Dutch part)'},
    {value: 'PM', label: 'Saint Pierre and Miquelon'},
    {value: 'VC', label: 'Saint Vincent and the Grenadines'},
    {value: 'SM', label: 'San Marino'},
    {value: 'ST', label: 'São Tomé and Príncipe'},
    {value: 'SA', label: 'Saudi Arabia'},
    {value: 'SN', label: 'Senegal'},
    {value: 'RS', label: 'Serbia'},
    {value: 'SC', label: 'Seychelles'},
    {value: 'SL', label: 'Sierra Leone'},
    {value: 'SG', label: 'Singapore'},
    {value: 'SK', label: 'Slovakia'},
    {value: 'SI', label: 'Slovenia'},
    {value: 'SB', label: 'Solomon Islands'},
    {value: 'SO', label: 'Somalia'},
    {value: 'ZA', label: 'South Africa'},
    {value: 'GS', label: 'South Georgia/Sandwich Islands'},
    {value: 'KR', label: 'South Korea'},
    {value: 'SS', label: 'South Sudan'},
    {value: 'ES', label: 'Spain'},
    {value: 'LK', label: 'Sri Lanka'},
    {value: 'SD', label: 'Sudan'},
    {value: 'SR', label: 'Suriname'},
    {value: 'SJ', label: 'Svalbard and Jan Mayen'},
    {value: 'SZ', label: 'Swaziland'},
    {value: 'SE', label: 'Sweden'},
    {value: 'CH', label: 'Switzerland'},
    {value: 'SY', label: 'Syria'},
    {value: 'TW', label: 'Taiwan'},
    {value: 'TJ', label: 'Tajikistan'},
    {value: 'TZ', label: 'Tanzania'},
    {value: 'TH', label: 'Thailand'},
    {value: 'TL', label: 'Timor-Leste'},
    {value: 'TG', label: 'Togo'},
    {value: 'TK', label: 'Tokelau'},
    {value: 'TO', label: 'Tonga'},
    {value: 'TT', label: 'Trinidad and Tobago'},
    {value: 'TN', label: 'Tunisia'},
    {value: 'TR', label: 'Turkey'},
    {value: 'TM', label: 'Turkmenistan'},
    {value: 'TC', label: 'Turks and Caicos Islands'},
    {value: 'TV', label: 'Tuvalu'},
    {value: 'UG', label: 'Uganda'},
    {value: 'UA', label: 'Ukraine'},
    {value: 'AE', label: 'United Arab Emirates'},
    {value: 'GB', label: 'United Kingdom (UK)'},
    {value: 'US', label: 'United States (US)'},
    {value: 'UM', label: 'United States (US) Minor Outlying Islands'},
    {value: 'VI', label: 'United States (US) Virgin Islands'},
    {value: 'UY', label: 'Uruguay'},
    {value: 'UZ', label: 'Uzbekistan'},
    {value: 'VU', label: 'Vanuatu'},
    {value: 'VA', label: 'Vatican'},
    {value: 'VE', label: 'Venezuela'},
    {value: 'VN', label: 'Vietnam'},
    {value: 'WF', label: 'Wallis and Futuna'},
    {value: 'EH', label: 'Western Sahara'},
    {value: 'WS', label: 'Samoa'},
    {value: 'YE', label: 'Yemen'},
    {value: 'ZM', label: 'Zambia'},
    {value: 'ZW', label: 'Zimbabwe'}
];


export const epochToHumanDate = (epochTimestamp, showTime = true) => {
    if (!epochTimestamp) return;
    let dateTime;

    if (typeof epochTimestamp === 'number' || (typeof epochTimestamp === 'string' && /^\d+$/.test(epochTimestamp))) {
        // Treat input as epoch timestamp
        dateTime = dayjs.unix(Number(epochTimestamp));
    } else if (typeof epochTimestamp === 'string' && !isNaN(Date.parse(epochTimestamp))) {
        // Treat input as date string
        dateTime = dayjs(epochTimestamp);
    } else {
        // Handle invalid input
        return 'Invalid date';
    }
    // return dateTime.format("Do MMMM YYYY [at] HH:mm A"); // 20th January 2026 at 12:33 PM
    // I want the date to be in the format of Jan 20, 2025 at 12:31 am
    if (showTime) {
        // Return date with time
        return dateTime.format("MMM DD, YYYY [at] hh:mm a");
    } else {
        // Return date without time
        return dateTime.format("MMM DD, YYYY");
    }
}

export const getCardBrand = (name, type = '') => {
    const brandLogos = {
        visa: 'images/credit-card/visa.svg',
        mastercard: 'images/credit-card/mastercard.svg',
        unionpay: 'images/credit-card/unionpay.svg',
        amex: 'images/credit-card/amex.svg',
    };
    if (name in brandLogos) {
        return AppConfig.assetUrl(brandLogos[name])
    }

    if (type === 'card') {
        return AppConfig.assetUrl('images/credit-card/card.svg');
    }

    return null;

};

export const isSummaryLastItem = (data, index) => {
    const totalItems = data.length;

    if (totalItems % 2 === 0) {
        // Even number of items - apply to last two items
        return index >= totalItems - 2;
    } else {
        // Odd number of items - apply only to last item
        return index === totalItems - 1;
    }
};

export const formatOrderItems = (items) => {
    const map = {};
    const result = [];

    // create a map of all items
    items.forEach(item => {
        item.bundle_items = [];
        map[item.id] = item;
    });

    // attach bundle items to parent
    items.forEach(item => {
        const parentId = item?.line_meta?.bundle_parent_item_id;

        if (parentId && map[parentId]) {
            map[parentId].bundle_items.push(item);
        } else {
            result.push(item); // parent / normal item
        }
    });

    return result;
};
