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


        // Radio input(s)
        const radioInputs = document.querySelectorAll('input[name="_fct_pay_method"]');
        radioInputs.forEach((radio) => {
            radio.addEventListener('change', (event) => {
                if (event.target.checked) {
                    this.saveCustomerData('_fct_pay_method', event.target.value);
                }
            });
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


        const checkboxes = [
            'ship_to_different'
        ];

        for (const checkbox of checkboxes) {
            const elements = document.querySelectorAll(`input[name="${checkbox}"]`);


            elements.forEach((element) => {
                element.addEventListener('change', (event) => {
                    if (event.target.checked === true) {
                        this.saveCustomerData(checkbox, 'yes');
                    } else {
                        this.saveCustomerData(checkbox, 'no');
                    }
                });
            });
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
                //"Content-Type": "application/json",
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

    prepareUtmData() {
        const formData = new FormData(this.form);
        formData.append("_wpnonce", this.nonce);

        const utmData = window.fluentCartUtmManager.get();

        Object.keys(utmData).forEach((key) => {
            const value = utmData[key];
            if (value) {
                formData.append(`utm_data[${key}]`, value);
            }

        })
        return formData;
    }

}
