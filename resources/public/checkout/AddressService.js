import CheckoutHelper from "./CheckoutHelper";
import Url from "@/utils/support/Url";
import dayjs from "dayjs";
import utc from "dayjs/plugin/utc";
import timezone from "dayjs/plugin/timezone";
import NiceSelect from "../components/select/NiceSelect";
import LocalizationService from "./LocalizationService";
import {saveResponseSequence, startCheckoutSave} from "./shippingDestination";
import {ownPickSave, savedByPick} from "./selectPickSave";

export default class AddressService {
    static instance = null;

    #timezone = null;
    formWrapper;
    checkoutHandler = null;
    onReady = null;
    defaultCountry = null;
    #nonce = '';
    translate = window.fluentcart.$t;
    static init(checkoutHandler, onReady) {

        if (AddressService.instance === null) {
            AddressService.instance = new AddressService(checkoutHandler, onReady);
        }
        window.fluent_cart_address_service = AddressService.instance;
        return AddressService.instance;
    }

    constructor(checkoutHandler, onReady) {
        this.formWrapper = checkoutHandler.form;
        this.checkoutHandler = checkoutHandler;
        this.isAllDigital = window.fluentcart_checkout_vars?.is_all_digital.toString() === '1';
        this.#nonce = window.fluentcart_checkout_info.checkout_nonce;
        dayjs.extend(utc);
        dayjs.extend(timezone);
        this.#timezone = dayjs.tz.guess();
        this.onReady = onReady;
        this.initCustomBillingSelect();
    }

