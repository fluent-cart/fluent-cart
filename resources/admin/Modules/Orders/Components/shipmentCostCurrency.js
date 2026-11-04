/**
 * The currency a Create shipment cost is saved in. A picked rate is in the
 * currency it was quoted in; a label carrier's cost is in its own currency,
 * unknown until it quotes; anything else is typed in the order's currency.
 *
 * @param {Object} args
 * @param {string} args.rateCurrency  Currency of the selected rate, or ''.
 * @param {boolean} args.buysLabel    The carrier buys the label.
 * @param {string} args.orderCurrency The order's currency.
 * @returns {string} ISO code, or '' when not known yet.
 */
export const shipmentCostCurrency = ({ rateCurrency = '', buysLabel = false, orderCurrency = '' } = {}) => {
  if (rateCurrency) {
    return String(rateCurrency).toUpperCase();
  }
  if (buysLabel) {
    return '';
  }
  return String(orderCurrency || '').toUpperCase();
};
