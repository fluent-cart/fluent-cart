import AddressField from "./AddressField";
import PaymentLoader from "../payments/payment-loader";
import CouponService from "./CouponService";
import dayjs from "dayjs";
import utc from "dayjs/plugin/utc";
import timezone from "dayjs/plugin/timezone";
import CheckoutHelper from "./CheckoutHelper";
import Url from "../../admin/utils/support/Url";
import ShippingService from "./ShippingService";
import AddressService from "./AddressService";
import DataWatcher from "./DataWatcher";
import CheckoutUIService from "./CheckoutUIService";
import TaxService from "./TaxService";

class FluentCartCheckoutHandler {
    static #instance = null;
    #paymentLoader = null;
    #cartData;
    #appliedCoupons = [];

    #timezone = null;
    #baseUrl;
    #submitButton;
    #shipToDifferentCheckbox = null;
    #shippingToDifferent = false;
    #orderNotesToggle;
    #orderNotesWrapper;
    #isZeroPayment = false;
    #hasSubscriptions = false;
    checkoutUiService;
    translate = window.fluentcart.$t;

    get #fluentCartCart() {
        return window.fluentCartCart;
    }




    constructor(checkoutFormContainer, form, settings) {
        // Check if window.fluentCartCart is defined
        dayjs.extend(utc);
        dayjs.extend(timezone);
        this.#timezone = dayjs.tz.guess();

        if (FluentCartCheckoutHandler.#instance !== null) {
            return FluentCartCheckoutHandler.#instance;
        }
        this.form = form;
        this.settings = settings;
        this.#baseUrl = window.fluentCartRestVars.rest.url;
        this.checkoutInfo = window.fluentcart_checkout_info;
        this.#isZeroPayment = window.fluentcart_checkout_info?.is_zero_payment === 'yes';
        this.#hasSubscriptions = window.fluentcart_checkout_info?.has_subscription == 'yes';
        this.nonce = this.checkoutInfo?.rest.nonce;
        this.checkoutUrl = this.checkoutInfo?.order_confirmation_url;
        this.checkoutUrl = CheckoutHelper.buildUrl(this.checkoutUrl);
        this.#submitButton = window.fluentcart_checkout_vars?.submit_button;
        this.#shipToDifferentCheckbox = this.form.querySelector("[data-fluent-cart-ship-to-different-address]");
        this.#orderNotesToggle = this.form.querySelector("[data-fluent-cart-checkout-order-notes-toggle]");
        this.#orderNotesWrapper = this.form.querySelector("[data-fluent-cart-checkout-order-notes-wrapper]");
        this.isAllDigital = window.fluentcart_checkout_vars?.is_all_digital.toString() === '1';

        this.buttons = this.form.querySelector("[data-fluent-cart-checkout-page-checkout-button]");

        this.checkoutUiService = CheckoutUIService.init(this);

        this.#paymentLoader = new PaymentLoader({
            form: this.form,
            orderHandler: this.handleOrder.bind(this),
            checkoutHandler: this
        });


        new AddressField(this).init();
        new CouponService(this.form).init();

        AddressService.init(this, () => {

            setTimeout(() => {
                DataWatcher.init(this);
                if (!this.isAllDigital) {
                    //if everything is digital, no need to init shipping service
                    ShippingService.init(this.form, this);
                }
            }, 1000);

        });

        // init tax service if needed
        if (window.fluentcart_checkout_vars?.tax_settings?.enable_tax === 'yes') {
            TaxService.init(this);
        }


        this.init();
        this.handleShippingForm(form);
        this.loadPaymentEmbedModules();
        this.formatErrorFields();
        this.handleCartItemsCollapse();
        this.handleCouponItemsCollapse();
        this.isCartLocked = window.fluentcart_checkout_vars?.is_cart_locked.toString() === 'yes';
        // this.initCustomSelect();
        this.setDefaultCountry();
        //this.load();
        this.setupDeleteCartItemHandler();
        this.handleCountryChange();
        this.handlePhoneInput();
        this.handleOrderNotesToggle();
    }

    handleCountryChange() {

        this.#shipToDifferentCheckbox?.addEventListener('change', (event) => {
            this.#shippingToDifferent = event.target.checked;
        });

    }

    async setDefaultCountry() {

    }

    handleCheckoutAmountChanges() {
        return;
        const params = CheckoutHelper.getParams();
        const url = CheckoutHelper.buildUrl(`${window.fluentcart_checkout_vars.ajaxurl}?action=fluent_cart_checkout_routes&fc_checkout_action=get_checkout_summary_view`, params).toString();
        this.buttonState(
            "order_processing",
            this.translate("Please wait..."),
            "Processing"
        );

        fetch(url, {
            headers: {
                'X-WP-Nonce': window.fluentCartRestVars.rest.nonce
            },
            credentials: 'include',
        }).then((response) => {
            return response.json();
        }).then(data => {

            if (data?.fragments) {
                CheckoutHelper.handleFragments(data.fragments);
            }

            window.dispatchEvent(new CustomEvent('fluentCartCheckoutDataChanged', {
                detail: {
                    response: data
                }
            }));
        })
    }


    async load() {
        try {
            const data = await this.loadCartItems();
            if (data) {
                this.#cartData = data;
            }
            return this.#cartData;
        } catch (error) {
            console.error('Error loading cart data:', error);
        }
    }

    async loadCartItems() {
        try {
            return await this.#fluentCartCart.getCart();
        } catch (error) {
            console.error('Error fetching cart items:', error);
            throw error;
        }
    }

    setupDeleteCartItemHandler() {
        const ref = this;
        document.addEventListener('click', async (e) => {
            if (e.target.matches('[data-fluent-cart-checkout-item-remove-button]')) {
                const button = e.target;
                const itemId = button.dataset.fluentCartCheckoutItemId;

                try {
                    await ref.#fluentCartCart.removeProduct(itemId);
                    const newCartData = await ref.load();

                    if (!newCartData || Object.keys(newCartData).length === 0) {
                        location.reload();
                    }
                } catch (error) {
                    console.error('Failed to remove product:', error);
                }
            }
        });
    }

    formatErrorFields() {
        const containers = document.querySelectorAll("[data-fluent-cart-checkout-page-form-section]");
        containers.forEach(container => {
            const errors = container.querySelectorAll("[data-fluent-cart-checkout-page-form-error]");
            errors.forEach(error => {
                const cloned = error.cloneNode(true);
                error.remove();
                container.appendChild(cloned);
            });
        });
    }

    init() {


        this.form.addEventListener("submit", (e) => {
            e.preventDefault();
            if (e.detail !== undefined) {
                return;
            }
            this.handleOrder(e);
        });

        if (this.buttons) {
            this.buttons.addEventListener("click", (event) => {
                event.preventDefault();
                this.form.dispatchEvent(new Event("submit", {bubbles: true, cancelable: true}));
            });
        }
    }

    loadPaymentEmbedModules() {
        this.#paymentLoader.load();
    }

    handleShippingForm(form) {
        this.disableRequiredShipping();
        const checkoutHandler = this;

        const shipToDifferent = form.querySelector("[data-fluent-cart-ship-to-different-address]");
        const shippingFields = form.querySelector("[data-fluent-cart-checkout-page-shipping-fields]");
        if (shipToDifferent?.checked) {
            this.#shippingToDifferent = true;
            shippingFields.style.display = 'block';
            const shippingInputs = shippingFields.querySelectorAll('input[data-required="disabled"]');
            shippingInputs.forEach(input => {
                input.dataset.required = "yes";
                input.required = true;
            });
        }
        if (shipToDifferent) {
            shipToDifferent.addEventListener("change", function (e) {
                const shippingFields = form.querySelector("[data-fluent-cart-checkout-page-shipping-fields]");
                if (this.checked) {
                    shippingFields.style.display = 'block';
                    const shippingInputs = shippingFields.querySelectorAll('input[data-required="disabled"]');
                    shippingInputs.forEach(input => {
                        input.dataset.required = "yes";
                        input.required = true;
                    });
                } else {
                    checkoutHandler.disableRequiredShipping();
                }
            });
        }
    }

    disableRequiredShipping() {
        const shippingFieldsWrapper = this.form.querySelector("[data-fluent-cart-checkout-page-shipping-fields]");
        if (shippingFieldsWrapper) {
            shippingFieldsWrapper.style.display = 'none';
            const shippingInputs = shippingFieldsWrapper.querySelectorAll('input[data-required="yes"]');
            shippingInputs.forEach(input => {
                input.dataset.required = "disabled";
                input.required = false;
            });
        }
    }

    buttonState(state, message, buttonState = "disabled") {
        if (buttonState === "disabled") {
            this.#paymentLoader.disableCheckoutButton(message);
        } else if (buttonState === "hide") {
            this.#paymentLoader.hideCheckoutButton();
        } else {
            this.#paymentLoader.enableCheckoutButton(message);
        }
        this.form.classList.add("fluent_cart_" + state);
    }

    async handleOrder(e) {
        if (this.checkoutUiService.isCheckoutButtonDisabled()) {
            return;
        }
        const errorMessages = document.querySelectorAll('.fct-error-message');
        errorMessages.forEach(msg => msg.remove());

        this.payMethod = this.#paymentLoader.payMethod;
        const paymentElementState = window["is_" + this.payMethod + "_ready"];

        if (this.payMethod !== "offline_payment" && !paymentElementState) {
            console.warn("Dev Warn FCT: Payment method is not ready yet!");
            window.dispatchEvent(
                new CustomEvent("fluent_cart_validate_checkout_" + this.payMethod)
            );
            return false;
        }

        this.#paymentLoader.disableCheckoutButton(this.translate("Processing Order..."));

        const loaderElement = '<div class="loader"></div>';
        let processingDiv = document.querySelector('.fct-order-processing');
        if (!processingDiv) {
            processingDiv = this.createProcessingDiv(loaderElement);
            document.body.appendChild(processingDiv);
        } else {
            processingDiv.classList.remove('hidden');
            processingDiv.style.display = '';
        }

        this.removeValidationErrors();

        const callbacks = window.fluentCartCheckout?.beforeCheckoutCallbacks;

        let proceed = true;
        if (Array.isArray(callbacks)) {
            for (let callback of callbacks) {
                if (typeof callback === "function") {
                    let result = await callback({
                        paymentLoader: this.#paymentLoader
                    });
                    proceed = proceed && result;
                }
            }
        }

        if (proceed === false) {
            return;
        }

        try {
            const formData = this.prepareFormData();

            this.removeValidationErrors();
            const response = await this.submitCheckoutForm(formData);
            const data = await response.json();

            if (data.status === 'success') {
                window.fluentCartUtmManager?.clear();
                this.handleSuccessfulResponse(data);
                return data;
            } else {
                if (data?.errors && typeof data?.errors === 'object') {
                    let message = '';
                    for (const fieldKey in data.errors) {
                        const errorData = data.errors[fieldKey];
                        const firstError = Object.values(errorData)[0];
                        message += firstError + ' \n';
                    }
                    new Toastify({
                        text: message,
                        className: "warning",
                        duration: 1500
                    }).showToast();
                    this.cleanupAfterProcessing();
                    return false;
                }
                this.showValidationErrors(data?.errors);
                let message = data?.message || data?.message;
                if (message) {
                    this.showProductValidationError(message);
                }
                this.cleanupAfterProcessing();
                return false;
            }
        } catch (data) {
            this.cleanupAfterProcessing();
            return false;
        }
    }

    createProcessingDiv(loaderElement) {
        const processingDiv = document.createElement('div');
        processingDiv.className = 'fct-order-processing';
        processingDiv.innerHTML = loaderElement + "<br/><div class='loading-status'>" + this.translate("Order Processing...") + "</div><div class='fct-browser-notify'>" + this.translate("Please Don't close the browser") + "</div>";
        return processingDiv;
    }

    prepareFormData() {
        const formData = new FormData(this.form);
        formData.append("_wpnonce", this.nonce);

        const utmData = window.fluentCartUtmManager.get();

        Object.keys(utmData).forEach((key) => {
            const value = utmData[key];
            if (value) {
                formData.append(`utm_data[${key}]`, value);
            }
        });

        return formData;
    }

    async submitCheckoutForm(formData) {
        formData.append("user_tz", this.#timezone);

        // Convert object to URLSearchParams
        const params = new URLSearchParams();
        for (const [key, value] of formData.entries()) {
            params.append(key, value);
        }

        let url = this.checkoutUrl.toString();

        return await fetch(url, {
            method: 'POST',
            headers: {
                'X-WP-Nonce': window.fluentCartCheckout.nonce,
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: params
        });
    }

    async handleSuccessfulResponse(data) {

        const callbacks = window.fluentCartCheckout?.afterCheckoutCallbacks;

        if (Array.isArray(callbacks)) {
            for (let callback of callbacks) {
                if (typeof callback === "function") {
                    await callback(data);
                }
            }
        }

        if (data?.redirect_to) {
            this.buttonState(
                "order_processing",
                this.translate("Please wait..."),
                data?.buttonState
            );
            window.location.href = data.redirect_to;
        }

        if (data?.actionName === "custom") {
            window.dispatchEvent(
                new CustomEvent(
                    "fluent_cart_payment_next_action_" + data?.nextAction,
                    {
                        detail: {
                            response: data,
                        }
                    }
                )
            );
        } else if (data?.actionName === "redirect") {
            window.location.href = data?.payment_args?.checkout_url;
        }
    }

    cleanupAfterProcessing() {
        const processingDiv = document.querySelector('.fct-order-processing');
        if (processingDiv) {
            processingDiv.classList.add('hidden');
            processingDiv.style.display = 'none';
        }
        this.#paymentLoader.enableCheckoutButton(this.translate("Place Order"));
    }

    removeValidationErrors() {
        document.querySelectorAll("[data-fluent-cart-checkout-page-form-input-wrapper]").forEach(el => {
            el.classList.remove("has-error");
        });
        document.querySelectorAll("[data-fluent-cart-checkout-page-form-error]").forEach(el => {
            el.innerHTML = "";
        });
        document.querySelectorAll("[data-fluent-cart-checkout-page-form-section]").forEach(el => {
            el.classList.remove("has-error");
        });
    }

    showProductValidationError(error = this.translate("Error! Please update cart items!")) {
        new Toastify({
            text: '<span class="warn warning"></span>' + error,
            className: "warning",
            escapeMarkup: false,
            duration: 3000,
            close: true,
            style: {
                color: "#000",
                background: "#ffffff",
            },
        }).showToast();
    }

    showValidationErrors(errors) {
        if (!errors || Object.keys(errors).length < 1) return;

        for (const fieldId in errors) {
            const containers = document.querySelectorAll("[data-fluent-cart-checkout-page-form-section]");
            const input = this.form.querySelector('#' + fieldId);
            //const input = document.getElementById(fieldId);
            if (input) {
                if (input.closest("[data-fluent-cart-checkout-page-form-input-wrapper]")) {
                    input.closest("[data-fluent-cart-checkout-page-form-input-wrapper]").classList.add("has-error");
                }
                containers.forEach(container => container.classList.add("has-error"));

                const errorMessages = errors[fieldId];
                if (typeof errorMessages === "object") {
                    let errorMessage = "";
                    let index = 0;
                    const errorCount = Object.entries(errorMessages).length;

                    for (const messageId in errorMessages) {
                        errorMessage += errorMessages[messageId];
                        if (index !== errorCount) {
                            errorMessage += "<br>";
                        }
                        index++;
                        break;
                    }

                    new Toastify({
                        text: errorMessage,
                        className: "warning",
                        escapeMarkup: false,
                        duration: 1500,
                        style: {
                            color: "#000",
                            background: "#ffffff",
                        },
                    }).showToast();

                    if (fieldId === 'order_notes') {
                        new Toastify({
                            text: '<span class="warn error"></span>' + errorMessage,
                            className: "error",
                            escapeMarkup: false,
                            duration: 1500,
                            style: {
                                color: "#000",
                                background: "#ffffff",
                            },
                        }).showToast();
                    }
                    const selector = `[data-fluent-cart-checkout-page-form-error][for="${input.name}"]`;
                    document.querySelector(selector).innerHTML = errorMessage;
                    document.querySelector(selector).classList.add("show_error");

                }
            } else if (fieldId === 'shipping_method') { // shipping method element structure is different from other input fields
                const shippingMethodsWrapper = document.querySelector("[data-fluent-cart-checkout-page-shipping-methods-wrapper]");
                if (shippingMethodsWrapper) {
                    shippingMethodsWrapper.classList.add("has-error");
                    shippingMethodsWrapper.querySelector("[data-fluent-cart-checkout-page-form-error]").innerHTML = errors[fieldId];
                }
            } else {
                new Toastify({
                    text: '<span class="warn warning"></span>' + this.translate("Validation Error! Please fill required fields!"),
                    className: "warning",
                    escapeMarkup: false,
                    duration: 1300,
                    style: {
                        color: "#000",
                        background: "#ffffff",
                    },
                }).showToast();
            }
        }

    }

    handleCartItemsCollapse() {
        this.form.addEventListener('click', (e) => {
            if (e.target.closest('[data-fluent-cart-checkout-cart-items-toggle]')) {
                this.form.querySelector("[data-fluent-cart-checkout-page-cart-items-wrapper]").classList.toggle('box_closed');
            }
        });
    }

    handleCouponItemsCollapse() {
        const toggleElement = document.querySelector("[data-fluent-cart-checkout-coupon-items-toggle]");
        const targetElement = document.querySelector("[data-fluent-cart-checkout-coupon-items-wrapper]");

        if (toggleElement && targetElement) {
            toggleElement.addEventListener("click", () => {
                toggleElement.classList.toggle("active");
                targetElement.style.display = targetElement.style.display === 'none' ? 'block' : 'none';
            });
        }
    }

    handlePhoneInput() {
        const billingPhoneInput = document.querySelector("[data-fluent-cart-checkout-page-form-input-wrapper][id='billing_phone_wrapper'] input");
        const shippingPhoneInput = document.querySelector("[data-fluent-cart-checkout-page-form-input-wrapper][id='shipping_phone_wrapper'] input");

        const validatePhone = (input) => {
            if (!input) return;

            // Limit to 15 characters
            if (input.value.length > 15) {
                input.value = input.value.substring(0, 15);
            }
        };

        if (billingPhoneInput) {
            billingPhoneInput.addEventListener('input', () => validatePhone(billingPhoneInput));
            billingPhoneInput.addEventListener('paste', () => {
                setTimeout(() => validatePhone(billingPhoneInput), 0);
            });
        }

        if (shippingPhoneInput) {
            shippingPhoneInput.addEventListener('input', () => validatePhone(shippingPhoneInput));
            shippingPhoneInput.addEventListener('paste', () => {
                setTimeout(() => validatePhone(shippingPhoneInput), 0);
            });
        }
    }

    handleOrderNotesToggle() {

        let toggleItems = this.form.querySelectorAll("[data-fct-item-toggle-control]");
        toggleItems.forEach(item => {
            item.addEventListener('click', () => {
                // get the parent element with the attribute data-fct-item
                const parent = item.closest("[data-fct-item-toggle]");
                if (parent) {
                    parent.classList.toggle('active');
                }
            });
        });

        if (!this.#orderNotesToggle) return;
        this.#orderNotesToggle.addEventListener('click', () => {
            this.#orderNotesToggle.classList.toggle('active');
            this.#orderNotesWrapper.classList.toggle('active');
        });
    }
}

export default FluentCartCheckoutHandler;
