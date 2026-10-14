/**
 * Format a cart total (stored cents) with the storefront's currency settings —
 * the JS mirror of CurrencySettings::getFormattedPrice().
 *
 * The settings are the ones the drawer is localized with; under
 * multi-currency they describe the shopper's currency, so the decimal count
 * comes from is_zero_decimal rather than a hard-coded 2.
 *
 * @param {number} cents
 * @param {object} settings currency_settings (currency, currency_sign, currency_position, decimal_separator, is_zero_decimal)
 * @returns {string}
 */
export default function formatCartTotal(cents, settings = {}) {
    const decimals = settings.is_zero_decimal ? 0 : 2;
    const decimalSeparator = settings.decimal_separator === 'comma' ? ',' : '.';
    const thousandSeparator = decimalSeparator === ',' ? '.' : ',';

    const fixed = (Math.round(Number(cents) || 0) / 100).toFixed(decimals);
    const [whole, fraction] = fixed.split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, thousandSeparator);
    const price = fraction !== undefined ? `${grouped}${decimalSeparator}${fraction}` : grouped;

    const sign = settings.currency_sign || '';
    const code = settings.currency || '';

    switch (settings.currency_position) {
        case 'after':
            return `${price}${sign}`;
        case 'iso_before':
            return `${code} ${price}`;
        case 'iso_after':
            return `${price} ${code}`;
        case 'symbool_before_iso':
            return `${sign}${price} ${code}`;
        case 'symbool_after_iso':
            return `${code} ${price}${sign}`;
        case 'symbool_and_iso':
            return `${code} ${sign}${price}`;
        default:
            return `${sign}${price}`;
    }
}
