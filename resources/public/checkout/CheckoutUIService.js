export default class CheckoutUIService {
    static instance = null;
    formWrapper = null;

    states = {
        loading: 'loading',
        enable: 'enable',
        disable: 'disable',
        loadingAndDisabled: 'loadingAndDisabled',
    };

    viewsStates = {};

    views = {
        billingCountry: null,
        billingState: null,
        billingStateSelect: null,
        shippingCountry: null,
        shippingState: null,
        shippingStateSelect: null,
        checkoutButton: null,
        shippingMethodsWrapper: null,
    };


    static init(checkoutHandler,) {

        if (CheckoutUIService.instance === null) {
            CheckoutUIService.instance = new CheckoutUIService(checkoutHandler);
            window.dispatchEvent(new CustomEvent("checkout_ui_service_initialized",{
                detail: {
                    service: CheckoutUIService.instance
                }
            }));
        }

        window.fluent_cart_checkout_ui_service = CheckoutUIService.instance;
        return CheckoutUIService.instance;
    }

    constructor(checkoutHandler) {
        this.formWrapper = checkoutHandler.form;
        this.initViews();
    }

    initViews() {
        this.views.billingCountry = this.formWrapper.querySelector('#billing_country');
        this.views.billingState = this.formWrapper.querySelector('#billing_state');

        if (this.views.billingState) {
            this.views.billingStateSelect = this.views.billingState.closest('#billing_state_wrapper');
        }

        this.views.shippingCountry = this.formWrapper.querySelector('#shipping_country');
        this.views.shippingState = this.formWrapper.querySelector('#shipping_state');

        if (this.views.shippingState) {
            this.views.shippingStateSelect = this.views.shippingState.closest('#shipping_state_wrapper');
        }

        this.views.shippingMethodsWrapper = this.formWrapper.querySelector('[data-fluent-cart-checkout-page-shipping-methods-wrapper]');



        this.views.checkoutButton = this.formWrapper.querySelector('#fluent_cart_order_btn');

    }

    setCheckoutButtonText(text) {
        if (!this.views.checkoutButton) {
            return;
        }
        this.views.checkoutButton.innerHTML = text;
    }

    enableCheckoutButton() {
        this.initViews();
        this.setComponentState('checkoutButton', this.states.enable);
    }

    disableCheckoutButton() {
        this.initViews();
        this.setComponentState('checkoutButton', this.states.disable);
    }

    hideCheckoutButton() {
        this.initViews();
        this.hideComponent('checkoutButton');
    }

    showCheckoutButton() {
        this.initViews();
        this.showComponent('checkoutButton');
    }

    isCheckoutButtonDisabled() {
        if(!this.viewsStates.hasOwnProperty('checkoutButton')){
           return false;
        }
        return this.viewsStates['checkoutButton'] !== this.states.enable;
    }

    setComponentState(component, state) {
        if (!this.views[component]) {
            return;
        }

        if (state === this.states.enable) {
            this.views[component].removeAttribute('disabled');
            this.views[component].dataset.loading = '';
        }

        if (state === this.states.disable) {
            this.views[component].setAttribute('disabled', 'disabled');
        }

        if (state === this.states.loading) {
            this.views[component].dataset.loading = 'loading';
        }

        if (state === this.states.loadingAndDisabled) {
            this.views[component].setAttribute('disabled', 'disabled');
            this.views[component].dataset.loading = 'loading';
        }

        this.viewsStates[component] = state;
    }

    hideComponent(component) {
        if (!this.views[component]) {
            return;
        }
        this.views[component].style.display = 'none';
    }

    showComponent(component, display = 'block') {
        if (!this.views[component]) {
            return;
        }
        this.views[component].style.display = display;
    }

    setLoading(component) {
        if (!this.views[component]) {
            return;
        }

        this.views[component].classList.add('fct-loading');
        this.views[component].setAttribute('fct-loading', '1');
    }

    finishLoading(component) {
        if (!this.views[component]) {
            return;
        }

        this.views[component].classList.remove('fct-loading');
        this.views[component].removeAttribute('fct-loading');
    }
}
