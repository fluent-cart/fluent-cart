import CurrencyFormatter from '@/utils/support/CurrencyFormatter';
import translate, { translateNumber } from '@/utils/translator/Translator';
import { orderCarrierSlug } from '@/Modules/Orders/Components/shipmentCarrierDefault';

// Rates the order snapshot cannot vouch for (see CurrencySnapshot).
const UNTRUSTED_FX_SOURCES = ['unknown', 'legacy_unknown'];

// First-strong isolates keep an LTR amount or code intact inside RTL text.
const isolate = (value) => '⁨' + value + '⁩';

const orderConfig = (order) => {
    let config = order && order.config;
    if (typeof config === 'string') {
        try {
            config = JSON.parse(config);
        } catch (error) {
            config = null;
        }
    }
    return config && typeof config === 'object' ? config : {};
};

const formatRate = (rate) => translateNumber(new Intl.NumberFormat(CurrencyFormatter.locale || undefined, {
    minimumFractionDigits: 0,
    maximumFractionDigits: 6,
}).format(rate));

/**
 * The live carrier's original quote and the frozen exchange rate behind an
 * order's shipping charge, for an order not in the carrier's currency.
 *
 * @param {Object|null} order Admin order payload
 * @returns {{carrierRate: string, exchangeRate: string}|null}
 */
export const shippingQuoteNote = (order) => {
    if (!order) {
        return null;
    }

    const config = orderConfig(order);
    const quote = config.shipping_quote;
    const orderCurrency = String(order.currency || '').toUpperCase();
    const sourceCurrency = String((quote && quote.source_currency) || '').toUpperCase();
    const sourceCost = Number(quote && quote.source_cost);

    if (!quote || !sourceCurrency || !orderCurrency || sourceCurrency === orderCurrency || !(sourceCost > 0)) {
        return null;
    }

    // An admin edit can change the carrier after checkout; the quote then no longer applies.
    if (String(quote.carrier || '') !== orderCarrierSlug(order)) {
        return null;
    }

    /* translators: %1$s: the carrier's quoted cost with its currency code, e.g. "$6.66 USD" */
    const carrierRate = translate('Carrier rate: %1$s',
        isolate(CurrencyFormatter.formatFixed(sourceCost, true, false, sourceCurrency) + ' ' + sourceCurrency));

    let exchangeRate = '';
    const fx = config.fx || {};
    const baseCurrency = String(fx.base_currency || '').toUpperCase();
    const rate = Number(fx.rate);

    if (baseCurrency && baseCurrency !== orderCurrency
        && String(fx.currency || '').toUpperCase() === orderCurrency
        && rate > 0 && !UNTRUSTED_FX_SOURCES.includes(fx.source)) {
        // One isolate for the whole equation, so RTL text cannot reorder its parts.
        const equation = translateNumber(1) + ' ' + baseCurrency + ' = ' + formatRate(rate) + ' ' + orderCurrency;
        /* translators: %1$s: the order's frozen exchange rate, e.g. "1 USD = 0.92 EUR" */
        exchangeRate = translate('Exchange rate %1$s', isolate(equation));
    }

    return { carrierRate, exchangeRate };
};
