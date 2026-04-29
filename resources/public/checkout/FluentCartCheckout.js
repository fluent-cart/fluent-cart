import FluentCartCheckoutHandler from './FluentCartCheckoutHandler';
import CheckoutHelper from "./CheckoutHelper";
import TurnstileHandler from "./TurnstileHandler";

// Expose CheckoutHelper to window for payment gateway JS files
window.CheckoutHelper = CheckoutHelper;

let firstTimeLoad = true;
let fluentCheckout = {
    checkoutPageContainer: null,
    init() {


        this.checkoutPageContainer =
            document.querySelector('[data-fluent-cart-checkout-page]');
        if (!this.checkoutPageContainer) {
            return;
        }
        let form = this.checkoutPageContainer.querySelector(
            'form[data-fluent-cart-checkout-page-checkout-form]'
        );
        return new FluentCartCheckoutHandler(this, form, {});
    },
};

// Initialize Turnstile Handler
// console.log('Initializing Turnstile Handler from FluentCartCheckout.js');
// const turnstileHandler = new TurnstileHandler();

window.addEventListener('load', function () {
    const wrapper = document.querySelector('[data-fluent-cart-checkout-item-wrapper]');
    const subtotalWrapper = document.querySelector('[data-fluent-cart-checkout-subtotal]');
    const estimatedTotalWrappers = document.querySelectorAll('[data-fluent-cart-checkout-estimated-total]');

    let baseUrl = window.fluentcart_checkout_vars.ajaxurl + `?action=fluent_cart_checkout_routes&fc_checkout_action=get_checkout_summary_view`;

    if (wrapper) {
        window.addEventListener(
            'fluentCartNotifyCartDrawerItemChanged',
            function () {

                // if(firstTimeLoad){
                //     firstTimeLoad = false;
                //     return;
                // }
                fetch(
                    CheckoutHelper.buildUrl(baseUrl).toString(),
                    {
                        headers: {
                            'X-WP-Nonce': window.fluentCartRestVars.rest.nonce
                        },
                        credentials: 'include'
                    }
                )
                    .then(response => {
                        return response.json();
                    })
                    .then(response => {
                        if (response.fragments) {
                            CheckoutHelper.handleFragments(response.fragments);
                        }
                        window.dispatchEvent(new CustomEvent('fluentCartCheckoutDataChanged', {
                            detail: {
                                response: response
                            }
                        }));
                    })
                    .catch(error => {
                        console.error('An error occurred:', error);
                    });
            },
            false
        );
    }

    window.fluentCartCheckout = fluentCheckout.init();



    if(window.fluentCartCheckout){
        window.fluentCartCheckout['beforeCheckoutCallbacks'] = [];
        window.fluentCartCheckout['afterCheckoutCallbacks'] = [];
    }


    setTimeout(() => {
        window.dispatchEvent(
            new CustomEvent("fluent_cart_after_checkout_js_loaded", {})
        );

        // Auto-render Turnstile widget if enabled
        // turnstileHandler.autoRenderWidget();
    }, 500)

});