    async initCustomBillingSelect() {
        let countrySelector = this.formWrapper.querySelector('#billing_country');
        if (!countrySelector) {
            this.initShippingOrReady({});
            return;
        }

        let selectedCountryValue = '';
        const billingAddressInput = this.formWrapper.querySelector('#billing_address_id');
        const billingAddressCountry = billingAddressInput?.dataset?.country || '';

        if (countrySelector.value) {
            selectedCountryValue = countrySelector.value;
        } else if (billingAddressCountry) {
            selectedCountryValue = billingAddressCountry;
        }

        let response = null;
        const shouldPersistDetectedCountry = !selectedCountryValue;

        if (!countrySelector || (!this.#timezone && !selectedCountryValue)) {
            selectedCountryValue = window.fluentcart_checkout_info.store_country;
        }

        if (selectedCountryValue) {
            response = await LocalizationService.getCountryInfo(selectedCountryValue);
        } else {
            response = await LocalizationService.getCountryInfoByTimeZone(this.#timezone);
            if (shouldPersistDetectedCountry && response?.country_code) {
                this.saveCustomerData('billing_country', response?.country_code);
            }
        }

        if (countrySelector && response) {
            countrySelector.value = response?.country_code;
        }

        if (response.cached && shouldPersistDetectedCountry) {
            this.saveCustomerData('billing_country', response?.country_code);
        }

        let stateSelector = this.formWrapper.querySelector('#billing_state');
        let defaultState = '';
        if (stateSelector) {
            defaultState = stateSelector.value;
        }

        this.stateLabel = this.translate('State');
        if (response && response.address_locale?.state?.label) {
            this.stateLabel = response.address_locale?.state?.label
        }
        if (stateSelector) {
            this.billingStateSelect = new NiceSelect(stateSelector, {
                placeholder: this.stateLabel,
                data: null
            });
        }

        let defaultCountry = '';
        if (countrySelector) {
            defaultCountry = countrySelector.value;
        }
        this.defaultCountry = defaultCountry;


        if (countrySelector) {
            this.billingCountrySelect = new NiceSelect(countrySelector, {
                placeholder: 'Select a Country',
                clearable: false,
                data: null,
            });
        }

        if (countrySelector && defaultCountry) {
            this.billingCountrySelect.setSelected(defaultCountry);
        }


        if (countrySelector) {
            if (countrySelector.value === defaultCountry) {
                if (stateSelector) {
                    this.billingStateSelect.setSelected(defaultState);
                }
            }
        }

        const ref = this;
        if (countrySelector) {
            ownPickSave(this.billingCountrySelect,
                async (value) => {
                    window.fluent_cart_checkout_ui_service.setComponentState('billingStateSelect', 'loading');
                    // Save after the state input is rebuilt, so the posted form carries its reset value.
                    try {
                        if (value) {
                            // const response = await this.getCountryInfo(null, value);
                            const response = await LocalizationService.getCountryInfo(value);

                            if (response) {
                                window.fluent_cart_checkout_ui_service.setComponentState('billingStateSelect', 'enable');
                            }

                            if (window.fluent_cart_shipping_service && window.fluent_cart_shipping_service.shippingToDifferent === false) {
                                if (response.views) {
                                    window.fluent_cart_shipping_service.updateShippingMethodsView({
                                        view: response.views.shipping_methods,
                                        status: true
                                    });
                                } else {
                                    window.fluent_cart_shipping_service.handleBillingAddressChange(value);
                                }
                            }


                            this.updateStateInput(response, 'billing');
                        } else {
                            if (this.formWrapper.querySelector('#billing_state')) {
                                this.formWrapper.querySelector('#billing_state').value = '';
                                this.billingStateSelect.setOptions([]);
                            }
                        }
                    } finally {
                        ref.saveCustomerData('billing_country', value);
                    }
                }
            )
            this.onCountryChanged('billing', response, response?.country_code);
        }


        this.updateStateInput(response, 'billing');

        // const ref = this;
        if (stateSelector && this.billingStateSelect) {
            // this.billingStateSelect.setOnChange(
            //     async (value) => {
            //         ref.saveCustomerData('billing_state', value);
            //     }
            // )
        }


        this.initShippingOrReady({response, defaultCountry, defaultState});
    }

    initShippingOrReady(shippingData) {
        if (this.isAllDigital) {
            this.callOnReady();
        } else {
            this.initCustomShippingSelect(shippingData);
        }
    }

    // The checkout starts ShippingService here, so this must run even when an address select is turned off.
    callOnReady() {
        if (typeof this.onReady === 'function') {
            this.onReady();
        }
    }

    initCustomShippingSelect(shippingData) {
        const shippingStateSelector = this.formWrapper.querySelector('#shipping_state');
        const shippingCountrySelector = this.formWrapper.querySelector('#shipping_country');
        const shippingAddressInput = this.formWrapper.querySelector('#shipping_address_id');
        const shippingAddressCountry = shippingAddressInput?.dataset?.country || '';
        const shippingAddressState = shippingAddressInput?.dataset?.state || '';

        if (!shippingCountrySelector) {
            this.callOnReady();
            return;
        }

        let shippingDefaultCountry = '';
        if (shippingCountrySelector?.value) {
            shippingDefaultCountry = shippingCountrySelector.value;
        } else if (shippingAddressCountry) {
            shippingDefaultCountry = shippingAddressCountry;
        }

        let shippingDefaultState = '';
        if (shippingStateSelector?.value) {
            shippingDefaultState = shippingStateSelector.value;
        } else if (shippingAddressState) {
            shippingDefaultState = shippingAddressState;
        }

        if (shippingStateSelector) {
            this.shippingStateSelect = new NiceSelect(shippingStateSelector, {
                placeholder: this.stateLabel,
                data: null
            });
        }

        if (shippingCountrySelector) {
            this.shippingCountrySelect = new NiceSelect(shippingCountrySelector, {
                placeholder: false,
                clearable: false,
                data: null
            });
        }

        if (!shippingDefaultCountry) {
            shippingDefaultCountry = shippingData.defaultCountry;
        }

        if (!shippingDefaultState) {
            shippingDefaultState = shippingData.defaultState;
        }

        this.defaultCountry = shippingDefaultCountry;

        if (shippingCountrySelector) {
            this.shippingCountrySelect.setSelected(shippingDefaultCountry);
        }

        if (shippingStateSelector && shippingDefaultCountry) {
            this.shippingStateSelect.setSelected(shippingDefaultState);
        }

        if (shippingCountrySelector) {
            ownPickSave(this.shippingCountrySelect,
                async (value) => {

                    window.fluent_cart_checkout_ui_service.setComponentState('shippingStateSelect', 'loading');
                    // Save after the state input is rebuilt, so the posted form carries its reset value.
                    try {
                        if (value) {
                            // const response = await this.getCountryInfo(null, value);
                            const response = await LocalizationService.getCountryInfo(value, 'shipping');

                            if (response) {
                                window.fluent_cart_checkout_ui_service.setComponentState('shippingStateSelect', 'enable');
                            }

                            if (window.fluent_cart_shipping_service) {
                                if (response.views) {
                                    window.fluent_cart_shipping_service.updateShippingMethodsView({
                                        view: response.views.shipping_methods,
                                        status: true
                                    });
                                } else {
                                    window.fluent_cart_shipping_service.handleShippingAddressChange(value);
                                }
                            }


                            this.updateStateInput(response, 'shipping');
                        } else if (this.formWrapper.querySelector('#shipping_state')) {
                            this.formWrapper.querySelector('#shipping_state').value = '';
                            this.shippingStateSelect.setOptions([]);
                        }
                    } finally {
                        this.saveCustomerData('shipping_country', value);
                    }
                }
            )

            this.onCountryChanged('shipping', shippingData.response, shippingData.response?.country_code);
        }

        this.callOnReady();
    }

    onCountryChanged(type = 'billing', response, country_code = '') {
        const stateSelector = this.formWrapper.querySelector('#' + type + '_state');
        const countrySelector = this.formWrapper.querySelector('#' + type + '_country');

        const defaultCountry = countrySelector.value;
        let defaultState = '';
        if (stateSelector) {
            defaultState = stateSelector.value;
        }


        if (response?.states) {
            let formattedData = [
                {
                    text: 'Select an option',
                    value: ''
                }
            ];
            response?.states.forEach((state) => {
                formattedData.push({
                    text: state.name,
                    value: state.value
                });
            });

            if (type === 'billing') {
                if (stateSelector) {
                    if (this.billingStateSelect) {
                        this.billingStateSelect.setOptions(formattedData)
                    }
                    if (defaultCountry) {
                        this.billingStateSelect.setSelected(defaultState);
                    }
                    const ref = this;
                    ownPickSave(this.billingStateSelect,
                        async (value) => {
                            ref.saveCustomerData('billing_state', value);
                        }
                    )
                }
            } else {
                if (stateSelector) {
                    if (this.shippingStateSelect) {
                        this.shippingStateSelect.setOptions(formattedData);
                    }
                    if (defaultCountry) {
                        this.shippingStateSelect.setSelected(defaultState);
                    }
                    const ref = this;
                    ownPickSave(this.shippingStateSelect,
                        async (value) => {
                            ref.saveCustomerData('shipping_state', value);
                        }
                    )
                }
            }
        }

        this.showHideState(response?.address_locale?.state?.hidden, type);
    }

    updateStateInput(response, type = 'billing') {
        if (!response) {
            return;
        }
        const isHidden = response?.address_locale?.state?.hidden;
        const states = response?.states;
        const id = '#' + type + '_state_wrapper';
        let element = this.formWrapper.querySelector('#' + type + '_state');
        const elementType = (element?.tagName || '').toLowerCase();
        const stateCount = response?.states?.length || 0;
        const isSelectElement = ['select', 'select-one'].includes(elementType);

        if (!element) {
            return;
        }

        const defaultState = element.value;

        if (isHidden) {
            this.formWrapper.querySelector(id).style.display = 'none';
            this.formWrapper.querySelector(id).hidden = true;

        } else {
            this.formWrapper.querySelector(id).style.display = 'block';
            this.formWrapper.querySelector(id).hidden = false;
            this.syncStateLabel(this.formWrapper.querySelector(id), response);

            if (type === 'billing' && this.billingStateSelect !== null) {
                this.billingStateSelect.destroy();
                this.billingStateSelect = null;
            }

            if (type === 'shipping' && this.shippingStateSelect !== null) {
                this.shippingStateSelect.destroy();
                this.shippingStateSelect = null;
            }

            if (stateCount === 0) {
                if (isSelectElement) {
                    let stateLabel = this.translate('State');
                    if (response && response.address_locale?.state?.label) {
                        stateLabel = response.address_locale?.state?.label
                    }
                    element = this.convertElementToType(element, 'input', stateLabel);

                    this.saveOnNativeChange(element);
                }
            } else {
                if (!isSelectElement) {
                    element = this.convertElementToType(element, 'select');

                    this.saveOnNativeChange(element);
                }
                let stateLabel = this.translate('State');
                if (response && response.address_locale?.state?.label) {
                    stateLabel = response.address_locale?.state?.label
                }

                let formattedData = [
                    {
                        text: this.translate('Select %s', stateLabel),
                        value: ''
                    }
                ];
                response?.states.forEach((state) => {
                    formattedData.push({
                        text: state.name,
                        value: state.value
                    });
                });

                if (type === 'shipping') {
                    this.shippingStateSelect = this.initStateSelect(element, response, stateLabel).setOptions(formattedData);
                    this.shippingStateSelect.setSelected(defaultState);
                    const ref = this;
                    ownPickSave(this.shippingStateSelect,
                        async (value) => {
                            ref.saveCustomerData('shipping_state', value);
                        }
                    )
                } else {
                    this.billingStateSelect = this.initStateSelect(element, response, stateLabel).setOptions(formattedData);
                    this.billingStateSelect.setSelected(defaultState);
                    const ref = this;
                    ownPickSave(this.billingStateSelect,
                        async (value) => {
                            ref.saveCustomerData('billing_state', value);
                        }
                    )
                }
            }
            // get parent element of element
            const parent = element.closest('[data-fluent-cart-checkout-page-form-input-wrapper]');
        }
    }


    // A nice-select pick fires change twice before its owner saves; run after it and save only if no pick did.
    saveOnNativeChange(element) {
        let timer = null;
        element.addEventListener('change', () => {
            clearTimeout(timer);
            timer = setTimeout(() => {
                if (!savedByPick(element, element.value)) {
                    this.saveCustomerData(element.name, element.value);
                }
            }, 0);
        });
    }

    initStateSelect(element, response = null, placeholder = false) {
        const label = response?.address_locale?.state?.label || this.translate('State');
        return new NiceSelect(element, {
            placeholder: placeholder,
            onChange: (value) => {

            }
        });
    }


    convertElementToType(element, type, placeholder = null) {
        if (!element || !type) return;

        const newElement = document.createElement(type);

        // Copy common attributes
        newElement.id = element.id;
        newElement.name = element.name;
        newElement.className = element.className;
        newElement.value = element.value;

        // Copy data-* attributes (optional)
        Array.from(element.attributes).forEach(attr => {
            if (attr.name.startsWith('data-')) {
                newElement.setAttribute(attr.name, attr.value);
            }
        });
        if (placeholder) {
            // newElement.setAttribute('data-placeholder', placeholder);
            newElement.setAttribute('placeholder', placeholder);
        }

        // Replace the original element with the new one
        // element.parentNode.replaceChild(newElement, element);
        const parent = element.parentNode;
        if (parent) {
            parent.replaceChild(newElement, element);
        }
        if (type === 'input') {
            newElement.value = '';
        }

        return newElement;
    }

    // The field label styles read data-fct-label / data-fct-field, so follow the country's state naming
    // (Province, County…) and whether the state renders as a select or a text input.
    syncStateLabel(wrapper, response) {
        if (!wrapper || !wrapper.dataset.fctLabel) {
            return;
        }
        const isRequired = /\s\*$/.test(wrapper.dataset.fctLabel);
        const stateLabel = response?.address_locale?.state?.label || this.translate('State');
        wrapper.dataset.fctLabel = stateLabel + (isRequired ? ' *' : '');
        wrapper.dataset.fctField = response?.states?.length ? 'select' : 'input';
    }

    showHideState(hide, type = 'billing') {
        let id = '#' + type + '_state_wrapper';
        let element = this.formWrapper.querySelector('#' + type + '_state');
        if (!this.formWrapper.querySelector(id)) {
            return;
        }
        // if (hide) {
        //     let inputWrapper = this.formWrapper.querySelector(id);
        //     if (inputWrapper.querySelector('.fct-nice-select')) {
        //         inputWrapper.querySelector('.fct-nice-select').remove();
        //     }
        //     setTimeout(() => {
        //         element.classList.remove('hidden-select');
        //         this.convertElementToType(element, 'input', 'State');
        //
        //         element.addEventListener('change', () => {
        //             this.saveCustomerData(element.name, element.value);
        //         });
        //     }, 400);
        // }
        if (hide) {
            this.formWrapper.querySelector(id).style.display = 'none';
            this.formWrapper.querySelector(id).hidden = true;
        } else {
            this.formWrapper.querySelector(id).style.display = 'block';
            this.formWrapper.querySelector(id).hidden = false;
        }
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

        window.fluent_cart_checkout_ui_service.setLoading('shippingMethodsWrapper');
        const shipToDifferent = !!this.formWrapper.querySelector('input[name="ship_to_different"]')?.checked;
        const save = startCheckoutSave(params, column, value, shipToDifferent, window.fluent_cart_checkout_ui_service);
        let stored = false;

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
            stored = response.ok;
            return response.json();
        }).then(data => {
            save.settled(stored);
            if (!saveResponseSequence.shouldApply(save.sequence, data)) {
                return;
            }
            if (data?.fragments) {
                CheckoutHelper.handleFragments(data.fragments);
            }
            if (data?.shipping_charge_changes || data?.tax_total_Changes) {
                this.checkoutHandler.handleCheckoutAmountChanges();
            }
        }).catch((error) => {
            save.settled(false);
            throw error;
        }).finally(() => {
            window.fluent_cart_checkout_ui_service.finishLoading('shippingMethodsWrapper');
            save.release();
        });

    }

}
