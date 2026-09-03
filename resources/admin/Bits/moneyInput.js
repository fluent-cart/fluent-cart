/**
 * Parsing of human-typed / human-authored money strings into cents.
 *
 * This is the shared core behind the two dollars -> cents boundaries in the
 * admin: PriceInput.vue (what a merchant types) and Importer.vue (what a
 * merchant's spreadsheet contains). Both accept the same shapes and must agree
 * on what "1.234,56" means, so the logic lives here rather than in either one.
 *
 * Everything below is pure — no Vue, no AppConfig, no DOM. The caller resolves
 * the shop's separators and passes them in, which is also what makes this
 * testable without a DOM environment.
 */

/**
 * Resolve the shop's decimal/thousand separators and a matching locale.
 *
 * @param {string} configuredSeparator `shop.decimal_separator` — 'comma', 'dot', or ''
 * @param {string} numberFormat        fallback when unset — 'dot_comma' means comma-decimal
 */
export const resolveSeparators = (configuredSeparator = '', numberFormat = '') => {
    let decimal;

    if (configuredSeparator === 'comma') {
        decimal = ',';
    } else if (configuredSeparator === 'dot') {
        decimal = '.';
    } else {
        decimal = numberFormat === 'dot_comma' ? ',' : '.';
    }

    return {
        decimal,
        thousand: decimal === ',' ? '.' : ',',
        locale: decimal === ',' ? 'de-DE' : 'en-US',
    };
};

export const stripLeadingZeros = (value) => {
    return String(value || '').replace(/^0+(?=\d)/, '');
};

export const sanitizeInput = (value) => {
    return String(value ?? '').replace(/[^\d.,]/g, '');
};

/**
 * Normalize a typed or imported string to a plain dot-decimal string.
 *
 * The hard case is a lone separator: "1.234" is one thousand two hundred in a
 * comma-decimal shop and one-point-two-three-four in a dot-decimal one. It is
 * read as a thousands separator only when it is the shop's thousands character
 * AND the shape backs that up — either it repeats, or it is followed by more
 * than two digits (no currency has three decimal places here). Anything else is
 * treated as a decimal point, which keeps a plain "19.99" working in every shop.
 *
 * @param {string|number} value
 * @param {string} thousandSeparator the shop's thousands character
 * @returns {string} dot-decimal string, or '' when there is nothing numeric
 */
export const normalizeTypingValue = (value, thousandSeparator = ',') => {
    const cleaned = sanitizeInput(value);

    if (!cleaned) {
        return '';
    }

    const lastDotIndex = cleaned.lastIndexOf('.');
    const lastCommaIndex = cleaned.lastIndexOf(',');
    const decimalIndex = Math.max(lastDotIndex, lastCommaIndex);

    if (decimalIndex === -1) {
        return stripLeadingZeros(cleaned.replace(/[.,]/g, ''));
    }

    const integerDigits = stripLeadingZeros(cleaned.slice(0, decimalIndex).replace(/[^\d]/g, ''));
    const fractionDigits = cleaned.slice(decimalIndex + 1).replace(/[^\d]/g, '');
    const separatorChar = cleaned.charAt(decimalIndex);
    const separatorCount = (cleaned.match(new RegExp(`\\${separatorChar}`, 'g')) || []).length;
    const hasTrailingSeparator = decimalIndex === cleaned.length - 1;

    const hasBothSeparatorTypes = cleaned.includes('.') && cleaned.includes(',');
    const shouldTreatAsThousandsSeparator = !hasTrailingSeparator
        && !hasBothSeparatorTypes
        && separatorChar === thousandSeparator
        && (separatorCount > 1 || fractionDigits.length > 2);

    if (shouldTreatAsThousandsSeparator) {
        return stripLeadingZeros(cleaned.replace(/[^\d]/g, ''));
    }

    const normalizedInteger = integerDigits || '0';

    if (hasTrailingSeparator) {
        return `${normalizedInteger}.`;
    }

    return `${normalizedInteger}.${fractionDigits.slice(0, 2)}`;
};

/**
 * @returns {number} the value as a float, or NaN when it is not numeric
 */
export const parseNormalizedValue = (value, thousandSeparator = ',') => {
    if (value === null || value === undefined || value === '') {
        return NaN;
    }

    const normalizedValue = typeof value === 'number'
        ? String(value)
        : normalizeTypingValue(value, thousandSeparator);

    if (!normalizedValue) {
        return NaN;
    }

    return parseFloat(normalizedValue.replace(/\.$/, ''));
};

/**
 * Convert a dollars string/number to whole cents.
 *
 * Rounds rather than truncates: 19.99 * 100 is 1998.9999999999998 in binary
 * float, and a bare cast would store 1998 cents.
 *
 * @returns {number|string} integer cents, or '' when there is nothing numeric
 */
export const dollarsToCents = (value, thousandSeparator = ',') => {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    const parsed = parseNormalizedValue(value, thousandSeparator);

    return isNaN(parsed) ? '' : Math.round(parsed * 100);
};

/**
 * A CSV cell must be a bare money value and nothing else: an optional leading
 * minus, then digits and separators, with at least one digit present.
 *
 * This is deliberately stricter than what a typed field accepts. Anything that
 * merely *contains* a number is rejected rather than salvaged, because
 * sanitizeInput() would otherwise quietly turn "19.99abc" into 19.99 and
 * "$19.99" into 19.99 — a typo or a stray column becomes a real price with no
 * indication anything was wrong. It also rejects misplaced signs ("19-99",
 * "--19") and exponent notation ("1e3").
 *
 * Rejecting decorated values restores the pre-conversion behaviour: the raw
 * string used to reach PHP, where `is_numeric('$19.99')` is false and the
 * `numeric` rule failed the row.
 */
const CSV_MONEY_PATTERN = /^-?[\d.,]*\d[\d.,]*$/;

/**
 * Convert one CSV cell to cents, preserving anything the server must reject.
 *
 * Unlike dollarsToCents this is deliberately NOT total: a typed field can lean
 * on PriceInput's keydown filter (which blocks '-') and on the merchant seeing
 * the result immediately, but an imported file is validated server-side, and
 * quietly rewriting a cell defeats that.
 *
 *   ''          -> ''            blank cell; `nullable` accepts it
 *   '19.99'     -> 1999          parsed
 *   '-19.99'    -> -1999         sign kept so `min:0` still rejects it
 *   'abc'       -> 'abc'         handed back verbatim so `numeric` rejects it
 *   '19.99abc'  -> '19.99abc'    likewise — not salvaged into 1999
 *
 * Stripping the sign, salvaging a partial number, or collapsing junk to ''
 * would each turn a rejected row into a silently mispriced product.
 */
export const csvValueToCents = (value, thousandSeparator = ',') => {
    if (value === null || value === undefined) {
        return '';
    }

    const raw = String(value).trim();

    if (raw === '') {
        return '';
    }

    // Not a bare money string — hand it back so the backend reports a row error
    // instead of importing whatever digits happened to be in there.
    if (!CSV_MONEY_PATTERN.test(raw)) {
        return raw;
    }

    const cents = dollarsToCents(raw, thousandSeparator);

    if (cents === '') {
        return raw;
    }

    return raw.charAt(0) === '-' ? -cents : cents;
};

/**
 * @returns {string} cents rendered as a plain dot-decimal dollars string
 */
export const centsToDollarValue = (cents) => {
    if (cents === null || cents === undefined || cents === '') {
        return '';
    }

    const parsed = typeof cents === 'number' ? cents : parseFloat(String(cents));

    return isNaN(parsed) ? '' : (parsed / 100).toFixed(2);
};
