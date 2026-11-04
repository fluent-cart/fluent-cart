import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';

vi.mock('../../resources/public/checkout/CheckoutHelper', () => ({
    default: {
        buildUrl: () => 'https://shop.test/wp-admin/admin-ajax.php',
        handleFragments: vi.fn(),
    },
}));

vi.mock('@/utils/support/Url', () => ({
    default: {appendQueryParams: (url, query) => url + '?' + new URLSearchParams(query)},
}));

const countryInfo = (code) => ({
    country_code: code,
    states: [{name: 'Alpha', value: code + '-A'}, {name: 'Beta', value: code + '-B'}],
    address_locale: {state: {label: 'State'}},
});

vi.mock('../../resources/public/checkout/LocalizationService', () => ({
    default: {
        getCountryInfo: vi.fn(async (code) => countryInfo(code)),
        getCountryInfoByTimeZone: vi.fn(async () => countryInfo('US')),
    },
}));

const niceSelects = [];

// Mirrors NiceSelect#syncSelectValue on a pick: change, onChange, change again.
vi.mock('../../resources/public/components/select/NiceSelect', () => ({
    default: class {
        constructor(el, config = {}) {
            this.el = el;
            this.onChange = config.onChange || null;
            el._niceSelect = this;
            niceSelects.push(this);
        }

        setOptions() {
            return this;
        }

        setSelected() {}

        setOnChange(callback) {
            this.onChange = callback;
        }

        destroy() {}

        pick(value) {
            this.el.value = value;
            this.el.dispatchEvent(new Event('change', {bubbles: true}));
            if (this.onChange) {
                this.onChange(this.el.value, this.el);
                this.el.dispatchEvent(new Event('change', {bubbles: true, cancelable: true}));
            }
        }
    },
}));

import AddressService from '../../resources/public/checkout/AddressService.js';
import DataWatcher from '../../resources/public/checkout/DataWatcher.js';

class FakeField extends EventTarget {
    constructor(id, tagName, value = '') {
        super();
        this.id = id;
        this.name = id;
        this.tagName = tagName;
        this.value = value;
        this.dataset = {};
        this.style = {};
        this.attributes = [];
        this.className = '';
    }

    closest() {
        return null;
    }
}

function checkoutForm() {
    const fields = {
        billing_country: new FakeField('billing_country', 'SELECT', 'US'),
        billing_state: new FakeField('billing_state', 'SELECT', 'US-A'),
        billing_postcode: new FakeField('billing_postcode', 'INPUT', '10001'),
        billing_state_wrapper: new FakeField('billing_state_wrapper', 'DIV'),
    };
    const form = {
        querySelector: (selector) => fields[selector.replace('#', '')] || null,
        addEventListener: () => {},
    };
    const handler = {
        form,
        prepareFormData: () => {
            const formData = new FormData();
            for (const key of ['billing_country', 'billing_state', 'billing_postcode']) {
                formData.append(key, fields[key].value);
            }
            return formData;
        },
        handleCheckoutAmountChanges: vi.fn(),
    };
    return {fields, form, handler};
}

const savedKeys = (fetchMock) => fetchMock.mock.calls.map(([url]) => new URL(url).searchParams.get('data_key'));

describe('a country or state pick saves once', () => {
    let fetchMock;
    let checkout;

    beforeEach(async () => {
        vi.useFakeTimers();
        niceSelects.length = 0;
        fetchMock = vi.fn(async () => ({ok: true, json: async () => ({message: 'ok'})}));
        vi.stubGlobal('fetch', fetchMock);

        checkout = checkoutForm();
        vi.stubGlobal('document', {
            getElementById: (id) => checkout.fields[id] || null,
            addEventListener: () => {},
        });
        vi.stubGlobal('window', {
            fluentcart: {$t: (text) => text},
            fluentcart_checkout_info: {checkout_nonce: 'nonce', store_country: 'US'},
            fluentcart_checkout_vars: {ajaxurl: 'https://shop.test/wp-admin/admin-ajax.php', is_all_digital: 1},
            fluent_cart_checkout_ui_service: {
                setComponentState: vi.fn(),
                setLoading: vi.fn(),
                finishLoading: vi.fn(),
                holdCheckoutButton: vi.fn(),
                releaseCheckoutButton: vi.fn(),
            },
            addEventListener: () => {},
        });

        const ready = new Promise((resolve) => {
            new AddressService(checkout.handler, resolve);
        });
        await vi.advanceTimersByTimeAsync(0);
        await ready;
        new DataWatcher(checkout.handler);
        await vi.advanceTimersByTimeAsync(1000);
        fetchMock.mockClear();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    const selectFor = (field) => [...niceSelects].reverse().find((select) => select.el === checkout.fields[field]);

    it('saves billing_country once for one pick', async () => {
        selectFor('billing_country').pick('CA');
        await vi.advanceTimersByTimeAsync(1000);

        expect(savedKeys(fetchMock)).toEqual(['billing_country']);
    });

    it('saves billing_state once for one pick', async () => {
        selectFor('billing_state').pick('US-B');
        await vi.advanceTimersByTimeAsync(1000);

        expect(savedKeys(fetchMock)).toEqual(['billing_state']);
    });

    it('still saves a country the browser autofilled without the nice select', async () => {
        checkout.fields.billing_country.value = 'CA';
        checkout.fields.billing_country.dispatchEvent(new Event('change'));
        await vi.advanceTimersByTimeAsync(1000);

        expect(savedKeys(fetchMock)).toEqual(['billing_country']);
    });

    it('still saves a plain postcode input', async () => {
        checkout.fields.billing_postcode.value = '90210';
        checkout.fields.billing_postcode.dispatchEvent(new Event('change'));
        await vi.advanceTimersByTimeAsync(1000);

        expect(savedKeys(fetchMock)).toEqual(['billing_postcode']);
    });
});
