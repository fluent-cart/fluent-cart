import {beforeEach, describe, expect, it, vi} from 'vitest';

vi.mock('../../resources/public/checkout/CheckoutHelper', () => ({
    default: {
        buildUrl: () => 'https://shop.test/wp-admin/admin-ajax.php',
        handleFragments: vi.fn(),
    },
}));

vi.mock('@/utils/support/Url', () => ({
    default: {appendQueryParams: (url) => url},
}));

import CheckoutHelper from '../../resources/public/checkout/CheckoutHelper';
import DataWatcher from '../../resources/public/checkout/DataWatcher.js';
import {
    isShippingDestinationKey,
    SaveResponseSequence,
} from '../../resources/public/checkout/shippingDestination.js';

/**
 * Live carriers price by postcode and city. Saving a field of the shipping
 * destination re-quotes the rates, holds the place-order button until they
 * arrive, and a late response for an older address never overwrites newer rates.
 */

describe('isShippingDestinationKey', () => {
    it('treats billing address fields as the destination when shipping to the same address', () => {
        for (const key of ['billing_country', 'billing_state', 'billing_postcode', 'billing_city', 'billing_address_id']) {
            expect(isShippingDestinationKey(key, false)).toBe(true);
        }
        expect(isShippingDestinationKey('shipping_postcode', false)).toBe(false);
    });

    it('treats shipping address fields as the destination when shipping elsewhere', () => {
        for (const key of ['shipping_country', 'shipping_state', 'shipping_postcode', 'shipping_city', 'shipping_address_id']) {
            expect(isShippingDestinationKey(key, true)).toBe(true);
        }
        expect(isShippingDestinationKey('billing_postcode', true)).toBe(false);
    });

    it('counts the ship-to-different toggle and ignores fields that do not move the parcel', () => {
        expect(isShippingDestinationKey('ship_to_different', false)).toBe(true);
        expect(isShippingDestinationKey('ship_to_different', true)).toBe(true);
        for (const key of ['billing_phone', 'billing_full_name', 'billing_email', 'billing_address_1', 'shipping_method_id', '', undefined]) {
            expect(isShippingDestinationKey(key, false)).toBe(false);
        }
    });
});

describe('SaveResponseSequence', () => {
    it('drops fragments older than ones already applied', () => {
        const seq = new SaveResponseSequence();
        const first = seq.next();
        const second = seq.next();

        expect(seq.shouldApply(second, {fragments: []})).toBe(true);
        expect(seq.shouldApply(first, {fragments: []})).toBe(false);
    });

    it('lets an older response through when the newer one carried no fragments', () => {
        const seq = new SaveResponseSequence();
        const first = seq.next();
        const second = seq.next();

        expect(seq.shouldApply(second, {message: 'No changes detected'})).toBe(true);
        expect(seq.shouldApply(first, {fragments: []})).toBe(true);
    });
});

function deferredFetch() {
    const pending = [];
    const fetchMock = vi.fn(() => new Promise((resolve) => {
        pending.push((data, ok = true) => resolve({ok, json: () => Promise.resolve(data)}));
    }));
    return {fetchMock, pending};
}

function formDataOf(fields) {
    const formData = new FormData();
    for (const [key, value] of Object.entries(fields)) {
        formData.append(key, value);
    }
    return formData;
}

