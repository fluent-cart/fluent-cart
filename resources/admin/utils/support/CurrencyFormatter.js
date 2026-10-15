import Arr from "@/utils/support/Arr";
import dayjs from "dayjs";
import {resolveDateFormat, fluentDayjsLocale} from "@/utils/dateFormats";
import translate from "@/utils/translator/Translator";
import {translateNumber} from "@/utils/translator/Translator";
import AppConfig from "@/utils/Config/AppConfig";

export default class CurrencyFormatter {
    static #locale = null;
    static #decimalSeparator;

    static {
        AppConfig.onShopUpdate(() => CurrencyFormatter.setLocale());
    }

    static #currencyConfig() {
        return {
            signs:    AppConfig.get('currency_signs', {}),
            sign:     AppConfig.get('shop.currency_sign', '$'),
            position: AppConfig.get('shop.currency_position', 'before'),
        };
    }

    // Decimal places belong to the currency being rendered, not the store's.
    // Falls back to the store currency, then to 2.
    static #decimalsFor(currencyName) {
        const decimalsMap = AppConfig.get('currency_decimals', {});
        const code = (currencyName || AppConfig.get('shop.currency', '')).toUpperCase();
        const decimals = Arr.get(decimalsMap, code);
        return typeof decimals === 'number' ? decimals : 2;
    }

    static setLocale() {
        const wpLocale = (AppConfig.get('wp_locale', 'en-US')).replace('_', '-');
        const shopConfig = AppConfig.get('shop', {});
        CurrencyFormatter.#decimalSeparator = shopConfig.decimal_separator || null;
        CurrencyFormatter.#locale = wpLocale;

        if (shopConfig.decimal_separator) {
            CurrencyFormatter.#locale = shopConfig.decimal_separator === 'comma' ? 'de-DE' : 'en-US';
        } else {
            CurrencyFormatter.#locale = navigator.language || navigator.languages?.[0] || 'en-US';
        }
    }

    static formatForOrder(amount, orderOrCurrency, withCurrency = true, hideEmpty = false) {
        let currency = null;
        if (typeof orderOrCurrency === 'string') {
            currency = orderOrCurrency || null;
        } else if (orderOrCurrency && orderOrCurrency.currency) {
            currency = orderOrCurrency.currency;
        }
        return CurrencyFormatter.formatNumber(amount, withCurrency, hideEmpty, currency);
    }

    // Core method to format a single amount (similar to your formatNumber)
    static formatNumber(amount, withCurrency = true, hideEmpty = false, currencyName = null) {
        if (!amount && hideEmpty) {
            return '';
        }
        const { signs: currencySigns, sign: currencySign, position } = CurrencyFormatter.#currencyConfig();
        const currency = Arr.get(currencySigns, (currencyName || '').toUpperCase(), currencySign);

        let formatted = new Intl.NumberFormat(CurrencyFormatter.#locale, {
            minimumFractionDigits: 0,
            maximumFractionDigits: CurrencyFormatter.#decimalsFor(currencyName)
        }).format(amount ? amount / 100 : 0);

        formatted = translateNumber(formatted);

        if (!withCurrency) {
            return formatted;
        }

        return position === 'before' ? `${currency}${formatted}` : `${formatted}${currency}`;
    }

    static #replaceDecimalSeparator(value) {
        return value;
    }


    // Format amount with scaling to K, M, B
    // Format amount with scaling to K, M, B, respecting currency sign and position
    static formatScaled(amount, withCurrency = true, hideEmpty = false, currencyName = '') {
        if (!amount && hideEmpty) {
            return '';
        }

        const { signs: currencySigns, sign: currencySign, position } = CurrencyFormatter.#currencyConfig();
        const currency = Arr.get(currencySigns, (currencyName || '').toUpperCase(), currencySign);

        // Format the scaled number using Intl.NumberFormat for locale-aware formatting
        let formattedAmount;
        let suffix = '';

        if (Math.abs(amount) >= 100000000000) { // Billion in cents
            amount = amount / 1000000000;
            suffix = 'B';
        } else if (Math.abs(amount) >= 100000000) { // Million in cents
            amount = amount / 1000000;
            suffix = 'M';
        } else if (Math.abs(amount) >= 100000) { // Thousand in cents
            amount = amount / 1000;
            suffix = 'K';
        } else {
            return CurrencyFormatter.formatNumber(amount, withCurrency, hideEmpty, currencyName);
        }


        // Scaled values keep up to 2 fraction digits regardless of the
        // currency's own decimals — "¥7.4K", not "¥7K".
        formattedAmount = translateNumber(new Intl.NumberFormat(CurrencyFormatter.#locale, {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2
        }).format(amount ? amount / 100 : 0));

        if(!withCurrency) {
            return formattedAmount;
        }

        // Combine the formatted amount with the suffix
        const valueWithSuffix = `${formattedAmount}${suffix}`;



        // Apply currency sign based on position
        if (position === 'before') {
            return `${currency}${valueWithSuffix}`;
        }
        return `${valueWithSuffix}${currency}`;
    }

    static formatScaledChart(amount, currencyName = '') {
        if (!amount) {
            return '0';
        }

        const { signs: currencySigns, sign: currencySign, position } = CurrencyFormatter.#currencyConfig();
        const currency = Arr.get(currencySigns, (currencyName || '').toUpperCase(), currencySign);

        let suffix = '';

        if (Math.abs(amount) >= 100000000000) {
            amount = amount / 1000000000;
            suffix = 'B';
        } else if (Math.abs(amount) >= 100000000) {
            amount = amount / 1000000;
            suffix = 'M';
        } else if (Math.abs(amount) >= 100000) {
            amount = amount / 1000;
            suffix = 'K';
        }

        const formatted = new Intl.NumberFormat(undefined, {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2
        }).format(amount/100);

        const value = `${formatted}${suffix}`;

        return position === 'before'
            ? `${currency}${value}`
            : `${value}${currency}`;
    }

    static scaled(amountInDollars, withCurrency = true, hideEmpty = false, currencyName = '') {
        return CurrencyFormatter.formatScaled(amountInDollars * 100, withCurrency, hideEmpty, currencyName);
    }

    static scaledChart(amountInDollars, withCurrency = true, hideEmpty = false, currencyName = '') {
        return CurrencyFormatter.formatScaledChart(amountInDollars * 100, currencyName);
    }

    // Format a list of amounts
    static formatBulk(amounts, useScaling = false, currencyName = '', hideEmpty = false) {
        return amounts.map(amount => {
            if (useScaling) {
                return CurrencyFormatter.formatScaled(amount, true, hideEmpty, currencyName);
            }
            return CurrencyFormatter.formatNumber(amount, true, hideEmpty, currencyName);
        });
    }

    static get currencySign() {
        return CurrencyFormatter.#currencyConfig().sign;
    }

    static get currencyPosition() {
        return CurrencyFormatter.#currencyConfig().position;
    }

    static get locale() {
        return CurrencyFormatter.#locale;
    }

    // The server sends null growth when the prior-year figure is missing or zero.
    static #yoyChange(growth) {
        if (growth === null || growth === undefined) {
            return `<span class="font-bold">${translate('with no prior-year figure to compare')}</span>`;
        }

        const value = Number(growth);
        const text = value >= 0
            ?
            /* translators: %s - YoY growth percentage */
            translate('an increase of %s year-over-year', `${value.toFixed(1)}%`)
            :
            /* translators: %s - YoY growth percentage */
            translate('a decrease of %s year-over-year', `${Math.abs(value).toFixed(1)}%`);

        return `<span class="font-bold ${value >= 0 ? 'text-green-500' : 'text-red-500'}">${text}</span>`;
    }

    static generateVolumeSummary(data, isDate = false) {

        if (isDate) {
            const now = dayjs();
            const currentMonthIndex = now.format('YYYY-MM'); // e.g., "2025-05" for May 2025
            const previousMonthIndex = now.subtract(1, 'month').format('YYYY-MM'); // e.g., "2025-04" for April 2025
            const currentMonthName = now.locale(fluentDayjsLocale()).format(resolveDateFormat('month_long'));
            const previousMonthName = now.subtract(1, 'month').locale(fluentDayjsLocale()).format(resolveDateFormat('month_long'));

            // Extract data for current and previous months from data.gross_revenue
            const currentData = data[currentMonthIndex] || {current: 0, yoy_growth: null};
            const previousData = data[previousMonthIndex] || {current: 0, yoy_growth: null};

            // Format the volumes using formatNumber
            const formattedPreviousVolume = CurrencyFormatter.formatScaled(previousData.current);
            const formattedCurrentVolume = CurrencyFormatter.formatScaled(currentData.current);

            /* translators: %1$s - previous month name, %2$s - previous month volume, %3$s - YoY change, %4$s - current month name, %5$s - current month volume */
            return translate('%1$s gross volume was %2$s, %3$s. Gross volume for the month of %4$s so far is %5$s. Here\'s the trend over the previous 12 months.',
                `<span class="font-bold">${previousMonthName}</span>`,
                `<span class="font-bold fct-custom-tooltip">${formattedPreviousVolume}<span class="fct-custom-tooltip-content">${CurrencyFormatter.formatNumber(previousData.current)}</span></span>`,
                CurrencyFormatter.#yoyChange(previousData.yoy_growth),
                currentMonthName,
                `<span class="font-bold fct-custom-tooltip">${formattedCurrentVolume}<span class="fct-custom-tooltip-content">${CurrencyFormatter.formatNumber(currentData.current)}</span></span>`
            );
        } else {
            const now = dayjs();
            const currentYear = now.year();
            const currentQuarter = Math.floor(now.month() / 3) + 1;
            const quarterEndDate = now.date(1).month((currentQuarter * 3) - 1).endOf('month');
            const leftoverDays = quarterEndDate.diff(now, 'day');

            const quarters = data || {};
            const quarterKeys = Object.keys(quarters);

            if (quarterKeys.every((key) => !Number(quarters[key]?.current))) {
                // prev_year is the same quarter a year earlier, the only trace of older sales in this payload.
                const lastSoldKey = [...quarterKeys].reverse().find((key) => Number(quarters[key]?.prev_year));
                if (!lastSoldKey) {
                    return translate('No sales in the last two years.');
                }

                const [lastSoldQuarter, lastSoldYear] = lastSoldKey.split('-');

                /* translators: %s - quarter of the last sale, e.g. Q3-2025 */
                return translate('No sales since %s.', `<span class="font-bold">${lastSoldQuarter}-${Number(lastSoldYear) - 1}</span>`);
            }

            const currentKey = `Q${currentQuarter}-${currentYear}`;
            const previousKey = currentQuarter === 1 ? `Q4-${currentYear - 1}` : `Q${currentQuarter - 1}-${currentYear}`;
            const emptyQuarter = {current: 0, prev_year: 0, yy_growth: null};
            const currentData = Arr.get(quarters, currentKey, emptyQuarter);
            const previousData = Arr.get(quarters, previousKey, emptyQuarter);

            const currentValue = Number(currentData.current) || 0;
            const previousValue = Number(previousData.current) || 0;

            const formatPercent = (value) => `${Number(value).toFixed(1)}%`;

            let quarterChange;
            let quarterChangeUp = true;

            if (previousValue === 0) {
                quarterChange = currentValue === 0
                    ?
                    /* translators: %s - previous quarter name */
                    translate('no change compared to %s', previousKey)
                    :
                    /* translators: %s - previous quarter name */
                    translate('up from nothing in %s', previousKey);
            } else {
                const changePercentage = ((currentValue - previousValue) / previousValue) * 100;
                quarterChangeUp = changePercentage >= 0;
                quarterChange = quarterChangeUp
                    ?
                    /* translators: %1$s - percentage change, %2$s - previous quarter name */
                    translate('an increase of %1$s compared to %2$s', formatPercent(changePercentage), previousKey)
                    :
                    /* translators: %1$s - percentage change, %2$s - previous quarter name */
                    translate('a decrease of %1$s compared to %2$s', formatPercent(Math.abs(changePercentage)), previousKey);
            }

            /* translators: %1$s - previous quarter name, %2$s - previous quarter volume, %3$s - previous quarter YoY change, %4$s - days left in quarter, %5$s - current quarter name, %6$s - current quarter volume, %7$s - quarter change, %8$s - YoY change */
            return translate('%1$s gross volume was %2$s, %3$s. With %4$s days left in %5$s, Gross volume is %6$s for the quarter, %7$s and %8$s',
                `<span class="font-bold">${previousKey}</span>`,
                `<span class="font-bold fct-custom-tooltip">${CurrencyFormatter.formatScaled(previousValue)}<span class="fct-custom-tooltip-content">${CurrencyFormatter.formatNumber(previousValue)}</span></span>`,
                CurrencyFormatter.#yoyChange(previousData.yy_growth),
                leftoverDays,
                `Q${currentQuarter}`,
                `<span class="font-bold fct-custom-tooltip">${CurrencyFormatter.formatScaled(currentValue)}<span class="fct-custom-tooltip-content">${CurrencyFormatter.formatNumber(currentValue)}</span></span>`,
                `<span class="font-bold ${quarterChangeUp ? 'text-green-500' : 'text-red-500'}">${quarterChange} </span>`,
                CurrencyFormatter.#yoyChange(currentData.yy_growth),
            );
        }
        // Get current and previous month using dayjs

    }
}

CurrencyFormatter.setLocale();
