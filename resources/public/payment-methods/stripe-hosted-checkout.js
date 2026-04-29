/**
 * Stripe Hosted Checkout Handler
 * Handles button state for Stripe hosted/redirect mode
 */

window.addEventListener("fluent_cart_load_payments_stripe", function (e) {
    const translate = window.fluentcart.$t || ((str) => str);
    const $t = translate;
    
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

        if (response?.status === 'failed') {
            displayErrorMessage(response?.message || $t('Something went wrong'));
            e.detail.paymentLoader?.disableCheckoutButton();
            return;
        }

        // For hosted mode, just show a message and enable the button
        if (stripeContainer) {
            const infoMessage = `
                <div class="stripe-hosted-checkout-info" style="padding: 15px; background: #f8f9fa; border-radius: 4px; margin: 10px 0;">
                    <p style="margin: 0; color: #495057; font-size: 14px;">
                        ${$t('You will be redirected to Stripe to complete your payment securely.')}
                    </p>
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
        console.error('Stripe hosted checkout error:', error);
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

