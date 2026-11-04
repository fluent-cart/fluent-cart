import CurrencyFormatter from '@/utils/support/CurrencyFormatter';
import translate from '@/utils/translator/Translator';

/**
 * Format a shipment cost stored in cents into a display string with the
 * currency sign, always with the currency's decimals ("$6.60", not "$6.6").
 *
 * @param {number} cents    Amount in cents.
 * @param {string|null} currency  Currency code, or null to fall back to the shop sign.
 * @returns {string} Formatted cost, or '' when there is no cost.
 */
export const formatShipmentCost = (cents, currency) => {
  if (!cents) {
    return '';
  }
  return CurrencyFormatter.formatFixed(cents, true, false, currency || null);
};

/**
 * An approximate amount in the order's currency, formatted like the order's
 * other amounts. From 100 units up the minor units are dropped: an estimate
 * reads "৳11,040", not "৳11,040.37".
 *
 * @param {number|null} cents        Estimate in the order currency, in cents.
 * @param {string} orderCurrency     The order's currency code.
 * @returns {string} Formatted amount, or '' when there is no estimate.
 */
export const formatOrderCurrencyEstimate = (cents, orderCurrency) => {
  const amount = Number(cents);
  if (!amount || !orderCurrency) {
    return '';
  }
  const shown = Math.abs(amount) >= 10000 ? Math.round(amount / 100) * 100 : amount;
  return CurrencyFormatter.formatForOrder(shown, orderCurrency);
};

/**
 * "$92.00 (≈ ৳11,040)": a billed cost with its order-currency estimate, or the
 * cost alone when there is none.
 *
 * @param {string} costText          The formatted billed cost.
 * @param {number|null} estimateCents Estimate in the order currency, in cents.
 * @param {string} orderCurrency     The order's currency code.
 * @returns {string}
 */
export const withOrderCurrencyEstimate = (costText, estimateCents, orderCurrency) => {
  const estimate = formatOrderCurrencyEstimate(estimateCents, orderCurrency);
  if (!costText || !estimate) {
    return costText;
  }
  /* translators: %1$s: cost as billed by the carrier, %2$s: approximate amount in the order's currency */
  return translate('%1$s (≈ %2$s)', costText, estimate);
};

/**
 * The shipments' total cost, one figure per currency: amounts billed in
 * different currencies are never added together. Order currency first.
 *
 * @param {Array} shipments      Shipments with meta.cost, meta.cost_currency and order_currency_estimate.
 * @param {string} orderCurrency The order's currency code; a cost without a currency is in it.
 * @returns {string} e.g. "৳500 + $92.00 (≈ ৳11,040)", or '' when no shipment has a cost.
 */
export const formatShipmentCostTotal = (shipments, orderCurrency) => {
  const order = String(orderCurrency || '').toUpperCase();
  const totals = new Map();

  (shipments || []).forEach((shipment) => {
    const meta = (shipment && shipment.meta) || {};
    const cost = Number(meta.cost);
    if (!cost) {
      return;
    }
    const currency = String(meta.cost_currency || order).toUpperCase();
    const total = totals.get(currency) || { cents: 0, estimate: 0, estimable: true };
    total.cents += cost;
    if (currency !== order) {
      // One shipment without an estimate leaves the currency's sum unestimated.
      const estimate = Number(shipment.order_currency_estimate);
      total.estimable = total.estimable && !!estimate;
      total.estimate += estimate || 0;
    }
    totals.set(currency, total);
  });

  const currencies = Array.from(totals.keys()).sort((first, second) => {
    return (second === order) - (first === order);
  });

  return currencies.map((currency) => {
    const total = totals.get(currency);
    const cost = formatShipmentCost(total.cents, currency || null);
    if (currency === order || !total.estimable) {
      return cost;
    }
    return withOrderCurrencyEstimate(cost, total.estimate, order);
  }).join(' + ');
};
