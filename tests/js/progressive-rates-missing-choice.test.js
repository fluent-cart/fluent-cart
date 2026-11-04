import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';

vi.mock('../../resources/public/checkout/CheckoutHelper', () => ({default: {}}));
vi.mock('../../resources/admin/utils/support/Url', () => ({default: {}}));

import ProgressiveShippingRates from '../../resources/public/checkout/ProgressiveShippingRates';

// The settled list: its hidden choice, its radios, and the loader row.
function list(chosen, radioValues, checked = null) {
    const hidden = {value: chosen};
    const radios = radioValues.map((value) => ({value, checked: value === checked}));
    const status = {classList: {remove: vi.fn()}, remove: vi.fn()};
    return {
        hidden,
        isConnected: true,
        dataset: {fctPendingRateSources: '[]'},
        parentElement: {querySelector: () => null},
        querySelector(selector) {
            if (selector === 'input[name="fc_selected_shipping_method"]') return hidden;
            if (selector.endsWith(':checked')) return radios.find((radio) => radio.checked) || null;
            if (selector.includes('data-fct-rate-sources-status')) return status;
            return null;
        },
        querySelectorAll: () => radios,
    };
}

describe('ProgressiveShippingRates: a choice no source brought', () => {
    const settle = (target) => Object.create(ProgressiveShippingRates.prototype).updateStatus(target, new Set());
    let save;

    beforeEach(() => {
        save = vi.fn();
        globalThis.window = {fluent_cart_checkout_data_watcher: {saveCustomerData: save}};
        globalThis.getComputedStyle = () => ({transitionDuration: '0s'});
    });

    afterEach(() => {
        delete globalThis.window;
        delete globalThis.getComputedStyle;
    });

    it('is cleared and saved, so the summary re-renders without its charge', () => {
        const target = list('carrier:shippo:usps_priority', ['carrier:shippo:dhl_intl']);
        settle(target);

        expect(target.hidden.value).toBe('');
        expect(save).toHaveBeenCalledWith('shipping_method_id', '');
    });

    it('is kept when a source brought it', () => {
        const target = list('carrier:shippo:usps_priority', ['carrier:shippo:usps_priority'], 'carrier:shippo:usps_priority');
        settle(target);

        expect(target.hidden.value).toBe('carrier:shippo:usps_priority');
        expect(save).not.toHaveBeenCalled();
    });

    it('leaves a pick the customer just made alone', () => {
        const target = list('carrier:shippo:usps_priority', ['carrier:shippo:dhl_intl'], 'carrier:shippo:dhl_intl');
        settle(target);

        expect(save).not.toHaveBeenCalled();
    });
});
