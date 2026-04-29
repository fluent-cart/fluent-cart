import CheckoutHelper from "./CheckoutHelper";
import Url from "@/utils/support/Url";
import CartCheckoutHelper from "./CheckoutHelper";
import AddressService from "./AddressService";
import DataWatcher from "./DataWatcher";

export default class ShippingService {

    static instance = null;
    countries = [];
    states = {};
    shippingToDifferent = false;

    billingCountry = null;
    shippingCountry = null;
    loadingShippingInfo = false;
    countryCode = '';

    state = '';
    billingState = '';
    shippingState = '';

    formWrapper;
    shippingContainer;
    checkoutHandler = null;

    currentShippingMethodId = null;
    translate = window.fluentcart.$t;

    static init(form, checkoutHandler) {

        if (ShippingService.instance === null) {
            ShippingService.instance = new ShippingService(form, checkoutHandler);
        }

        AddressService.init(this,  () =>{
            DataWatcher.init(this);
        });


        window.fluent_cart_shipping_service = ShippingService.instance;
        return ShippingService.instance;
    }

    constructor(form, checkoutHandler) {
        this.formWrapper = form;
        this.checkoutHandler = checkoutHandler;
        this.shippingContainer = this.formWrapper.querySelector("[data-fluent-cart-checkout-page-shipping-method-wrapper]");
        this.bindDelegatedListeners();
        this.countryCode = window.fluent_cart_address_service.defaultCountry;

    }

    getShippingContainer(){
        // Refresh reference if DOM was replaced by JS/fragments
        this.shippingContainer = this.formWrapper.querySelector('[data-fluent-cart-checkout-page-shipping-method-wrapper]');
        return this.shippingContainer;
    }

    // Delegated listeners survive DOM fragment replacements
    bindDelegatedListeners() {
        // Initialize known state
        const shipToDifferent = document.getElementById('ship_to_different');
        if (shipToDifferent) {
            this.shippingToDifferent = shipToDifferent.checked;
        }

        const billingAddress = document.getElementById('billing_address');
        if (billingAddress) {
            this.billingCountry = billingAddress.dataset.country;
        }
        const shippingAddress = document.getElementById('shipping_address');
        if (shippingAddress) {
            this.shippingCountry = shippingAddress.dataset.country;
        }

        this.formWrapper.addEventListener('change', (event) => {
            // Ship to different toggle
            if (event.target.id === 'ship_to_different') {
                this.handleShipToDifferentChange(event.target.checked);
                return;
            }

            // Billing address country changes
            if (event.target.id === 'billing_address') {
                // const countryCode = event.target.dataset.country;
                // this.handleBillingAddressChange(countryCode);
                this.countryCode = event.target.dataset.country;
                this.state = event.target.dataset.state;
                this.fetchCountryShippingMethods();
                return;
            }

            if (event.target.id === 'billing_country') {
                this.billingCountry = event.target.value;
                this.handleCountryChange(event.target.value);
                return;
            }

            if (event.target.id === 'shipping_country') {
                this.shippingCountry = event.target.value;
                this.handleCountryChange(event.target.value);
                return;
            }

            // Shipping address country changes
            if (event.target.id === 'shipping_address') {
                // const countryCode = event.target.dataset.country;
                // this.handleShippingAddressChange(countryCode);

                this.countryCode = event.target.dataset.country;
                this.state = event.target.dataset.state;
                this.fetchCountryShippingMethods();
                return;
            }

            if (event.target.matches('select[name="shipping_state"]')) {
                this.shippingState = event.target.value;
                this.handleStateChange();
                return;
            }

            if (event.target.matches('select[name="billing_state"]')) {
                this.billingState = event.target.value;
                this.handleStateChange()
                return;
            }

            // Shipping method radio selection
            if (event.target.matches('input[name="fc_shipping_method"]')) {
                this.handleShippingMethodChange(event.target.value);
                return;
            }
        });
    }

    bindShipToDifferentListener() {
        const shipToDifferent = document.getElementById('ship_to_different');
        if (shipToDifferent) {
            this.shippingToDifferent = shipToDifferent.checked;
            const ref = this;
            shipToDifferent.addEventListener('change', function () {
                ref.handleShipToDifferentChange.bind(ref)(this.checked);
            });
        }
    }