// `fields` is the live form: mutate it to simulate typing or autofill.
function watcher(shipToDifferent = false, fields = {}) {
    const checkbox = {checked: shipToDifferent};
    const form = {
        querySelector: (selector) => (selector === 'input[name="ship_to_different"]' ? checkbox : null),
        addEventListener: () => {},
    };
    const handler = {
        form,
        prepareFormData: () => formDataOf(fields),
        handleCheckoutAmountChanges: vi.fn(),
    };
    return new DataWatcher(handler);
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('DataWatcher shipping destination saves', () => {
    let ui;
    let fetchCtl;

    beforeEach(() => {
        ui = {holdCheckoutButton: vi.fn(), releaseCheckoutButton: vi.fn()};
        fetchCtl = deferredFetch();
        vi.stubGlobal('fetch', fetchCtl.fetchMock);
        vi.stubGlobal('document', {getElementById: () => null, addEventListener: () => {}});
        vi.stubGlobal('window', {
            fluentcart_checkout_info: {checkout_nonce: 'nonce'},
            fluentcart_checkout_vars: {ajaxurl: 'https://shop.test/wp-admin/admin-ajax.php'},
            fluent_cart_checkout_ui_service: ui,
            addEventListener: () => {},
        });
        CheckoutHelper.handleFragments.mockClear();
    });

    it('ignores a slow response for an old postcode once the new postcode is rendered', async () => {
        const dw = watcher();
        dw.saveCustomerData('billing_postcode', '10001');
        dw.saveCustomerData('billing_postcode', '90210');

        const [resolveOld, resolveNew] = fetchCtl.pending;
        resolveNew({fragments: [{selector: 'methods', content: 'rates for 90210'}]});
        await flush();
        resolveOld({fragments: [{selector: 'methods', content: 'rates for 10001'}]});
        await flush();

        expect(CheckoutHelper.handleFragments).toHaveBeenCalledTimes(1);
        expect(CheckoutHelper.handleFragments.mock.calls[0][0][0].content).toBe('rates for 90210');
    });

    it('holds the place-order button until each destination save settles', async () => {
        const dw = watcher();
        dw.saveCustomerData('billing_postcode', '10001');
        dw.saveCustomerData('billing_city', 'Beverly Hills');

        expect(ui.holdCheckoutButton).toHaveBeenCalledTimes(2);
        const owners = ui.holdCheckoutButton.mock.calls.map((call) => call[0]);
        expect(new Set(owners).size).toBe(2);

        fetchCtl.pending[0]({message: 'ok'});
        await flush();
        expect(ui.releaseCheckoutButton).toHaveBeenCalledWith(owners[0]);
        expect(ui.releaseCheckoutButton).not.toHaveBeenCalledWith(owners[1]);

        fetchCtl.pending[1]({message: 'ok'});
        await flush();
        expect(ui.releaseCheckoutButton).toHaveBeenCalledWith(owners[1]);
    });

    it('does not hold the button for fields that cannot change the rates', () => {
        watcher().saveCustomerData('billing_phone', '555');
        watcher(true).saveCustomerData('billing_postcode', '10001');

        expect(ui.holdCheckoutButton).not.toHaveBeenCalled();
    });

    it('holds for the shipping postcode when shipping to a different address', () => {
        watcher(true).saveCustomerData('shipping_postcode', '02139');

        expect(ui.holdCheckoutButton).toHaveBeenCalledTimes(1);
    });

    it('holds for a field a request-building carrier quotes from', () => {
        window.fluentcart_checkout_vars.shipping_quote_form_fields = ['billing_email', 'fct_billing_tax_id'];

        watcher().saveCustomerData('billing_email', 'b@example.com');
        watcher().saveCustomerData('fct_billing_tax_id', 'DE2');
        watcher().saveCustomerData('_fct_pay_method', 'stripe');

        expect(ui.holdCheckoutButton).toHaveBeenCalledTimes(2);
    });
});

describe('DataWatcher holds the button for the destination the form posts', () => {
    let ui;
    let fetchCtl;

    beforeEach(() => {
        ui = {holdCheckoutButton: vi.fn(), releaseCheckoutButton: vi.fn()};
        fetchCtl = deferredFetch();
        vi.stubGlobal('fetch', fetchCtl.fetchMock);
        vi.stubGlobal('document', {getElementById: () => null, addEventListener: () => {}});
        vi.stubGlobal('window', {
            fluentcart_checkout_info: {checkout_nonce: 'nonce'},
            fluentcart_checkout_vars: {ajaxurl: 'https://shop.test/wp-admin/admin-ajax.php'},
            fluent_cart_checkout_ui_service: ui,
            addEventListener: () => {},
        });
    });

    const address = () => ({
        billing_full_name: '',
        billing_country: 'US',
        billing_state: 'NY',
        billing_postcode: '10001',
        billing_city: 'New York',
    });

    it('holds a name save when autofill changed the postcode in the same form', () => {
        const fields = address();
        const dw = watcher(false, fields);

        // Autofill fills name and address at once; the name change event saves first.
        Object.assign(fields, {billing_full_name: 'Jane Roe', billing_postcode: '90210', billing_city: 'Beverly Hills'});
        dw.saveCustomerData('billing_full_name', 'Jane Roe');

        expect(ui.holdCheckoutButton).toHaveBeenCalledTimes(1);
    });

    it('holds the shipping-address destination posted with a non-destination key', () => {
        const fields = {...address(), ship_to_different: 'yes', shipping_postcode: '02139'};
        const dw = watcher(true, fields);

        fields.shipping_postcode = '94103';
        dw.saveCustomerData('shipping_full_name', 'Jane Roe');

        expect(ui.holdCheckoutButton).toHaveBeenCalledTimes(1);
    });

    it('stops holding once the server confirmed the new destination', async () => {
        const fields = address();
        const dw = watcher(false, fields);

        fields.billing_postcode = '90210';
        dw.saveCustomerData('billing_full_name', 'Jane Roe');
        fetchCtl.pending[0]({message: 'Data saved successfully'});
        await flush();

        dw.saveCustomerData('billing_phone', '555');
        expect(ui.holdCheckoutButton).toHaveBeenCalledTimes(1);
    });

    it('keeps holding while the first save of a new destination is still in flight', () => {
        const fields = address();
        const dw = watcher(false, fields);

        fields.billing_postcode = '90210';
        dw.saveCustomerData('billing_full_name', 'Jane Roe');
        dw.saveCustomerData('billing_phone', '555');

        expect(ui.holdCheckoutButton).toHaveBeenCalledTimes(2);
    });

    it('holds the next save after a failed one, since the server may not have stored it', async () => {
        const fields = address();
        const dw = watcher(false, fields);

        fields.billing_postcode = '90210';
        dw.saveCustomerData('billing_full_name', 'Jane Roe');
        fetchCtl.pending[0]({message: 'error'}, false);
        await flush();

        dw.saveCustomerData('billing_phone', '555');
        expect(ui.holdCheckoutButton).toHaveBeenCalledTimes(2);
    });

    it('ignores billing changes when shipping to a different address', () => {
        const fields = {...address(), ship_to_different: 'yes', shipping_postcode: '02139'};
        const dw = watcher(true, fields);

        fields.billing_postcode = '90210';
        dw.saveCustomerData('billing_full_name', 'Jane Roe');

        expect(ui.holdCheckoutButton).not.toHaveBeenCalled();
    });
});


describe('DataWatcher re-quotes with the contact once it is complete', () => {
    let ui;
    let fetchCtl;

    const stubWindow = (quoteFields) => {
        vi.stubGlobal('window', {
            fluentcart_checkout_info: {checkout_nonce: 'nonce'},
            fluentcart_checkout_vars: {ajaxurl: 'https://shop.test/wp-admin/admin-ajax.php', shipping_quote_fields: quoteFields},
            fluent_cart_checkout_ui_service: ui,
            addEventListener: () => {},
        });
    };

    beforeEach(() => {
        ui = {holdCheckoutButton: vi.fn(), releaseCheckoutButton: vi.fn()};
        fetchCtl = deferredFetch();
        vi.stubGlobal('fetch', fetchCtl.fetchMock);
        vi.stubGlobal('document', {getElementById: () => null, addEventListener: () => {}});
    });

    const address = () => ({billing_country: 'US', billing_state: 'NY', billing_postcode: '10001', billing_city: 'New York'});

    it('re-quotes once a lone name field is typed', () => {
        stubWindow({billing: ['billing_full_name'], shipping: ['shipping_full_name']});
        const fields = address();
        const dw = watcher(false, fields);

        fields.billing_full_name = 'Jane Roe';
        dw.saveCustomerData('billing_full_name', 'Jane Roe');

        expect(ui.holdCheckoutButton).toHaveBeenCalledTimes(1);
    });

    it('waits for every shown contact field, then re-quotes on later changes', async () => {
        stubWindow({billing: ['billing_full_name', 'billing_phone'], shipping: []});
        const fields = address();
        const dw = watcher(false, fields);

        fields.billing_full_name = 'Jane Roe';
        dw.saveCustomerData('billing_full_name', 'Jane Roe');
        fields.billing_company_name = 'Acme';
        dw.saveCustomerData('billing_company_name', 'Acme');
        expect(ui.holdCheckoutButton).not.toHaveBeenCalled();

        fields.billing_phone = '555 0100';
        dw.saveCustomerData('billing_phone', '555 0100');
        expect(ui.holdCheckoutButton).toHaveBeenCalledTimes(1);
        fetchCtl.pending[2]({message: 'ok'});
        await flush();

        fields.billing_full_name = 'Jane Q Roe';
        dw.saveCustomerData('billing_full_name', 'Jane Q Roe');
        expect(ui.holdCheckoutButton).toHaveBeenCalledTimes(2);
    });

    it('never holds for contact fields when no live carrier quotes', () => {
        stubWindow({billing: [], shipping: []});
        const fields = address();
        const dw = watcher(false, fields);

        fields.billing_full_name = 'Jane Roe';
        dw.saveCustomerData('billing_full_name', 'Jane Roe');

        expect(ui.holdCheckoutButton).not.toHaveBeenCalled();
    });
});
