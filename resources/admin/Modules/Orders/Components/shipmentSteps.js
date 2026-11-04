/**
 * Which Create shipment steps can be opened: Items always, Method once an item
 * ships, Review once the method is complete. A label carrier needs a picked
 * rate; a carrier that lists zone methods needs one of them or Custom price.
 * Tracking is optional, and a failed or empty quote never blocks Manual.
 *
 * @param {Object} state
 * @param {boolean} state.hasItems      At least one item has a quantity.
 * @param {boolean} state.carriersReady The carrier list has finished loading.
 * @param {boolean} state.buysLabel     The chosen carrier buys the label.
 * @param {boolean} state.listsMethods  A non-label carrier that quotes zone methods.
 * @param {boolean} state.rateSelected  A rate is picked.
 * @param {boolean} state.customPrice   Custom price is picked.
 * @param {boolean} state.quoteFailed   The quote finished with no methods to pick.
 * @returns {boolean[]} One flag per step: Items, Method, Review.
 */
export const shipmentStepsReachable = ({
    hasItems = false,
    carriersReady = false,
    buysLabel = false,
    listsMethods = false,
    rateSelected = false,
    customPrice = false,
    quoteFailed = false,
} = {}) => {
    const methodDone = buysLabel
        ? rateSelected
        : !listsMethods || rateSelected || customPrice || quoteFailed;

    return [true, hasItems, hasItems && carriersReady && methodDone];
};

/**
 * The ids of the cheapest and the fastest rate, '' when the list cannot tell
 * them apart: fewer than two rates, all equal, or prices in different currencies.
 *
 * @param {Array} rates Quote rates with rate_object_id, cost_cents, currency, estimated_days.
 * @returns {{cheapest: string, fastest: string}}
 */
export const rateHighlights = (rates) => {
    const list = Array.isArray(rates) ? rates : [];
    const pick = (candidates, value) => {
        if (candidates.length < 2) {
            return '';
        }
        const values = candidates.map(value);
        const lowest = Math.min(...values);
        if (values.every((item) => item === lowest)) {
            return '';
        }
        return candidates[values.indexOf(lowest)].rate_object_id;
    };

    const currencies = new Set(list.map((rate) => String(rate.currency || '').toUpperCase()));
    const priced = list.filter((rate) => !isNaN(Number(rate.cost_cents)));

    return {
        cheapest: currencies.size === 1 ? pick(priced, (rate) => Number(rate.cost_cents)) : '',
        fastest: pick(list.filter((rate) => Number(rate.estimated_days) > 0), (rate) => Number(rate.estimated_days)),
    };
};
