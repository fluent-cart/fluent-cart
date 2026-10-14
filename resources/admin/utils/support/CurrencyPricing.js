// Mirrors PriceConverter::toPrice() (dev-docs/multi-currency/plan.md §15.4):
// rate, then the % adjustment, rounding up to the step, the charm ending.
// Amounts are cents, ×100 for every currency.

const ROUNDING_STEPS = {
    up_0_05: 5, up_0_10: 10, up_0_25: 25, up_0_50: 50,
    up_1: 100, up_5: 500, up_10: 1000, up_50: 5000, up_100: 10000
};

const CHARM_CENTS = {minus_0_01: 1, minus_0_05: 5};

// Sources whose rate is not a real exchange rate (CurrencySnapshot::hasTrustedRate)
const UNTRUSTED_SOURCES = ['unknown', 'legacy_unknown'];

/**
 * @param {number} baseCents
 * @param {{rate: string|number, rounding?: string, charm?: string, adjustment?: string|number, decimals?: number}} quote
 * @returns {number} cents in the quote's currency
 */
export function toPrice(baseCents, quote) {
    const unit = Number(quote.decimals) === 0 ? 100 : 1;
    let cents = Math.max(0, Number(baseCents) || 0) * parseFloat(quote.rate) * (1 + (parseFloat(quote.adjustment) || 0) / 100);

    const step = ROUNDING_STEPS[quote.rounding];
    if (step) {
        const size = Math.max(step, unit);
        cents = Math.ceil(cents / size - 1e-9) * size;
    } else {
        cents = Math.round(cents / unit) * unit;
    }

    const charm = CHARM_CENTS[quote.charm] || 0;
    if (charm && unit === 1 && cents > charm) {
        cents -= charm;
    }

    return cents;
}

/**
 * The rate shoppers are priced at for an active rate row, mirroring
 * ExchangeRateService::current(): the store's margin on a feed rate while a
 * feed is selected; none on a manual rate, nor in manual mode. The
 * fluent_cart/currency/fx_margin filter is server-only.
 *
 * @param {{rate: string|number, source?: string}|null} rateRow
 * @param {{rate_provider?: string, rate_margin?: string|number}} settings
 * @returns {number|null}
 */
export function shopperRate(rateRow, settings) {
    const rate = rateRow ? parseFloat(rateRow.rate) : NaN;
    if (!(rate > 0)) {
        return null;
    }
    if (rateRow.source === 'manual' || !settings.rate_provider || settings.rate_provider === 'manual') {
        return rate;
    }

    return rate * (1 + (parseFloat(settings.rate_margin) || 0) / 100);
}

/**
 * The quote an existing order prices new lines at: its frozen snapshot, when
 * the order is in another currency with a known rate; null otherwise.
 *
 * @param {object} order an order with config.fx
 * @returns {object|null}
 */
export function orderQuote(order) {
    const fx = order?.config?.fx;
    if (!fx || !fx.currency || !fx.base_currency || fx.currency === fx.base_currency) {
        return null;
    }
    if (UNTRUSTED_SOURCES.includes(fx.source) || !(parseFloat(fx.rate) > 0)) {
        return null;
    }

    return fx;
}

export default {toPrice, orderQuote, shopperRate};
