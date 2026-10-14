import {describe, expect, it} from 'vitest';
import formatCartTotal from '../../resources/public/globals/Cart/formatCartTotal';

// The cart drawer total used currency_sign + parseFloat(toFixed(2)): trailing
// zeros vanished ("£39.3"), the symbol position and separators were ignored,
// and a zero-decimal presentment currency (multi-currency) could not be shown.
describe('formatCartTotal', () => {
    it('keeps two decimals, trailing zeros included', () => {
        expect(formatCartTotal(3930, {currency_sign: '£', currency_position: 'before'})).toBe('£39.30');
        expect(formatCartTotal(2000, {currency_sign: '$'})).toBe('$20.00');
    });

    it('shows a zero-decimal currency in whole units (x100 storage)', () => {
        expect(formatCartTotal(744500, {currency_sign: '¥', is_zero_decimal: true})).toBe('¥7,445');
    });

    it('follows the separators and symbol position', () => {
        expect(formatCartTotal(123456789, {currency_sign: '€', currency_position: 'after', decimal_separator: 'comma'})).toBe('1.234.567,89€');
        expect(formatCartTotal(3995, {currency: 'GBP', currency_sign: '£', currency_position: 'iso_before'})).toBe('GBP 39.95');
        expect(formatCartTotal(3995, {currency: 'GBP', currency_sign: '£', currency_position: 'symbool_before_iso'})).toBe('£39.95 GBP');
    });

    it('treats missing input as zero', () => {
        expect(formatCartTotal(undefined, {currency_sign: '$'})).toBe('$0.00');
    });
});
