import CheckoutHelper from "./CheckoutHelper";
import Url from "@/utils/support/Url";
import CartCheckoutHelper from "./CheckoutHelper";

export default class DataWatcher {

    static instance = null;
    checkoutHandler = null;
    form = null;
    #nonce = ''

    static init(checkoutHandler) {
        if (DataWatcher.instance === null) {
            DataWatcher.instance = new DataWatcher(checkoutHandler);
        }
        window.fluent_cart_checkout_data_watcher = DataWatcher.instance;
        return DataWatcher.instance;
    }

    constructor(checkoutHandler) {
        this.checkoutHandler = checkoutHandler;
        this.form = checkoutHandler.form;
        this.#nonce = window.fluentcart_checkout_info.checkout_nonce;
        this.bindEvents();
    }

    bindEvents() {
        const countrySelects = [
            'billing_country',
            'shipping_country',
        ];
        for (const select of countrySelects) {
            const element = document.getElementById(select);
            if (element) {
                element.addEventListener('change', this.debounce((event) => {
                    this.saveCustomerData(select, event.target.value);
                }, 400));
            }
        }

        const stateSelects = [
            'billing_state',
            'shipping_state',
        ];
        for (const select of stateSelects) {
            const element = document.getElementById(select);
            if (element) {
                element.addEventListener('change', this.debounce((event) => {
                    if (event.target.value === 'Select State' || event.target.value === '') {
                        return;
                    }
                    this.saveCustomerData(select, event.target.value);
                }, 400));
            }
        }


        const inputs = [
            'billing_address',
            'billing_full_name',
            'billing_email',
            'billing_address_1',
            'billing_city',
            'billing_postcode',
            'billing_company_name',
            'billing_legal_registration_id',
            'billing_phone',

            'shipping_address',
            'shipping_full_name',
            'shipping_address_1',
            'shipping_city',
            'shipping_postcode',
            'shipping_company_name',
            'shipping_phone',
        ];

        for (let input of inputs) {
            const element = document.getElementById(input);
            if (element) {
                element.addEventListener('change', this.debounce((event) => {
                    if (input === 'shipping_address') {
                        input = 'shipping_address_id';
                    }
                    if (input === 'billing_address') {
                        input = 'billing_address_id';
                    }
                    this.saveCustomerData(input, event.target.value);
                }, 400));
            }
        }


        // Payment method radios — use event delegation so it survives DOM replacement
        // (when address/state changes trigger fragment re-render of payment methods)
        window.addEventListener('change', (event) => {
            const target = event.target;
            if (target && target.name === '_fct_pay_method' && target.checked) {
                this.saveCustomerData('_fct_pay_method', target.value);
            }
        });

        // const shippingMethods = document.querySelectorAll('input[name="fc_shipping_method"]');
        // shippingMethods.forEach((radio) => {
        //     radio.addEventListener('change', (event) => {
        //         if (event.target.checked) {
        //             this.saveCustomerData('shipping_method_id', event.target.value);
        //         }
        //     });
        // });

        window.addEventListener('change', (event) => {
            const target = event.target;
            if (target && target.name === 'fc_shipping_method' && target.checked) {
                this.saveCustomerData('shipping_method_id', target.value);
            }
        });

        // Save VAT/tax ID on change — only for non-EU (no Apply button); EU saves via Apply click
        this.form.addEventListener('change', (event) => {
            const target = event.target;
            if (!target || !target.matches('[data-fluent-cart-checkout-page-tax-id]')) {
                return;
            }
            const wrapper = target.closest('[data-fluent-cart-checkout-page-tax-wrapper]');
            const hasApplyBtn = wrapper && wrapper.querySelector('[data-fluent-cart-checkout-page-tax-apply-btn]');
            if (!hasApplyBtn) {
                this.saveCustomerData('fct_billing_tax_id', target.value.trim());
            }
        });


        document.addEventListener('change', (event) => {
            if (!event.target.matches('input[name="ship_to_different"]')) return;
            this.saveCustomerData('ship_to_different', event.target.checked ? 'yes' : 'no');
        });

        document.addEventListener('change', (event) => {
            if (!event.target.matches('[data-fluent-cart-b2b-toggle]')) return;
            const isB2B = event.target.checked;
            this.handleB2BToggle(isB2B, event.target);
            this.saveCustomerData('is_business', isB2B ? 'yes' : 'no');
        });
    }

    handleB2BToggle(isB2B, toggleEl = null) {
        const businessSection = document.querySelector('[data-fluent-cart-b2b-section]');
        const toggle = toggleEl || document.querySelector('[data-fluent-cart-b2b-toggle]');

        if (businessSection) {
            businessSection.style.display = isB2B ? '' : 'none';

            businessSection.querySelectorAll('input[data-b2b-required="yes"]').forEach((input) => {
                input.required = isB2B;
                if (isB2B) {
                    input.setAttribute('aria-required', 'true');
                } else {
                    input.removeAttribute('aria-required');
                }
            });
        }

        if (toggle) {
            toggle.setAttribute('aria-expanded', isB2B ? 'true' : 'false');
        }
    }

    debounce(func, delay) {
        let timeout;
        return (...args) => {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), delay);
        };
    }

    saveCustomerData(column, value) {


        const formData = this.checkoutHandler.prepareFormData();

        const utmData = window.fluentCartUtmManager?.get() || {};

        Object.keys(utmData).forEach((key) => {
            if (value) {
                formData.append(`utm_data[${key}]`, utmData[key]);
            }
        })

        const params = new URLSearchParams();
        for (const [key, form_value] of formData.entries()) {
            params.append(key, form_value);
        }

        const saveUrl = CheckoutHelper.buildUrl(`${window.fluentcart_checkout_vars.ajaxurl}?action=fluent_cart_checkout_routes`)
        const url = Url.appendQueryParams(
            saveUrl.toString(), {
                fc_checkout_action: 'save_checkout_data',
                data_key: column,
                data_value: value
            });

        fetch(url, {
            method: "POST",
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                "X-WP-Nonce": this.#nonce,
            },
            credentials: 'include',
            body: params
        }).then((response) => {
            return response.json();
        }).then(data => {
            if (data?.fragments) {
                CheckoutHelper.handleFragments(data.fragments);
            }
            if (data?.shipping_charge_changes || data?.tax_total_Changes) {
                this.checkoutHandler.handleCheckoutAmountChanges();
            }
        })

    }

}
