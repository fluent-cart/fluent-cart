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

vi.mock('../../resources/public/checkout/LocalizationService', () => ({
    default: {
        getCountryInfo: vi.fn(async (code) => ({
            country_code: code,
            states: [{name: 'Alpha', value: code + '-A'}],
            address_locale: {state: {label: 'State'}},
        })),
        getCountryInfoByTimeZone: vi.fn(async () => ({country_code: 'DE', states: []})),
    },
}));

vi.mock('../../resources/public/components/select/NiceSelect', () => ({
    default: class {
        setOptions() {
            return this;
        }

        setSelected() {}

        setOnChange() {}

        destroy() {}
    },
}));

import AddressService from '../../resources/public/checkout/AddressService.js';

class FakeField extends EventTarget {
    constructor(id, tagName, value = '') {
        super();
        this.id = id;
        this.tagName = tagName;
        this.value = value;
        this.dataset = {};
        this.style = {};
    }

    closest() {
        return null;
    }
}

const ALL_FIELDS = ['billing_country', 'billing_state', 'shipping_country', 'shipping_state'];

function handlerWithout(missing) {
    const fields = {};
    for (const id of ALL_FIELDS.filter((field) => !missing.includes(field))) {
        const isCountry = id.endsWith('_country');
        fields[id] = new FakeField(id, 'SELECT', isCountry ? 'DE' : '');
        if (!isCountry) {
            fields[id + '_wrapper'] = new FakeField(id + '_wrapper', 'DIV');
        }
    }
    return {
        form: {
            querySelector: (selector) => fields[selector.replace('#', '')] || null,
            addEventListener: () => {},
        },
        prepareFormData: () => new FormData(),
    };
}

async function readyCalls(missing, isAllDigital = 0) {
    window.fluentcart_checkout_vars.is_all_digital = isAllDigital;
    const onReady = vi.fn();
    new AddressService(handlerWithout(missing), onReady);
    await new Promise((resolve) => setTimeout(resolve, 0));
    return onReady.mock.calls.length;
}

describe('AddressService runs onReady once whatever address selects exist', () => {
    beforeEach(() => {
        vi.stubGlobal('fetch', vi.fn(async () => ({ok: true, json: async () => ({message: 'ok'})})));
        vi.stubGlobal('document', {getElementById: () => null, addEventListener: () => {}});
        vi.stubGlobal('window', {
            fluentcart: {$t: (text) => text},
            fluentcart_checkout_info: {checkout_nonce: 'nonce', store_country: 'DE'},
            fluentcart_checkout_vars: {ajaxurl: 'https://shop.test/wp-admin/admin-ajax.php', is_all_digital: 0},
            fluent_cart_checkout_ui_service: {
                setComponentState: vi.fn(),
                setLoading: vi.fn(),
                finishLoading: vi.fn(),
                holdCheckoutButton: vi.fn(),
                releaseCheckoutButton: vi.fn(),
            },
            addEventListener: () => {},
        });
    });

    it('all selects present', async () => {
        expect(await readyCalls([])).toBe(1);
    });

    it('state field turned off', async () => {
        expect(await readyCalls(['billing_state', 'shipping_state'])).toBe(1);
    });

    it('shipping state missing only', async () => {
        expect(await readyCalls(['shipping_state'])).toBe(1);
    });

    it('shipping country missing', async () => {
        expect(await readyCalls(['shipping_country'])).toBe(1);
    });

    it('billing country missing on a physical cart', async () => {
        expect(await readyCalls(['billing_country'])).toBe(1);
    });

    it('billing country missing on a digital cart', async () => {
        expect(await readyCalls(['billing_country', 'shipping_country', 'shipping_state'], 1)).toBe(1);
    });

    it('digital cart with all selects', async () => {
        expect(await readyCalls([], 1)).toBe(1);
    });
});