    handleShipToDifferentChange(checked) {
        this.shippingToDifferent = checked;
        const shippingAddress = document.getElementById('shipping_address');
        const ref = this;
        if (shippingAddress && shippingAddress.dataset.country && this.shippingToDifferent) {
            // if (this.shippingToDifferent) {
            //     this.shippingCountry = shippingAddress.dataset.country;
            // } else {
            //     this.shippingCountry = this.billingCountry;
            // }
            this.countryCode = shippingAddress.dataset.country;
            this.state = shippingAddress.dataset.state;
            this.fetchCountryShippingMethods();
            // ref.handleShippingAddressChange.bind(ref)(shippingAddress.dataset.country);
        } else {
            let country = '';
            let state = '';
            if (this.shippingToDifferent) {
                if (this.formWrapper.querySelector('#shipping_country')) {
                    country = this.formWrapper.querySelector('#shipping_country').value;
                }
                if (this.formWrapper.querySelector('#shipping_state')) {
                    state = this.formWrapper.querySelector('#shipping_state').value;
                }
            } else {
                if (this.formWrapper.querySelector('#billing_country')) {
                    country = this.formWrapper.querySelector('#billing_country').value;
                }
                if (this.formWrapper.querySelector('#billing_state')) {
                    state = this.formWrapper.querySelector('#billing_state').value;
                }
            }

            this.countryCode = country;
            this.state = state;

            this.fetchCountryShippingMethods();
        }
    }

    bindBillingCountryListener() {
        const billingAddress = document.getElementById('billing_address');

        if (billingAddress) {
            const ref = this;
            this.billingCountry = billingAddress.dataset.country;
            billingAddress.addEventListener('change', function () {
                const countryCode = this.dataset.country;
                ref.handleBillingAddressChange.bind(ref)(countryCode)
            });
            return;
        }


        const billingCountry = document.getElementById('billing_country');
        if (billingCountry) {
            const ref = this;
            this.billingCountry = billingCountry.value;
            billingCountry.addEventListener('change', function () {
                //ref.handleBillingAddressChange.bind(ref)(this.value);
            });
        }
    }

    bindShippingCountryListener() {
        const shippingAddress = document.getElementById('shipping_address');

        if (shippingAddress) {
            const ref = this;
            this.shippingCountry = shippingAddress.dataset.country;
            shippingAddress.addEventListener('change', function () {
                const countryCode = this.dataset.country;
                ref.handleShippingAddressChange.bind(ref)(countryCode)
            });
            return;
        }


        const shippingCountry = document.getElementById('shipping_country');
        if (shippingCountry) {
            this.shippingCountry = shippingCountry.value;
            const ref = this;
            shippingCountry.addEventListener('change', function () {
                //ref.handleShippingAddressChange.bind(ref)(this.value);
            });
        }
    }

    handleBillingAddressChange(countryCode) {

        this.billingCountry = countryCode;

        if (!this.shippingToDifferent) {
            this.shippingCountry = countryCode;
        }

        // this.fetchCountryShippingMethods();
    }

    handleCountryChange() {
        if (this.shippingToDifferent) {
            this.countryCode = this.shippingCountry;
        } else {
            this.countryCode = this.billingCountry;
        }
    }

    handleShippingAddressChange(countryCode) {
        if (this.shippingToDifferent) {
            this.shippingCountry = countryCode;
        } else {
            this.shippingCountry = this.billingCountry;
        }
        // this.fetchCountryShippingMethods();
    }

    handleStateChange() {
        if (this.shippingToDifferent) {
            this.state = this.shippingState;
        } else {
            this.state = this.billingState;
        }
        this.fetchCountryShippingMethods();
    }

