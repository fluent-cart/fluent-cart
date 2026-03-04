import SearchableSelect from "../components/select/SearchableSelect";
import CheckoutHelper from "./CheckoutHelper";
import Url from "../../admin/utils/support/Url";
import NiceSelect from "../components/select/NiceSelect";
import timezone from "dayjs/plugin/timezone";
import dayjs from "dayjs";
import utc from "dayjs/plugin/utc";
import CartCheckoutHelper from "./CheckoutHelper";
import LocalizationService from "./LocalizationService";

export default class AddressField {
    static #instance = null;
    #cachedData = {};
    #baseUrl;
    form;
    #timezone = null;
    #updateAddressSelectUrl = null;
    #checkoutHandler = null;
    isAllDigital;
    translate = window.fluentcart.$t;
    #nonce = '';
    #errorConfig = {
        '.fct_error_billing_address_section_section': [
            'billing_full_name',
            'billing_first_name',
            'billing_last_name',
            'billing_address_1',
            'billing_address_2',
            'billing_city',
            'billing_postcode',
            'billing_country',
            'billing_state',
            'billing_phone'
        ],
        '.fct_error_shipping_address_section_section': [
            'shipping_full_name',
            'shipping_first_name',
            'shipping_last_name',
            'shipping_address_1',
            'shipping_address_2',
            'shipping_city',
            'shipping_postcode',
            'shipping_country',
            'shipping_state',
            'shipping_phone'
        ],
    };

    constructor(checkoutHandler) {
        this.checkoutInfo = window.fluentcart_checkout_info;
        this.#checkoutHandler = checkoutHandler
        this.#baseUrl = window.fluentCartRestVars.rest.url
        this.nonce = window.fluentCartRestVars?.rest.nonce;
        this.addAddressUrl = `${this.#baseUrl}/customers/add-address`;
        this.findAddressUrl = `${this.#baseUrl}/customers/`;
        this.#updateAddressSelectUrl = CheckoutHelper.buildUrl(`${window.fluentcart_checkout_vars.ajaxurl}?action=fluent_cart_checkout_routes&fc_checkout_action=update_address_select`);
        this.isAllDigital = window.fluentcart_checkout_vars?.is_all_digital.toString() === '1';

        dayjs.extend(utc);
        dayjs.extend(timezone);
        this.#timezone = dayjs.tz.guess();
        this.#nonce = window.fluentcart_checkout_info.checkout_nonce;
    }

    init() {
        if (AddressField.#instance !== null) {
            return AddressField.#instance;
        }
        this.initAddressFieldModalAction();
        AddressField.#instance = this;

        this.initialSetup();
        this.bindErrorCleanupListeners();

        return this;
    }

    initialSetup() {
        const selectorButton = document.querySelector('[data-fluent-cart-checkout-page-form-address-modal-address-selector-button-wrapper]');
        const activeSelectorButton = document.querySelector('[data-fluent-cart-checkout-page-form-address-modal-address-selector-button].selected');
        const applyBtn = document.querySelector('[data-fluent-cart-checkout-page-form-address-modal-apply-button]');

        if (activeSelectorButton) {
            if (applyBtn) {
                applyBtn.dataset.addressId = activeSelectorButton.dataset.id;
            }
        }

    }

    initAddressFieldModalAction() {
        document.addEventListener('click', (e) => {
            const button = e.target.closest('[data-fluent-cart-checkout-page-form-address-modal-open-button]');
            if (button) {
                const input = this.#getInputField(button);

                if (input) {
                    const selectedId = input.value;
                    const siblingButtons = button.parentNode.querySelectorAll('[data-fluent-cart-checkout-page-form-address-modal-address-selector-button]');
                    siblingButtons.forEach(btn => btn.classList.remove('selected'));

                    const selectedButton = button.parentNode.querySelector(`[data-id='${selectedId}']`);
                    if (selectedButton) selectedButton.classList.add('selected');
                }

                this.#removePreviousModal();
                const modalBody = this.#getModalBody(button);
                const addressType = modalBody.dataset.fluentCartAddressType;

                const cloned = modalBody.cloneNode(true);

                cloned.classList.remove('hidden');
                cloned.classList.add('show');

                this.#bindAddressSelectAction(cloned, button, addressType);
                this.#bindApplyAction(cloned, button);

                cloned.querySelectorAll('[data-fluent-cart-checkout-page-form-address-modal-close-button]').forEach(closeBtn => {
                    closeBtn.addEventListener('click', () => cloned.remove());
                });

                const backdrop = document.createElement('div');
                backdrop.className = 'fluent-cart-checkout-page-form-address-modal-backdrop';
                backdrop.addEventListener('click', () => this.#removePreviousModal());
                cloned.appendChild(backdrop);

                this.#bindAddNewFormOpeningAction(cloned);
                this.#bindAddNewFormClosingAction(cloned);
                this.#prepareAddNewForm(cloned, addressType, button);
                this.#appendModalToBody(cloned);
                // Add ESC key binding
                this.#bindEscKeyAction(cloned);
            }
        });
    }

    #bindEscKeyAction(cloned) {
        const escKeyHandler = (event) => {
            if (event.key === 'Escape' || event.keyCode === 27) {
                this.#removePreviousModal();
                // Remove the event listener to prevent memory leaks
                document.removeEventListener('keydown', escKeyHandler);
            }
        };

        // Add the event listener
        document.addEventListener('keydown', escKeyHandler);

        // Store the handler on the cloned element so we can remove it later if needed
        cloned._escKeyHandler = escKeyHandler;
    }


    #getModalBody(button) {
        const parent = button.closest('[data-fluent-cart-checkout-page-form-address-modal-wrapper]');
        return parent.querySelector('[data-fluent-cart-checkout-page-form-address-modal-body]');
    }

    #removePreviousModal() {
        document.querySelectorAll('body > [data-fluent-cart-checkout-page-form-address-modal-body]').forEach(el => {
            // Remove ESC key event listener if it exists
            if (el._escKeyHandler) {
                document.removeEventListener('keydown', el._escKeyHandler);
            }
            el.remove();
        });
    }

    #appendModalToBody(cloned) {
        document.body.appendChild(cloned);
    }

    #bindAddressSelectAction(cloned, button, addressType) {
        cloned.querySelectorAll('[data-fluent-cart-checkout-page-form-address-modal-address-selector-button]').forEach(selectButton => {
            selectButton.addEventListener('click', () => {
                cloned.querySelectorAll('[data-fluent-cart-checkout-page-form-address-modal-address-selector-button]').forEach(b => b.classList.remove('selected'));
                selectButton.classList.add('selected');
                const applyBtn = cloned.querySelector('[data-fluent-cart-checkout-page-form-address-modal-apply-button]');
                if (applyBtn) {
                    applyBtn.dataset.addressId = selectButton.dataset.id;
                    applyBtn.dataset.country = selectButton.dataset.country;
                    applyBtn.dataset.state = selectButton.dataset.state;
                    applyBtn.dataset.addressType = addressType;
                }

            });
        });
    }

    #bindApplyAction(cloned, button) {
        const applyBtn = cloned.querySelector('[data-fluent-cart-checkout-page-form-address-modal-apply-button]');
        const parent = applyBtn.closest('[data-fluent-cart-checkout-page-form-address-modal-body]');
        const addressType = parent.dataset.fluentCartAddressType;
        if (applyBtn) {
            applyBtn.addEventListener('click', () => {
                const selectedId = applyBtn.dataset.addressId;
                if (!selectedId) return;
                const selectedCountry = applyBtn.dataset.country;
                const selectedState = applyBtn.dataset.state;
                const addressWrapper = this.#getAddressInfoWrapper(button);
                const input = this.#getInputField(button);
                const addressType = applyBtn.dataset.addressType;

                if (input) {
                    const currentCountry = input.dataset.country;
                    const currentState = input.dataset.state;

                    if (currentCountry !== selectedCountry || currentState !== selectedState) {
                        input.value = selectedId;
                        input.dataset.country = selectedCountry;
                        input.dataset.state = selectedState;
                        const event = new Event('change', {bubbles: true, cancelable: true});
                        input.dispatchEvent(event);

                        window.dispatchEvent(
                            new CustomEvent('fluentCartNotifyAddressCountryChanged', {
                                detail: {
                                    country: selectedCountry,
                                    state: selectedState,
                                    input: input,
                                    addressType: addressType
                                }
                            })
                        );
                    }
                }

                if (addressWrapper) this.#updateAddressInfo(selectedId, addressWrapper, addressType);
                this.#removePreviousModal();
            });
        }
    }

    #getInputField(button) {
        const container = button.closest('[data-fluent-cart-checkout-page-form-address-select-wrapper]');
        return container?.querySelector('[data-fluent-cart-checkout-page-form-address-input]') ?? null;
    }

    #getAddressInfoWrapper(button) {
        const container = button.closest('[data-fluent-cart-checkout-page-form-address-select-wrapper]');
        return container?.querySelector('[data-fluent-cart-checkout-page-form-address-info-wrapper]') ?? null;
    }

    #updateAddressInfo(id, addressWrapper, addressType) {

        this.saveCustomerData(addressType+'_address_id', id);
        return;
        const searchParams = new URLSearchParams(window.location.search);
        const url = Url.appendQueryParams(
            this.#updateAddressSelectUrl.toString(), {
                customer_address_id: id
            });

        fetch(url, {
            method: 'GET',
            headers: {
                'X-WP-Nonce': this.nonce
            }
        })
            .then(response => response.json())
            .then(response => {
                if (response?.data) {
                    addressWrapper.outerHTML = response.data;
                }
                if (response?.fragments) {
                    CheckoutHelper.handleFragments(response.fragments);
                }

                if (response?.tax_total_changes || response?.shipping_charge_changes) {
                    this.#checkoutHandler.handleCheckoutAmountChanges();
                }
                new Toastify({
                    text: response.message,
                    className: "success",
                    duration: 900
                }).showToast();

            })
            .catch((error) => {
                console.error('An error occurred:', error);
            });
    }

    #bindAddNewFormOpeningAction(cloned) {
        cloned.querySelectorAll('[data-fluent-cart-checkout-page-form-address-show-add-new-modal-button]').forEach(btn => {
            btn.addEventListener('click', () => this.#showAddressAddForm(cloned, btn));
        });
    }

    #showAddressAddForm(cloned, button) {
        cloned.querySelector('[data-fluent-cart-checkout-page-form-address-modal-address-selector-button-wrapper]').classList.add('hide');
        const fromWrapper = cloned.querySelector('[data-fluent-cart-checkout-page-form-address-show-add-new-modal-form-wrapper]');
        this.form = fromWrapper;
        this.formType = fromWrapper.querySelector('#billing_state') || fromWrapper.querySelector('#billing_country') ? 'billing' : 'shipping';
        this.#initSelect(fromWrapper);

        fromWrapper.classList.add('show');
        button.parentNode.style.display = 'none';
    }

    initStateSelect(element, response = null) {
        const label = response?.address_locale?.state?.label || 'State';
        return SearchableSelect.init(element, {
            placeholder: false,
            onChange: (value) => {
                //console.log(value, 'bb country changed')
            }
        });
    }

    initStateSelect2(element, response = null) {
        const label = response?.address_locale?.state?.label || 'State';
        return new NiceSelect(element, {
            placeholder: false,
            onChange: (value) => {

            }
        });
    }


    async #initSelect(fromWrapper) {

        // const response = await this.getCountryInfo(this.#timezone);
        let response = null;
        const stateSelector = '#' + this.formType + '_state';
        const countrySelector = '#' + this.formType + '_country';

        const countrySelect = fromWrapper.querySelector(countrySelector);
        const stateSelect = fromWrapper.querySelector(stateSelector);

        // Remove leftover DOM if needed (optional safety net)
        if (countrySelect) {
            countrySelect.nextElementSibling?.classList?.contains('fct-nice-select') && countrySelect.nextElementSibling.remove();
        }
        if (stateSelect) {
            stateSelect.nextElementSibling?.classList?.contains('fct-nice-select') && stateSelect.nextElementSibling.remove();

            this.billingStateSelect = new NiceSelect(stateSelect, {
                placeholder: false,
                data: null
            });
        }

        if (countrySelect) {
            new NiceSelect(countrySelect, {
                placeholder: this.translate('Select a Country'),
                clearable: false,
                data: null,
                onChange: async (value) => {
                    if (value) {
                        const response = await LocalizationService.getCountryInfo(value);
                        this.updateStateInput(response, this.formType);
                    } else {
                        fromWrapper.querySelector(stateSelector).value = '';
                        this.billingStateSelect.setOptions([]);
                    }
                }
            })
        }
        if (stateSelect) {
            this.onCountryChanged(this.formType, response);
        }

        //billingStateSelect.disable().enable();
    }

    onCountryChanged(type = 'billing', response) {
        let formattedData = [
            {
                text: 'Select a option',
                value: ''
            }
        ];
        response?.states.forEach((state) => {
            formattedData.push({
                text: state.name,
                value: state.value
            });
        });
        this.billingStateSelect.setOptions(formattedData)
    }


    updateStateInput(response, type = 'billing') {
        if (!response) {
            return;
        }

        const isHidden = response?.address_locale?.state?.hidden;
        const states = response?.states;

        const id = '#' + type + '_state_wrapper';
        let element = this.form.querySelector('#' + type + '_state');
        if (!element) return;
        const elementType = (element?.tagName || '').toLowerCase();
        const stateCount = response?.states?.length || 0;
        const isSelectElement = ['select', 'select-one'].includes(elementType);


        if (isHidden) {
            this.form.querySelector(id).style.display = 'none';
        } else {

            this.form.querySelector(id).style.display = 'block';
            if (this.billingStateSelect !== null) {
                this.billingStateSelect.destroy();
                this.billingStateSelect = null;
            }


            if (stateCount === 0) {
                if (isSelectElement) {
                    element = this.convertElementToType(element, 'input');
                }
            } else {
                if (!isSelectElement) {
                    element = this.convertElementToType(element, 'select');
                }
                let formattedData = [];
                response?.states.forEach((state) => {
                    formattedData.push({
                        text: state.name,
                        value: state.value
                    });
                });
                this.billingStateSelect = this.initStateSelect2(element, response).setOptions(formattedData);
            }
        }
    }

    convertElementToType(element, type) {
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

        // Replace the original element with the new one
        element.parentNode.replaceChild(newElement, element);
        if (type === 'input') {
            newElement.value = '';
        }

        return newElement;
    }


    async getCountryInfo(timezone, country_code) {

        const query = timezone ? {timezone} : {country_code};


        let url = CheckoutHelper.buildUrl(`${window.fluentcart_checkout_vars.ajaxurl}?action=fluent_cart_checkout_routes&fc_checkout_action=get_country_info`).toString();
        url = Url.appendQueryParams(url, query);

        let prefetched = null;

        if (!timezone) {
            prefetched = this.#cachedData[query.country_code];
        }

        if (prefetched) {
            return prefetched;
        }

        let res;
        await fetch(url, {
            headers: {
                'X-WP-Nonce': window.fluentCartRestVars.rest.nonce
            },
            credentials: 'include'
        }).then(async (response) => {
            response = await response.json();
            res = response?.country_info;
            if (response?.fragments) {
                CheckoutHelper.handleFragments(response.fragments);
            }
        });

        return res;
    }

    #bindAddNewFormClosingAction(cloned) {
        cloned.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-fluent-cart-checkout-page-form-address-show-add-new-modal-cancel-button]');
            if (btn) {
                this.#hideAddressAddForm(cloned, btn);
            }
        });
    }

    #hideAddressAddForm(cloned, button) {
        cloned.querySelector('[data-fluent-cart-checkout-page-form-address-modal-address-selector-button-wrapper]').classList.remove('hide');
        cloned.querySelector('[data-fluent-cart-checkout-page-form-address-modal-address-selector-button-wrapper]').classList.add('show');
        cloned.querySelector('[data-fluent-cart-checkout-page-form-address-show-add-new-modal-form-wrapper]').classList.remove('show');
        cloned.querySelector('[data-fluent-cart-checkout-page-form-address-show-add-new-modal-form-wrapper]').classList.add('hide');
        cloned.querySelector('[data-fluent-cart-checkout-page-form-address-show-add-new-modal-button]').parentNode.style.display = '';
    }

    #prepareAddNewForm(cloned, addressType, button) {
        const formWrapper = cloned.querySelector('[data-fluent-cart-checkout-page-form-address-show-add-new-modal-form-wrapper]');
        const formClone = formWrapper.cloneNode(true);
        const form = document.createElement('form');
        form.appendChild(formClone);
        formWrapper.replaceWith(form);

        const productType = this.isAllDigital ? 'digital' : 'physical';

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const formData = new FormData(form);
            formData.append('type', addressType);
            formData.append('product_type', productType);
            //formData.append('_wpnonce', this.nonce);

            const labelKey = `${addressType}_label`;
            if (formData.get(labelKey)?.length > 15) {
                new Toastify({
                    text: this.translate('Label must not exceed 15 characters.'),
                    className: "warning",
                    duration: 900
                }).showToast();
                return;
            }

            this.#handleFormSubmit(cloned, formData, button);
        });
    }

    #handleFormSubmit(cloned, formData, button) {
        this.removeValidationErrors();
        let url = CheckoutHelper.buildUrl(this.addAddressUrl.toString()).toString();

        fetch(url, {
            method: 'POST',
            body: formData,
            headers: {
                'X-WP-Nonce': this.nonce
            }
        })
            .then(async response => {
                if (!response.ok) {
                    this.showValidationErrors(await response.json());
                    throw Error('Failed to submit form');

                } else {
                    return await response.json();
                }
            })
            .then(response => {
                if (response.fragment) {
                    if (Array.isArray(response.fragment)) {
                        response.fragment.forEach((fragment) => {
                            const element = document.querySelector(fragment.selector);
                            if (element && fragment.type === 'replace') {
                                element.outerHTML = fragment.content;
                            }
                        });
                    } else {
                        let element = document.querySelector(response.fragment.selector);
                        if(element && response.fragment.type === 'replace') {
                            element.outerHTML = response.fragment.content;
                        }
                    }
                }

                const wrapper = cloned.querySelector('[data-fluent-cart-checkout-page-form-address-modal-address-selector-button-wrapper]');
                const selectWrapper = document.querySelectorAll('[data-fluent-cart-checkout-page-form-address-select-wrapper]');
                wrapper.classList.remove('hide');
                wrapper.classList.remove('show');

                // selectWrapper.forEach(el => {
                //     const innerWrap = el.querySelector('[data-fluent-cart-checkout-page-form-address-modal-address-selector-button-wrapper]');
                //     innerWrap.insertAdjacentHTML('beforeend', response.data);
                // });
                //
                // if (wrapper && response.data) {
                //     wrapper.insertAdjacentHTML('beforeend', response.data);
                // }
                cloned.querySelector('[data-fluent-cart-checkout-page-form-address-show-add-new-modal-form-wrapper]').classList.remove('show');
                cloned.querySelector('[data-fluent-cart-checkout-page-form-address-show-add-new-modal-form-wrapper]').classList.add('hide');
                if (response.message) {
                    new Toastify({text: response.message, className: "info", duration: 900}).showToast();
                    this.#removePreviousModal();
                }
            })
            .catch(error => {
                //showValidationErrors();
                //console.log('An error occurred while submitting the form:', error);
            });
    }

    removeValidationErrors() {
        document.querySelectorAll('[data-fluent-cart-checkout-page-form-input-wrapper]').forEach(e => e.classList.remove('has-error'));
        document.querySelectorAll('[data-fluent-cart-checkout-page-form-error]').forEach(e => e.innerHTML = '');
    }

    showValidationErrors(errors) {
        const formSections = document.querySelectorAll('[data-fluent-cart-checkout-page-form-section]');
        const fieldErrors = errors?.errors || {};

        formSections.forEach(section => {
            // Loop through each error configuration
            Object.entries(this.#errorConfig).forEach(([selector, fieldKeys]) => {
                const errorElement = section.querySelector(selector);

                if (!errorElement) return;

                const sectionErrors = {};

                fieldKeys.forEach(field => {
                    if (fieldErrors[field]) {
                        sectionErrors[field] = fieldErrors[field];
                    }
                });

                if (Object.keys(sectionErrors).length) {
                    errorElement.classList.add('show_error');
                    errorElement.innerHTML = Object.entries(sectionErrors)
                        .flatMap(([field, errorObj]) =>
                            Object.values(errorObj).map(
                                message => `<span data-fct-error-field="${field}">${message}</span>`
                            )
                        )
                        .join('');
                }else {
                    errorElement.classList.remove('show_error');
                    errorElement.innerHTML = '';
                }
            });
        });

        const messages = Object.values(fieldErrors)
            .flatMap(errorObj => Object.values(errorObj));

        if (messages.length) {
            new Toastify({
                text: messages.join('<br>') || 'Validation Error!',
                className: "warning",
                escapeMarkup: false,
                duration: 3000,
                style: {
                    color: "#000",
                    background: "#ffffff",
                },
            }).showToast();
        }
    }

    clearFieldError(fieldName) {
        Object.entries(this.#errorConfig).forEach(([selector, fields]) => {

            if (!fields.includes(fieldName)) return;

            if(!this.form) return;

            const errorEl = this.form.querySelector(selector);
            if (!errorEl) return;

            const fieldError = errorEl.querySelector(
                `[data-fct-error-field="${fieldName}"]`
            );

            if (!fieldError) return;

            fieldError.classList.add('fct-error-leave');

            fieldError.addEventListener(
                'transitionend',
                () => {
                    fieldError.remove();

                    // hide container if empty
                    if (!errorEl.querySelector('span')) {
                        errorEl.classList.remove('show_error');
                    }
                },
                { once: true }
            );
        });
    }

    bindErrorCleanupListeners() {
        const handler = (e) => {
            const field = e.target;
            if (!field.name) return;
            if (!field.value?.trim()) return;

            this.clearFieldError(field.name);
        };

        document.addEventListener('input', handler);

        document.addEventListener('change', handler);
    }


    saveCustomerData(column, value) {

        const formData = this.#checkoutHandler.prepareFormData();

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
            if (data?.changes) {
                if(data?.changes?.billing_address_id) {
                    const addressId = document.querySelector('#billing_address_id');
                    if (addressId) {
                        addressId.value = data?.changes?.billing_address_id;
                    }
                }
                if(data?.changes?.billing_company_name) {
                    const companyName = document.querySelector('#billing_company_name');
                    if (companyName) {
                        companyName.value = data?.changes?.billing_company_name;
                    }
                }
                if(data?.changes?.billing_full_name) {
                    const fullName = document.querySelector('#billing_full_name');
                    if (fullName) {
                        fullName.value = data?.changes?.billing_full_name;
                    }
                }
            }
            if (data?.fragments) {
                CheckoutHelper.handleFragments(data.fragments);
            }
            if (data?.shipping_charge_changes || data?.tax_total_Changes) {
                this.checkoutHandler.handleCheckoutAmountChanges();
            }
        }).finally(() => {
            window.fluent_cart_checkout_ui_service.finishLoading('shippingMethodsWrapper');
        });

    }
}

