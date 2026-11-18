import {describe, expect, it, vi} from 'vitest';

vi.mock('../../resources/public/checkout/CheckoutHelper', () => ({default: {}}));
vi.mock('../../resources/admin/utils/support/Url', () => ({default: {}}));

import ProgressiveShippingRates from '../../resources/public/checkout/ProgressiveShippingRates';

// A list of rows, each with one radio; remove() takes the row out.
function list(values) {
    const rows = [];
    values.forEach((value) => {
        const radio = {value};
        const row = {querySelector: () => radio, remove: () => rows.splice(rows.indexOf(row), 1)};
        rows.push(row);
    });
    return {
        rows,
        querySelectorAll: (selector) => (selector.includes('fc_shipping_method') ? rows.map((row) => row.querySelector()) : [...rows]),
    };
}

describe('ProgressiveShippingRates: rows the available_rates filter hides', () => {
    const drop = (target, rendered, listIds) => {
        const rates = Object.create(ProgressiveShippingRates.prototype);
        rates.shown = {key: '', ids: []};
        rates.dropHidden(target, new Set(rendered), listIds);
        return target.rows.map((row) => row.querySelector().value);
    };

    it('drops a rendered row the whole list no longer has', () => {
        const target = list(['1', 'carrier:stub:express', 'carrier:gelato:std']);

        expect(drop(target, ['1', 'carrier:stub:express'], ['carrier:stub:express', 'carrier:gelato:std'])).toEqual(['carrier:stub:express', 'carrier:gelato:std']);
    });

    it('keeps a row another source added before this answer knew of it', () => {
        const target = list(['1', 'carrier:fedex:home', 'carrier:ups:ground']);

        expect(drop(target, ['1'], ['1', 'carrier:ups:ground'])).toEqual(['1', 'carrier:fedex:home', 'carrier:ups:ground']);
    });

    it('drops nothing when the server sends no list', () => {
        const target = list(['1', '2']);

        expect(drop(target, ['1', '2'], null)).toEqual(['1', '2']);
    });
});