    fetchCountryShippingMethods() {


        return;
        if (this.loadingShippingInfo) {
            return;
        }
        this.loadingShippingInfo = true;
        let url = CheckoutHelper.buildUrl(`${window.fluentcart_checkout_vars.ajaxurl}?action=fluent_cart_checkout_routes&fc_checkout_action=get_shipping_methods_list_view`).toString();

        const query = {
            country_code: this.countryCode,
            state: this.state
        };
        url = Url.appendQueryParams(url, query);

        fetch(url, {
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': window.fluentcart_checkout_vars.rest.nonce
            },
        }).then(async (response) => {
            response.json().then(data => {
                if (data?.fragments) {
                    CheckoutHelper.handleFragments(data.fragments);
                }
               // this.updateShippingMethodsView(data);
            });

            //res = response;
            //this.onShippingDataFetched(response, container)
        }).finally(() => {
            this.loadingShippingInfo = false;
        });
    }

    updateShippingMethodsView(data){
        if (data.status === false) {

            if (data.fragments) {
                CheckoutHelper.handleFragments(data.fragments);
                return;
            }
            
            this.shippingContainer.innerHTML = data.view || `<div class="fct-empty-text">${data.message}</div>`;
            return;
        }
        if (this.getShippingContainer()) {
            this.getShippingContainer().innerHTML = data.view;
        }
        this.checkoutHandler?.buttons.removeAttribute('disabled');

        let shippingMethodInputs = '';
        if (this.getShippingContainer()) {
            shippingMethodInputs = this.getShippingContainer().querySelectorAll('input[name="fc_shipping_method"]');
        }


        if (shippingMethodInputs.length > 0) {
            // Select default via existing helper; listeners are delegated
            this.selectDefaultShippingMethod(shippingMethodInputs);
        }
    }

    bindShippingMethodChangeListener() {

        this.currentShippingMethodId = null;
        this.setShippingMethodIdToForm();
        const shippingMethodInputs = this.shippingContainer.querySelectorAll('input[name="fc_shipping_method"]');

        // Select first shipping method on load
        if (shippingMethodInputs.length > 0) {
            this.selectDefaultShippingMethod(shippingMethodInputs);
        }

        // No direct listeners; form-level delegated listener handles change
    }

    selectDefaultShippingMethod(shippingMethodInputs) {
        shippingMethodInputs[0].checked = true;

        setTimeout(() => {
            this.handleShippingMethodChange(shippingMethodInputs[0].value);
        }, 0);
    }

    setShippingMethodIdToForm() {
        const hiddenInput = this.getShippingContainer().querySelector('input[name="fc_selected_shipping_method"]');
        if(!hiddenInput){
            return;
        }
        hiddenInput.value = this.currentShippingMethodId;
    }

    handleShippingMethodChange(shippingMethodId) {

        return;
        this.currentShippingMethodId = shippingMethodId;
        this.setShippingMethodIdToForm();

        let shippingChargeAmount = this.formWrapper.querySelector('[data-fluent-cart-checkout-shipping-amount]');
        let shippingChargeAmountWrapper = this.formWrapper.querySelector('[data-fluent-cart-checkout-shipping-amount-wrapper]');

        const estimatedTotalWrappers = document.querySelectorAll('[data-fluent-cart-checkout-estimated-total]');

        if (shippingChargeAmount) {
            shippingChargeAmount.innerHTML = `<span class="fct-loading-text">${this.translate("Loading...")}</span>`;
        }
        //remove class shipping-charge-hidden from shippingChargeAmountWrapper
        if (shippingChargeAmountWrapper) {
            shippingChargeAmountWrapper.classList.remove('shipping-charge-hidden');
        }
  

        const params = CartCheckoutHelper.getParams();

        const url = CheckoutHelper.buildUrl(`${window.fluentcart_checkout_vars.ajaxurl}?action=fluent_cart_checkout_routes&fc_checkout_action=get_checkout_summary_view`, params).toString();
        this.checkoutHandler?.buttonState(
            "order_processing",
            this.translate("Please wait..."),
            this.translate("Processing")
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
                // After JS fragment replacement, ensure container reference is fresh and a method is selected
                const inputs = this.getShippingContainer().querySelectorAll('input[name="fc_shipping_method"]');
                const anyChecked = this.getShippingContainer().querySelector('input[name="fc_shipping_method"]:checked');
                if (inputs.length && !anyChecked) {
                    this.selectDefaultShippingMethod(inputs);
                }
                // Re-query shipping amount elements since DOM may be replaced
                shippingChargeAmount = this.formWrapper.querySelector('[data-fluent-cart-checkout-shipping-amount]');
                shippingChargeAmountWrapper = this.formWrapper.querySelector('[data-fluent-cart-checkout-shipping-amount-wrapper]');
            }

            // Update shipping charge UI with fresh references
            if (shippingChargeAmount) {
                shippingChargeAmount.innerHTML = data.formatted_shipping_charge;
            }
            if (shippingChargeAmountWrapper) {
                const hasValue = !!(data && data.formatted_shipping_charge && data.formatted_shipping_charge.toString().trim());
                if (hasValue) {
                    shippingChargeAmountWrapper.classList.remove('shipping-charge-hidden');
                } else {
                    shippingChargeAmountWrapper.classList.add('shipping-charge-hidden');
                }
            }

            window.dispatchEvent(new CustomEvent('fluentCartNotifyShippingMethodChanged', {
                detail: {
                    response:data,
                    billingCountry: this.billingCountry,
                    shippingCountry: this.shippingCountry,
                    shippingMethodId
                }
            }));

            window.dispatchEvent(new CustomEvent('fluentCartCheckoutDataChanged', {
                detail: {
                    response: data
                }
            }));
        })
    }
}
