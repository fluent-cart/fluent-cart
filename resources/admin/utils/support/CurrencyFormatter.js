import Arr from "@/utils/support/Arr";
import dayjs from "dayjs";
import translate from "@/utils/translator/Translator";
import {translateNumber} from "@/utils/translator/Translator";
import AppConfig from "@/utils/Config/AppConfig";

export default class CurrencyFormatter {
    static #currencySign = AppConfig.get('shop.currency_sign', '$');
    static #currencySigns = AppConfig.get('currency_signs', {});
    static #currencyPosition = AppConfig.get('shop.currency_position', 'before');
    static #wpLocale = AppConfig.get('wp_locale', 'en-US');


    static #locale = null;
    static #decimalSeparator;

    static setLocale() {

        CurrencyFormatter.#wpLocale = CurrencyFormatter.#wpLocale.replace('_', '-');
        const shopConfig = AppConfig.get('shop', {});
        CurrencyFormatter.#decimalSeparator = shopConfig.decimal_separator || null;
        CurrencyFormatter.#locale = this.#wpLocale;

        if (shopConfig.decimal_separator) {
            CurrencyFormatter.#locale = shopConfig.decimal_separator === 'comma' ? 'de-DE' : 'en-US';
        } else {
            CurrencyFormatter.#locale = navigator.language || navigator.languages?.[0] || 'en-US';
        }
    }

