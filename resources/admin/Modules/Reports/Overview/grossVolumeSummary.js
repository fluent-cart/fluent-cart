import dayjs from "dayjs";
import translate, {pluralizeTranslate} from "@/utils/translator/Translator";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import {resolveDateFormat, fluentDayjsLocale} from "@/utils/dateFormats";

const amount = (value, currency) => ({
    short: CurrencyFormatter.formatScaled(value, true, false, currency),
    exact: CurrencyFormatter.formatNumber(value, true, false, currency),
});

const change = (percentage, label) => {
    const rounded = Math.abs(percentage).toFixed(1);
    const direction = Number(rounded) === 0 ? 'flat' : (percentage > 0 ? 'up' : 'down');

    return {direction, percentage: `${rounded}%`, label};
};

// The server sends null growth when the prior-year figure is missing or zero.
const yearOverYear = (growth) => {
    if (growth === null || growth === undefined) {
        return {
            /* translators: shown instead of a year-over-year percentage */
            text: translate('no prior-year figure')
        };
    }

    /* translators: follows a percentage, e.g. "▲4.2% year-over-year" */
    return change(Number(growth), translate('year-over-year'));
};

const monthName = (date) => date.locale(fluentDayjsLocale()).format(resolveDateFormat('month_long'));

export function monthlySummary(months, currency) {
    const now = dayjs();
    const previous = now.subtract(1, 'month');
    const currentData = (months || {})[now.format('YYYY-MM')] || {};
    const previousData = (months || {})[previous.format('YYYY-MM')] || {};

    return {
        message: '',
        periods: [
            {
                /* translators: %s - current calendar month name, e.g. October */
                label: translate('This month (%s) so far', monthName(now)),
                amount: amount(Number(currentData.current) || 0, currency),
            },
            {
                /* translators: %s - previous calendar month name, e.g. September */
                label: translate('Last month (%s)', monthName(previous)),
                amount: amount(Number(previousData.current) || 0, currency),
                change: yearOverYear(previousData.yoy_growth),
            },
        ],
    };
}

export function quarterlySummary(quarters, currency) {
    quarters = quarters || {};
    const keys = Object.keys(quarters);

    if (keys.every((key) => !Number(quarters[key]?.current))) {
        // prev_year is the same quarter a year earlier, the only trace of older sales in this payload.
        const lastSoldKey = [...keys].reverse().find((key) => Number(quarters[key]?.prev_year));
        if (!lastSoldKey) {
            return {message: translate('No sales in the last two years.'), periods: []};
        }

        const [lastSoldQuarter, lastSoldYear] = lastSoldKey.split('-');

        return {
            /* translators: %s - quarter of the last sale, e.g. Q3-2025 */
            message: translate('No sales since %s.', `${lastSoldQuarter}-${Number(lastSoldYear) - 1}`),
            periods: [],
        };
    }

    const now = dayjs();
    const year = now.year();
    const quarter = Math.floor(now.month() / 3) + 1;
    const daysLeft = now.date(1).month((quarter * 3) - 1).endOf('month').diff(now, 'day');
    const currentKey = `Q${quarter}-${year}`;
    const previousKey = quarter === 1 ? `Q4-${year - 1}` : `Q${quarter - 1}-${year}`;
    const currentData = quarters[currentKey] || {};
    const previousData = quarters[previousKey] || {};
    const currentValue = Number(currentData.current) || 0;
    const previousValue = Number(previousData.current) || 0;

    let quarterChange = null;
    if (previousValue) {
        /* translators: %s - previous quarter, e.g. Q3-2026; follows a percentage, e.g. "▲12.0% vs Q3-2026" */
        quarterChange = change(((currentValue - previousValue) / previousValue) * 100, translate('vs %s', previousKey));
    } else if (currentValue) {
        quarterChange = {
            /* translators: %s - previous quarter, e.g. Q3-2026 */
            text: translate('up from nothing in %s', previousKey)
        };
    } else {
        quarterChange = {
            /* translators: %s - previous quarter, e.g. Q3-2026 */
            text: translate('no change compared to %s', previousKey)
        };
    }

    return {
        message: '',
        periods: [
            {
                /* translators: %s - current calendar quarter, e.g. Q4-2026 */
                label: translate('This quarter (%s) so far', currentKey),
                amount: amount(currentValue, currency),
                change: quarterChange,
                /* translators: %s - number of days left in the current calendar quarter */
                note: pluralizeTranslate('%s day left', '%s days left', daysLeft, '%s days left'),
            },
            {
                /* translators: %s - previous calendar quarter, e.g. Q3-2026 */
                label: translate('Last quarter (%s)', previousKey),
                amount: amount(previousValue, currency),
                change: yearOverYear(previousData.yy_growth),
            },
        ],
    };
}
