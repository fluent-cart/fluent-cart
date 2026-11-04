import {describe, expect, it, vi} from 'vitest';

vi.mock('../../resources/public/checkout/CheckoutHelper', () => ({
    default: {
        buildUrl: () => 'https://shop.test/wp-admin/admin-ajax.php',
        handleFragments: vi.fn(),
    },
}));

vi.mock('@/utils/support/Url', () => ({
    default: {appendQueryParams: (url) => url},
}));

// A UMD bundle that assigns module.exports, which vitest's ESM loader rejects.
vi.mock('../../resources/public/components/select/SearchableSelect', () => ({default: class {}}));

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('AddressField saved-address pick', () => {
    it('holds the place-order button while the picked address re-quotes', async () => {
        const ui = {
            holdCheckoutButton: vi.fn(),
            releaseCheckoutButton: vi.fn(),
            setLoading: vi.fn(),
            finishLoading: vi.fn(),
        };
        let respond;
        vi.stubGlobal('fetch', vi.fn(() => new Promise((resolve) => {
            respond = (data) => resolve({ok: true, json: () => Promise.resolve(data)});
        })));
        vi.stubGlobal('window', {
            fluentcart: {$t: (text) => text},
            fluentCartRestVars: {rest: {url: 'https://shop.test/wp-json/fluent-cart/v2', nonce: 'nonce'}},
            fluentcart_checkout_info: {checkout_nonce: 'nonce'},
            fluentcart_checkout_vars: {ajaxurl: 'https://shop.test/wp-admin/admin-ajax.php', is_all_digital: 0},
            fluent_cart_checkout_ui_service: ui,
        });

        // Imported after the stubs: the module reads window on load.
        const {default: AddressField} = await import('../../resources/public/checkout/AddressField.js');
        const field = new AddressField({
            form: {querySelector: () => null},
            prepareFormData: () => new FormData(),
        });
        field.saveCustomerData('billing_address_id', '42');

        expect(ui.holdCheckoutButton).toHaveBeenCalledTimes(1);
        const owner = ui.holdCheckoutButton.mock.calls[0][0];

        respond({message: 'ok'});
        await flush();
        expect(ui.releaseCheckoutButton).toHaveBeenCalledWith(owner);
    });
});