    // Core method to format a single amount (similar to your formatNumber)
    static formatNumber(amount, withCurrency = true, hideEmpty = false, currencyName = null) {
        if (!amount && hideEmpty) {
            return '';
        }
        const currency = Arr.get(CurrencyFormatter.#currencySigns, currencyName || '', CurrencyFormatter.#currencySign);

        if (!amount) {
            amount = '0.00';
        } else {
            amount = (amount / 100).toFixed(2); // Convert cents to dollars
        }

        if (!withCurrency) {
            return amount;
        }


        let formatted = new Intl.NumberFormat(CurrencyFormatter.#locale, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(amount);


        formatted = translateNumber(formatted);

        if (!withCurrency) {
            return formatted;
        }

        return CurrencyFormatter.#currencyPosition === 'before' ? `${currency}${formatted}` : `${formatted}${currency}`;
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

        const currency = Arr.get(CurrencyFormatter.#currencySigns, currencyName || '', CurrencyFormatter.#currencySign);

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
            return CurrencyFormatter.formatNumber(amount, withCurrency, hideEmpty, currency);
        }


        formattedAmount = CurrencyFormatter.formatNumber(amount, false, hideEmpty, currency);

        if(!withCurrency) {
            return formattedAmount;
        }

        // Combine the formatted amount with the suffix
        const valueWithSuffix = `${formattedAmount}${suffix}`;



        // Apply currency sign based on position
        if (CurrencyFormatter.#currencyPosition === 'before') {
            return `${currency}${valueWithSuffix}`;
        }
        return `${valueWithSuffix}${currency}`;
    }

    static formatScaledChart(amount, currencyName = '') {
        if (!amount) {
            return '0';
        }

        const currency = Arr.get(
            CurrencyFormatter.#currencySigns,
            currencyName || '',
            CurrencyFormatter.#currencySign
        )
            // decode HTML entities to real characters for canvas
            .replace(/&#(\d+);/g, (_, n) => String.fromCharCode(n))
            .replace(/&amp;/g, '&')
            .replace(/&lt;/g, '<')
            .replace(/&gt;/g, '>')
            .replace(/&nbsp;/g, ' ');

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
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(amount/100);

        const value = `${formatted}${suffix}`;

        return CurrencyFormatter.#currencyPosition === 'before'
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
    static formatBulk(amounts, useScaling = false, currency = CurrencyFormatter.#currencySign, hideEmpty = false) {
        return amounts.map(amount => {
            if (useScaling) {
                return CurrencyFormatter.formatScaled(amount, currency, hideEmpty);
            }
            return CurrencyFormatter.formatNumber(amount, currency, hideEmpty);
        });
    }

    static get currencySign() {
        return CurrencyFormatter.#currencySign;
    }

    static get currencyPosition() {
        return CurrencyFormatter.#currencyPosition;
    }

    static get locale() {
        return CurrencyFormatter.#locale;
    }

    static generateVolumeSummary(data, isDate = false) {

        if (isDate) {
            const now = dayjs();
            const currentMonthIndex = now.format('YYYY-MM'); // e.g., "2025-05" for May 2025
            const previousMonthIndex = now.subtract(1, 'month').format('YYYY-MM'); // e.g., "2025-04" for April 2025
            const currentMonthName = now.format('MMMM'); // e.g., "May"
            const previousMonthName = now.subtract(1, 'month').format('MMMM'); // e.g., "April"

            // Extract data for current and previous months from data.gross_revenue
            const currentData = data[currentMonthIndex] || {current: 0, yoy_growth: 0};
            const previousData = data[previousMonthIndex] || {current: 0, yoy_growth: 0};


            previousData.yoy_growth = Number(previousData.yoy_growth || 0)
            currentData.yoy_growth = Number(currentData.yoy_growth || 0)

            // Format the volumes using formatNumber
            const formattedPreviousVolume = CurrencyFormatter.formatScaled(previousData.current);
            const formattedCurrentVolume = CurrencyFormatter.formatScaled(currentData.current);

            // Format the YoY growth for the previous month

            const yoyChange = previousData.yoy_growth >= 0
                ?
                /* translators: %s - YoY growth percentage */
                translate('Increase of %s year-over-year', previousData.yoy_growth.toFixed(1) + '%')
                :
                /* translators: %s - YoY growth percentage */
                translate('Decrease of %s year-over-year', Math.abs(previousData.yoy_growth).toFixed(1) + '%');


            /* translators: %1$s - previous month name, %2$s - previous month volume, %3$s - YoY change, %4$s - current month name, %5$s - current month volume */
            return translate('%1$s gross volume was %2$s, a %3$s. Gross volume for the month of %4$s so far is %5$s. Here\'s the trend over the previous 12 months.',
                `<span class="font-bold">${previousMonthName}</span>`,
                `<span class="font-bold fct-custom-tooltip">${formattedPreviousVolume}<span class="fct-custom-tooltip-content">${CurrencyFormatter.formatNumber(previousData.current)}</span></span>`,
                `<span class="font-bold ${previousData.yoy_growth >= 0 ? 'text-green-500' : 'text-red-500'}">${yoyChange} </span>`,
                currentMonthName,
                `<span class="font-bold fct-custom-tooltip">${formattedCurrentVolume}<span class="fct-custom-tooltip-content">${CurrencyFormatter.formatNumber(currentData.current)}</span></span>`
            );
        } else {
            const now = dayjs();
            const currentYear = now.year(); // e.g., 2025
            const currentQuarter = Math.floor((now.month() + 3) / 3); // Q2 for May (month 4: (4+3)/3 = 2.33, floor to 2)
            const currentMonthName = now.format('MMMM'); // e.g., "May"

            const quarterEndMonth = (currentQuarter * 3) - 1; // 0-based month (5 for Q2)
            let quarterEndDate = dayjs().year(currentYear).month(quarterEndMonth).endOf('month');
            if (currentQuarter === 1) quarterEndDate = quarterEndDate.month(2).endOf('month'); // Q1 ends March 31
            if (currentQuarter === 2) quarterEndDate = quarterEndDate.month(5).endOf('month'); // Q2 ends June 30
            if (currentQuarter === 3) quarterEndDate = quarterEndDate.month(8).endOf('month'); // Q3 ends Sep 30
            if (currentQuarter === 4) quarterEndDate = quarterEndDate.month(11).endOf('month'); // Q4 ends Dec 31

            const leftoverDays = quarterEndDate.diff(now, 'day'); // Days remaining until quarter end

            const firstKey = Object.keys(data)[0];

            const firstValue = Object.values(data)[0];

            const firstCurrentValue = CurrencyFormatter.formatScaled(firstValue.current);
            firstValue.yy_growth = Number(firstValue.yy_growth || 0);


            const yoyChange = firstValue.yy_growth >= 0
                ? translate('Increase of %s year-over-year', firstValue.yy_growth.toFixed(1) + '%')
                : translate('Decrease of %s year-over-year', Math.abs(firstValue.yy_growth).toFixed(1) + '%');


            let currentIndex = `Q${currentQuarter}-${currentYear}`;
            const currentData = Arr.get(data, currentIndex, {current: 0, prev_year: 0, yy_growth: 0});
            currentData.yy_growth = Number(currentData.yy_growth || 0);

            const currentValue = CurrencyFormatter.formatScaled(currentData.current);

            let currentMonthPercentage = 0;

            // Handle percentage calculation with proper zero checks
            if (currentData.current === 0 && firstValue.current === 0) {
                // Both are zero, no change
                currentMonthPercentage = 0;
            } else if (currentData.current === 0) {
                // Current is zero but first value exists, this represents a 100% decrease
                currentMonthPercentage = -100;
            } else {
                // Normal calculation when currentData.current is not zero
                currentMonthPercentage = ((currentData.current - firstValue.current) / currentData.current) * 100;

                if (isNaN(currentMonthPercentage)) {
                    currentMonthPercentage = 0;
                }
            }

            const quarterChange = currentMonthPercentage >= 0
                ?
                /* translators: %1$s - percentage change, %2$s - previous quarter name */
                translate('a increase of %1$s compared to %2$s', currentMonthPercentage.toFixed(1) + '%', firstKey)
                :
                /* translators: %1$s - percentage change, %2$s - previous quarter name */
                translate('a decrease of %1$s compared to %2$s', Math.abs(currentMonthPercentage).toFixed(1) + '%', firstKey);

            /* translators: %1$s - previous quarter name, %2$s - previous quarter volume, %3$s - YoY change, %4$s - days left in quarter, %5$s - current quarter name, %6$s - current quarter volume, %7$s - quarter change, %8$s - YoY change */
            return translate('%1$s gross volume was %2$s, a %3$s. With %4$s days left in %5$s, Gross volume is %6$s for the quarter, %7$s and %8$s year-over-year',
                `<span class="font-bold">${firstKey}</span>`,
                `<span class="font-bold fct-custom-tooltip">${firstCurrentValue}<span class="fct-custom-tooltip-content">${CurrencyFormatter.formatNumber(firstValue.current)}</span></span>`,
                `<span class="font-bold ${firstValue.yy_growth >= 0 ? 'text-green-500' : 'text-red-500'}">${yoyChange} </span>`,
                leftoverDays,
                `Q${currentQuarter}`,
                `<span class="font-bold fct-custom-tooltip">${currentValue}<span class="fct-custom-tooltip-content">${CurrencyFormatter.formatNumber(currentData.current)}</span></span>`,
                `<span class="font-bold ${currentMonthPercentage >= 0 ? 'text-green-500' : 'text-red-500'}">${quarterChange} </span>`,
                `<span class="font-bold ${currentData.yy_growth >= 0 ? 'text-green-500' : 'text-red-500'}">${(currentData.yy_growth)}% </span>`,
            );
        }
        // Get current and previous month using dayjs

    }
}

CurrencyFormatter.setLocale();
