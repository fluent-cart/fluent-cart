// IIFE keeps top-level bindings out of the global scope — the minified build
// renames them to short names (e.g. `_`) that collide with other classic
// (non-module) payment scripts on the same page.
(function () {

// Re-selecting the payment method re-fires the load event and wipes the
// container, which makes the superseded zoid render reject with "Detected
// container element removed from DOM" — teardown, not a failure to surface.
let renderGeneration = 0;

class PaypalCheckout {
    constructor(form, orderHandler, response, paymentLoader) {
        this.form = form;
        this.orderHandler = orderHandler;
        this.data = response;
        this.paymentLoader = paymentLoader;
        this.$t = this.translate.bind(this);
    }

    translate(string) {
        const translations = window.fct_paypal_data?.translations || {};
        return translations[string] || string;
    }

    init() {
        const ref = this;
        const paymentData = this.data;
        const intentMode = this.data?.intent?.mode;

        renderGeneration++;

        let paypalButtonContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_paypal');
        paypalButtonContainer.innerHTML = '';
        // Hide payment methods
        const paymentMethods = this.form.querySelector('.fluent_cart_payment_methods');
        if (paymentMethods) {
            paymentMethods.style.display = 'none';
        }

        // System checkout: informational save-and-auto-charge disclosure next to
        // the button. PayPal's own approval popup carries the save agreement.
        if (paypalButtonContainer) {
            const staleConsentEl = paypalButtonContainer.parentNode.querySelector('.fct-system-charge-consent');
            if (staleConsentEl) {
                staleConsentEl.remove();
            }

            if (paymentData?.system_consent) {
                const consentEl = document.createElement('p');
                consentEl.className = 'fct-system-charge-consent';
                consentEl.style.cssText = 'margin-top:8px;font-size:12px;opacity:0.75;';
                consentEl.textContent = paymentData.system_consent;
                paypalButtonContainer.insertAdjacentElement('afterend', consentEl);
            }
        }

        let orderData = null;

        if ('payment' === intentMode) {
            this.onetimePaymentHandler(ref, paymentData, orderData, paypalButtonContainer);
        } else if ('subscription' === intentMode) {
            this.subscriptionPaymentHandler(ref, paymentData, orderData, paypalButtonContainer);
        } else if ('setup' === intentMode) {
            // The vault buttons flow needs the SDK id token; if its generation
            // failed at page load, show an error instead of a dead button.
            if (window.fct_paypal_data?.vault_setup_unavailable === 'yes') {
                this.renderSetupUnavailable(paypalButtonContainer);
                return;
            }
            this.setupPaymentHandler(ref, paymentData, orderData, paypalButtonContainer);
        }
    }

    handleConfirmFailed(message) {
        this.paymentLoader?.changeLoaderStatus(message || this.$t('Payment confirmation failed'));
        setTimeout(() => {
            this.paymentLoader?.hideLoader();
            this.paymentLoader?.enableCheckoutButton();
        }, 1000);
    }

    redirectToConfirmationPage(url) {
        // Handle redirect based on checkout mode (modal or single page)
        if (window.CheckoutHelper) {
            window.CheckoutHelper.handleCheckoutRedirect(url);
        } else {
            // Fallback if CheckoutHelper is not available
            window.location.href = url;
        }
    }

    // Shared server confirmation: POST the params, redirect on success,
    // surface every failure through handleConfirmFailed.
    sendConfirmationRequest(params) {
        const that = this;
        const xhr = new XMLHttpRequest();
        xhr.open('POST', window.fluentcart_checkout_vars.ajaxurl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

        xhr.onload = function () {
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    const res = JSON.parse(xhr.responseText);
                    if (res?.status === 'pending' && res?.redirect_url) {
                        // PayPal is holding the capture — no money moved, so the
                        // order-complete actions must not run. Show why, then send the
                        // buyer to the receipt page, which renders the pending state.
                        that.paymentLoader?.changeLoaderStatus(res?.message || that.$t('Your payment is being reviewed. We will confirm your order once it completes.'));
                        setTimeout(() => {
                            that.paymentLoader?.changeLoaderStatus('redirecting');
                            that.redirectToConfirmationPage(res.redirect_url);
                        }, 2500);
                    } else if (res?.redirect_url) {
                        that.paymentLoader.triggerPaymentCompleteEvent(res);
                        that.paymentLoader?.changeLoaderStatus('redirecting');
                        that.redirectToConfirmationPage(res.redirect_url);
                    } else {
                        that.handleConfirmFailed(res?.message || that.$t('Payment confirmation failed'));
                    }
                } catch (error) {
                    that.handleConfirmFailed(that.$t('Payment confirmation failed'));
                }
            } else {
                that.handleConfirmFailed(that.$t('Payment confirmation failed'));
            }
        };

        xhr.onerror = function () {
            that.handleConfirmFailed(that.$t('Payment confirmation failed'));
        };

        xhr.send(params.toString());
    }

    // Shared render tail: success event + loader cleanup on resolve, full
    // recovery (selector restored, error shown) on rejection.
    finalizeButtonsRender(buttons, paypalButtonContainer) {
        const that = this;
        const generation = renderGeneration;
        buttons.render(paypalButtonContainer).then(() => {
            if (generation !== renderGeneration) {
                return; // superseded by a newer render
            }
            window.dispatchEvent(new CustomEvent('fluent_cart_payment_method_loading_success', {
                detail: {
                    payment_method: 'paypal'
                }
            }));
            const loadingElement = document.getElementById('fct_loading_payment_processor');
            if (loadingElement) {
                loadingElement.remove();
            }
        }).catch((err) => {
            if (generation !== renderGeneration) {
                return; // superseded render torn down by init() — not an error
            }
            that.renderPaymentMethodFailure(paypalButtonContainer, err?.message || that.$t('An error occurred while loading PayPal.'));
        });

        const extraText = document.createElement('p');
        extraText.id = 'fct-extra-checkout-text-for-paypal';
        extraText.style.textAlign = 'center';
        extraText.style.marginTop = '10px';
        extraText.style.fontSize = '14px';
        extraText.style.color = '#666';
        extraText.textContent = this.$t('Choose any option to continue');
        paypalButtonContainer.appendChild(extraText);
    }

    async onetimePaymentHandler(ref, paymentData, orderData, paypalButtonContainer) {
        const that = this;
        const params = new URLSearchParams(window.location.search);
        const mode = params.get('mode') || 'order';
        const buttons = paypal.Buttons({
            style: {
                shape: 'pill',
                layout: 'vertical',
                label: 'paypal',
                size: 'responsive',
                disableMaxWidth: true
            },
            createOrder: () => {
                that.paymentLoader?.changeLoaderStatus('processing');
                return orderData.response.paypalOrderId;
            },
            onApprove: (data, actions) => {
                that.paymentLoader?.changeLoaderStatus('confirming');
                return actions.order.capture().then((details) => {
                    if (details.id) {
                        that.paymentLoader?.changeLoaderStatus('completed');
                        that.sendConfirmationRequest(new URLSearchParams({
                            action: 'fluent_cart_confirm_paypal_payment',
                            payId: details.id,
                            ref_id: orderData?.data?.transaction.uuid ? orderData?.data?.transaction.uuid : that.$t('uuid not found'),
                            mode: mode
                        }));
                    }
                });
            },
            onCancel: (data) => {
                that.paymentLoader?.changeLoaderStatus('canceled');
                that.paymentLoader?.hideLoader();
                that.paymentLoader.enableCheckoutButton();
            },
            onShippingChange: (data, actions) => {
                return actions.resolve();
            },
            onError: (err) => {
                that.handlePaypalError(err, orderData);
            },
            onClick: async function (data, actions) {
                if (typeof ref.orderHandler === 'function') {
                    let response = await ref.orderHandler();
                    if (!response) {
                        that.paymentLoader?.changeLoaderStatus(that.$t('Order creation failed'));
                        that.paymentLoader?.hideLoader();
                        return actions.reject();
                    }
                    orderData = response;
                } else {
                    that.paymentLoader?.changeLoaderStatus(that.$t('Not proper order handler'));
                    that.paymentLoader?.hideLoader();
                    return actions.reject();
                }
            }
        });

        this.finalizeButtonsRender(buttons, paypalButtonContainer);
    }

    // Zero-payable system checkout (free trial): buttons run PayPal's vault
    // setup-token flow; the approved token is exchanged server-side.
    async setupPaymentHandler(ref, paymentData, orderData, paypalButtonContainer) {
        const that = this;
        const params = new URLSearchParams(window.location.search);
        const mode = params.get('mode') || 'order';
        const buttons = paypal.Buttons({
            style: {
                shape: 'pill',
                layout: 'vertical',
                label: 'paypal',
                size: 'responsive',
                disableMaxWidth: true
            },
            createVaultSetupToken: () => {
                that.paymentLoader?.changeLoaderStatus('processing');
                return orderData.response.setupTokenId;
            },
            onApprove: (data) => {
                that.paymentLoader?.changeLoaderStatus('confirming');
                that.sendConfirmationRequest(new URLSearchParams({
                    action: 'fluent_cart_confirm_paypal_vault_setup',
                    setup_token: data.vaultSetupToken,
                    ref_id: orderData?.data?.transaction.uuid ? orderData?.data?.transaction.uuid : that.$t('uuid not found'),
                    mode: mode
                }));
            },
            onCancel: (data) => {
                that.paymentLoader?.changeLoaderStatus('canceled');
                that.paymentLoader?.hideLoader();
                that.paymentLoader.enableCheckoutButton();
            },
            onError: (err) => {
                that.handlePaypalError(err, orderData);
            },
            onClick: async function (data, actions) {
                if (typeof ref.orderHandler === 'function') {
                    let response = await ref.orderHandler();
                    if (!response) {
                        that.paymentLoader?.changeLoaderStatus(that.$t('Order creation failed'));
                        that.paymentLoader?.hideLoader();
                        return actions.reject();
                    }
                    orderData = response;
                } else {
                    that.paymentLoader?.changeLoaderStatus(that.$t('Not proper order handler'));
                    that.paymentLoader?.hideLoader();
                    return actions.reject();
                }
            }
        });

        this.finalizeButtonsRender(buttons, paypalButtonContainer);
    }

    async subscriptionPaymentHandler(ref, paymentData, orderData, paypalButtonContainer) {
        const that = this;
        const params = new URLSearchParams(window.location.search);
        const mode = params.get('mode') || 'order';
        const buttons = paypal.Buttons({
            style: {
                shape: 'pill',
                layout: 'vertical',
                label: 'paypal',
                size: 'responsive',
                disableMaxWidth: true
            },
            createSubscription: function (data, actions) {
                return actions.subscription.create({
                    'plan_id': orderData.response.planId,
                    'custom_id': orderData.data.subscription.uuid,
                    'application_context': {
                        'shipping_preference': 'NO_SHIPPING'
                    }
                });
            },
            onApprove: function (data, actions) {
                that.paymentLoader?.changeLoaderStatus('confirming');
                if (data.subscriptionID) {
                    that.paymentLoader?.changeLoaderStatus('completed');
                    that.sendConfirmationRequest(new URLSearchParams({
                        action: 'fluent_cart_confirm_paypal_subscription',
                        order_id: data.orderID,
                        subscription_id: data.subscriptionID,
                        ref_id: orderData?.data?.transaction.uuid,
                        mode: mode
                    }));
                } else {
                    that.paymentLoader?.changeLoaderStatus(that.$t('No Subscription ID'));
                    that.paymentLoader?.hideLoader();
                }
            },
            onCancel: (data) => {
                that.paymentLoader?.changeLoaderStatus('canceled');
                that.paymentLoader?.hideLoader();
                that.paymentLoader.enableCheckoutButton();
            },
            onShippingChange(data, actions) {
                return actions.resolve();
            },
            onClick: async function (data, actions) {
                that.paymentLoader?.changeLoaderStatus(that.$t('no processing'));
                if (typeof ref.orderHandler === 'function') {
                    let response = await ref.orderHandler();
                    if (!response) {
                        that.paymentLoader?.changeLoaderStatus(that.$t('Order creation failed'));
                        that.paymentLoader?.hideLoader();
                        return actions.reject();
                    }
                    orderData = response;
                } else {
                    that.paymentLoader?.changeLoaderStatus(that.$t('not proper order handler'));
                    that.paymentLoader?.hideLoader();
                    return actions.reject();
                }
            },
            onError: function (err) {
                that.handlePaypalError(err, orderData);
            }
        });

        this.finalizeButtonsRender(buttons, paypalButtonContainer);
    }

    renderSetupUnavailable(paypalButtonContainer) {
        this.renderPaymentMethodFailure(
            paypalButtonContainer,
            this.$t('PayPal is temporarily unavailable for this checkout. Please choose another payment method or try again later.')
        );
    }

    // Shared recovery when the buttons cannot render: restore the selector
    // init() hid, drop the loader, show the error, mark the method failed.
    renderPaymentMethodFailure(paypalButtonContainer, message) {
        const paymentMethods = this.form.querySelector('.fluent_cart_payment_methods');
        if (paymentMethods) {
            paymentMethods.style.display = '';
        }

        const errorDiv = document.createElement('div');
        errorDiv.style.color = 'red';
        errorDiv.className = 'fct-error-message';
        errorDiv.textContent = message || this.$t('An error occurred while loading PayPal.');
        paypalButtonContainer.appendChild(errorDiv);

        const loadingElement = document.getElementById('fct_loading_payment_processor');
        if (loadingElement) {
            loadingElement.remove();
        }

        window.dispatchEvent(new CustomEvent('fluent_cart_payment_method_loading_failed', {
            detail: {
                payment_method: 'paypal'
            }
        }));
    }

    handlePaypalError(err, orderData) {
        let errorMessage = this.$t('An unknown error occurred');

        if (err?.message) {
            try {
                const jsonMatch = err.message.match(/{.*}/s);
                if (jsonMatch) {
                    errorMessage = JSON.parse(jsonMatch[0]).message || errorMessage;
                } else {
                    errorMessage = err.message;
                }
            } catch {
                errorMessage = err.message || errorMessage;
            }
        }

        this.paymentLoader?.changeLoaderStatus(err);
        this.paymentLoader?.hideLoader();
        this.paymentLoader.enableCheckoutButton();
    }
}


window.addEventListener("fluent_cart_load_payments_paypal", function (e) {

    window.dispatchEvent(new CustomEvent('fluent_cart_payment_method_loading', {
        detail: {
            payment_method: 'paypal'
        }
    }));

    const translate = window.fluentcart.$t;
    addLoadingText();
    fetch(e.detail.paymentInfoUrl, {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "X-WP-Nonce": e.detail.nonce,
        },
        credentials: 'include'
    }).then(async (response) => {
        response = await response.json();
        if (response?.status === 'failed') {
            displayErrorMessage(response?.message);
        }
        new PaypalCheckout(e.detail.form, e.detail.orderHandler, response, e.detail.paymentLoader).init();
    }).catch(error => {
        const translations = window.fct_paypal_data?.translations || {};
        function $t(string) {
            return translations[string] || string;
        }
        let message = error?.message || $t('An error occurred while loading PayPal.');
        displayErrorMessage(message);
    });

    function displayErrorMessage(message) {
        const errorDiv = document.createElement('div');
        errorDiv.style.color = 'red';
        errorDiv.className = 'fct-error-message';
        errorDiv.textContent = message;

        const paypalContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_paypal');
        paypalContainer.appendChild(errorDiv);

        const loadingElement = document.getElementById('fct_loading_payment_processor');
        if (loadingElement) {
            loadingElement.remove();
        }
        return;
    }

    function addLoadingText() {
        let paypalButtonContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_paypal');
        const loadingMessage = document.createElement('p');
        loadingMessage.id = 'fct_loading_payment_processor';
        const translations = window.fct_paypal_data?.translations || {};
        function $t(string) {
            return translations[string] || string;
        }
        loadingMessage.textContent = $t('Loading Payment Processor...');
        paypalButtonContainer.appendChild(loadingMessage);
    }
});

})();
