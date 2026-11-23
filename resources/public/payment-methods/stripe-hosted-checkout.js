/**
 * Stripe Hosted Checkout Handler
 * Handles button state for Stripe hosted/redirect mode
 */

// IIFE keeps top-level bindings out of the global scope — the minified build
// renames them to short names (e.g. `_`) that collide with other classic
// (non-module) payment scripts on the same page.
(function () {

let fctStripeHostedRequestId = 0;

window.addEventListener("fluent_cart_load_payments_stripe", function (e) {
    const $t = function(string) {
        const translations = window.fct_stripe_data?.translations || {};
        return translations[string] || string;
    };

    const requestId = ++fctStripeHostedRequestId;
    const isStale = () => requestId !== fctStripeHostedRequestId;

    window.dispatchEvent(new CustomEvent('fluent_cart_payment_method_loading', {
        detail: {
            payment_method: 'stripe'
        }
    }));

    const stripeContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_stripe');

    if (stripeContainer) {
        const loadingMessage = $t('Loading Payment Processor...');
        stripeContainer.innerHTML = '<p id="fct_loading_payment_processor">' + loadingMessage + '</p>';
    }

    fetch(e.detail.paymentInfoUrl, {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "X-WP-Nonce": e.detail.nonce,
        },
        credentials: 'include'
    }).then(async (response) => {
        response = await response.json();

        if (isStale()) {
            return;
        }

        if (response?.status === 'failed') {
            displayErrorMessage(response?.message || $t('Something went wrong'));
            e.detail.paymentLoader?.disableCheckoutButton();
            return;
        }

        // For hosted mode, just show a message and enable the button.
        // system_consent (system/auto-charge subscriptions) must be shown here —
        // this handler redirects to Stripe next, so it's the only place the
        // save-and-auto-charge disclosure can be seen before checkout.
        if (stripeContainer) {
            const consentMessage = response?.system_consent
                ? `<p style="margin:8px 0 0;color:#495057;font-size:12px;opacity:0.85;">${response.system_consent}</p>`
                : '';
            const infoMessage = `
                <div class="stripe-hosted-checkout-info" style="padding: 15px; background: #f8f9fa; border-radius: 4px; margin: 10px 0;">
                    <p style="margin: 0; color: #495057; font-size: 14px;">
                        ${$t('You will be redirected to Stripe to complete your payment securely.')}
                    </p>
                    ${consentMessage}
                </div>
            `;
            stripeContainer.innerHTML = infoMessage;
        }

        // Remove loading message
        const loadingElement = document.getElementById('fct_loading_payment_processor');
        if (loadingElement) {
            loadingElement.remove();
        }

        // Dispatch success event
        window.dispatchEvent(new CustomEvent('fluent_cart_payment_method_loading_success', {
            detail: {
                payment_method: 'stripe'
            }
        }));

        // Enable the checkout button
        const submitButton = window.fluentcart_checkout_vars?.submit_button;
        const paymentMethod = e.detail.form.querySelector('input[name="_fct_pay_method"]:checked');

        if (paymentMethod && paymentMethod.value === 'stripe') {
            e.detail.paymentLoader?.enableCheckoutButton(submitButton?.text || $t('Place Order'));
        }

    }).catch(error => {
        if (isStale()) {
            return;
        }

        displayErrorMessage($t('An error occurred while loading the payment method.'));

        const loadingElement = document.getElementById('fct_loading_payment_processor');
        if (loadingElement) {
            loadingElement.remove();
        }

        e.detail.paymentLoader?.disableCheckoutButton();
    });

    function displayErrorMessage(message) {
        if (!message || !stripeContainer) {
            return;
        }
        const errorDiv = document.createElement('div');
        errorDiv.className = 'fct-error-message';
        errorDiv.style.color = '#dc3545';
        errorDiv.style.fontSize = '14px';
        errorDiv.style.padding = '10px';
        errorDiv.textContent = message;
        stripeContainer.innerHTML = '';
        stripeContainer.appendChild(errorDiv);
    }
});

})();
