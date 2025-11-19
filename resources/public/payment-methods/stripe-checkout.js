class StripeCheckout {
    constructor(form, response, paymentLoader) {
        this.form = form;
        this.data = response;
        this.paymentArgs = response.payment_args;
        this.intent = response.intent;
        this.paymentLoader = paymentLoader;
        this.$t = this.translate.bind(this);
    }

    translate(string) {
        const translations = window.fct_stripe_data?.translations || {};
        return translations[string] || string;
    }

    async init() {

        const button = document.querySelector('[data-fluent-cart-checkout-page-checkout-button]');

        // Clear the container
        const stripeContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_stripe');

        // Hide payment methods
        const paymentMethods = this.form.querySelector('.fluent_cart_payment_methods');
        if (paymentMethods) {
            paymentMethods.style.display = 'none';
        }
        const that = this;
        const stripe = Stripe(this.paymentArgs?.public_key);
        const elements = await stripe.elements(this.intent);

        // Configure payment element options
        const paymentElementOptions = {
            fields: {
                billingDetails: {
                    name: 'never',
                    email: 'never'
                }
            }
        };

        const paymentElement = await elements.create('payment', paymentElementOptions);
        paymentElement.mount('.fluent-cart-checkout_embed_payment_container_stripe');

        const submitButton = window.fluentcart_checkout_vars?.submit_button;

        paymentElement.on('loaderror', function (event) {

            window.dispatchEvent(new CustomEvent('fluent_cart_payment_method_loading_failed', {
                detail: {
                    payment_method: 'stripe'
                }
            }));
            // Remove loading message
            const loadingElement = document.getElementById('fct_loading_payment_processor');
            if (loadingElement) {
                loadingElement.remove();
            }

            let errorMessage = event?.error?.message;
            let hiddenError = '';

            if (window?.fluentcart_checkout_info?.is_admin !== '1') {
                errorMessage = that.$t('Payment module not available to checkout! Please reload again, or contact admin!');
                hiddenError = `<p style="color:red; display:none;" class="hidden-error">${event?.error?.message}</p>`;

                const toggleLink = document.createElement('a');
                toggleLink.className = 'toggle-error';
                toggleLink.style.cursor = 'pointer';
                toggleLink.textContent = that.$t('See Errors');
                toggleLink.addEventListener('click', function () {
                    document.querySelector('.hidden-error').style.display =
                        document.querySelector('.hidden-error').style.display === 'none' ? 'block' : 'none';
                });
                stripeContainer.prepend(toggleLink);
            }

            const errorElement = document.createElement('p');
            errorElement.style.color = 'red';
            errorElement.innerHTML = errorMessage + hiddenError;
            stripeContainer.prepend(errorElement);

            window.is_stripe_ready = false;
            that.paymentLoader.disableCheckoutButton(submitButton?.text);
        });

        paymentElement.on('ready', function (event) {
            // Remove loading message

            window.dispatchEvent(new CustomEvent('fluent_cart_payment_method_loading_success', {
                detail: {
                    payment_method: 'stripe'
                }
            }));

            const loadingElement = document.getElementById('fct_loading_payment_processor');
            if (loadingElement) {
                loadingElement.remove();
            }

            if (button && button.id === 'fluent_cart_custom_checkout_btn') {
                that.paymentLoader.disableCheckoutButton(that.$t('Pay Now'));
            } else {
                const paymentMethod = that.form.querySelector('input[name="_fct_pay_method"]:checked');
                if (paymentMethod && paymentMethod.value === 'stripe') {
                    that.paymentLoader.disableCheckoutButton(submitButton.text);
                }
            }

            window.is_stripe_ready = false;

            paymentElement.addEventListener('change', function (e) {
                window.is_stripe_ready = e.complete;

                // Remove existing error messages
                const errorMessages = document.querySelectorAll('.fct-error-message');
                errorMessages.forEach(msg => msg.remove());

                if (window.is_stripe_ready) {
                    if (button && button.id === 'fluent_cart_custom_checkout_btn') {
                        that.paymentLoader.enableCheckoutButton(that.$t('Pay Now'));
                    } else {
                        that.paymentLoader.enableCheckoutButton(submitButton.text);
                    }
                } else {
                    that.paymentLoader.disableCheckoutButton(submitButton?.text ? submitButton.text : that.$t('Place Order'));
                }
            });

            window.addEventListener("fluent_cart_payment_next_action_stripe", (e) => {
                that.paymentLoader?.changeLoaderStatus('processing');
                const loaderElement = document.querySelector('.fct-loader');
                loaderElement?.classList?.add('active');

                const remoteResponse = e.detail?.response;
                let successUrl = remoteResponse?.payment_args?.success_url;
                const customPaymentUrl = remoteResponse?.payment_args?.custom_payment_url;
                const fcCustomer = remoteResponse?.fc_customer;

                let clientSecret = null;
                let intentType = 'intent';
                if (remoteResponse?.response?.object === 'subscription') {
                    intentType = remoteResponse?.payment_args?.vendor_subscription_info?.type;
                    clientSecret = remoteResponse?.payment_args?.vendor_subscription_info?.clientSecret;
                } else {
                    clientSecret = remoteResponse?.response?.client_secret;
                }

                const displayErrorMessage = function (message) {
                    new Toastify({
                        text: message,
                        className: "warning",
                        duration: 2000,
                        escapeMarkup: false,
                        close: true
                    }).showToast();
                }

                elements.submit().then(result => {
                    const confirmIntent = intentType === "setup" ? stripe.confirmSetup : stripe.confirmPayment;
                    const accessor = intentType === "setup" ? 'setupIntent' : 'paymentIntent';

                    const confirmData = {
                        elements,
                        clientSecret,
                        confirmParams: {
                            return_url: successUrl,
                            payment_method_data: {
                                billing_details: {
                                    address: {
                                        city: fcCustomer?.city ? fcCustomer?.city : null,
                                        country: fcCustomer?.country ? fcCustomer?.country : null,
                                        postal_code: fcCustomer?.postcode ? fcCustomer?.postcode : null,
                                        state: fcCustomer?.state ? fcCustomer?.state : null,
                                        line1: fcCustomer?.address_1 ? fcCustomer?.address_1 : null,
                                    },
                                    name: fcCustomer?.name,
                                    email: fcCustomer?.email
                                }
                            }
                        },
                        redirect: 'if_required'
                    };

                    confirmIntent(confirmData).then((result) => {
                        that.paymentLoader?.changeLoaderStatus('confirming');

                        if (result?.error) {
                            console.log('inside error', result?.error?.message);
                            that.paymentLoader.enableCheckoutButton(submitButton.text);
                            displayErrorMessage(result?.error?.message);
                            that.paymentLoader?.hideLoader();
                        }

                        const intent = result[accessor];

                        if (intent?.status && (intent.status === 'succeeded' || intent.status === 'processing' || intent.status === 'requires_capture')) {
                            that.paymentLoader?.changeLoaderStatus('completed');
                            const mode = new URLSearchParams(window.location.search).get('mode') || 'order';
                            const params = new URLSearchParams({
                                action: 'fluent_cart_confirm_stripe_payment',
                                intentId: intent.id,
                                mode
                            }).toString();

                            const xhr = new XMLHttpRequest();
                            xhr.open('POST', window.fluentcart_checkout_vars.ajaxurl, true);
                            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                            xhr.onreadystatechange = function () {
                                if (xhr.readyState === 4) {
                                    if (xhr.status >= 200 && xhr.status < 300) {
                                        that.paymentLoader?.changeLoaderStatus('redirecting');
                                        let responseJSON = JSON.parse(xhr.responseText);
                                        if (responseJSON) {
                                            that.paymentLoader.triggerPaymentCompleteEvent(responseJSON);
                                            if (responseJSON.redirect_url) {
                                                successUrl = responseJSON.redirect_url;
                                            }
                                        }
                                        window.location.href = successUrl;
                                    } else {
                                        console.log(xhr.responseText, 'failed');
                                    }
                                }
                            };
                            xhr.onerror = function () {
                                console.log('Request failed');
                            };
                            xhr.send(params);
                        } else if (intent?.status === 'requires_action' || intent?.status === 'requires_source_action') {
                            // window.location.href = `${customPaymentUrl}&status=failed&method=stripe&reason=requires_action_not_performed`;
                            // require action fallback - sometime action/modal get closed after a while by browser before user complete the action.
                            // case found so far: cashapp
                            return handleRequireAction(intent);
                        }
                    });
                }).catch(error => {
                    console.log(error, 'failed');
                    loaderElement.classList.remove('active');
                    that.paymentLoader?.changeLoaderStatus('failed');
                    that.paymentLoader?.hideLoader();
                    // window.location.href = `${customPaymentUrl}&status=failed&method=stripe&reason=${error?.message}`;
                });
            });
        });

        // require action fallback - sometime action/modal get closed after a while by browser before user complete the action.
        const handleRequireAction = (intent) => {
            that.paymentLoader?.changeLoaderStatus(that.$t('redirecting for action'));
            const type = intent.next_action?.type;

            switch (type) {
                case 'redirect_to_url':
                    window.location.href = intent.next_action.redirect_to_url.url;
                    break;

                case 'cashapp_handle_redirect_or_display_qr_code':
                    if (intent.next_action.cashapp_handle_redirect_or_display_qr_code.hosted_instructions_url) {
                        window.location.href = intent.next_action.cashapp_handle_redirect_or_display_qr_code.hosted_instructions_url;
                    } else {
                        console.log('QR Code:', intent.next_action.cashapp_handle_redirect_or_display_qr_code.qr_code);
                    }
                    break;

                case 'display_boleto':
                    window.location.href = intent.next_action.boleto_display_details.hosted_voucher_url;
                    break;

                case 'oxxo_display_details':
                    window.location.href = intent.next_action.oxxo_display_details.hosted_voucher_url;
                    break;

                case 'alipay_handle_redirect':
                    window.location.href = intent.next_action.alipay_handle_redirect.url;
                    break;

                case 'konbini_display_details':
                    window.location.href = intent.next_action.konbini_display_details.hosted_voucher_url;
                    break;

                case 'display_bank_transfer_instructions':
                    window.location.href = intent.next_action.display_bank_transfer_instructions.hosted_instructions_url;
                    break;

                case 'verify_with_microdeposits':
                    window.location.href = intent.next_action.verify_with_microdeposits.hosted_verification_url;
                    break;

                case 'wechat_pay_display_qr_code':
                    if (intent.next_action.wechat_pay_display_qr_code.hosted_instructions_url) {
                        window.location.href = intent.next_action.wechat_pay_display_qr_code.hosted_instructions_url;
                    } else {
                        console.log('QR Code:', intent.next_action.wechat_pay_display_qr_code.qr_code);
                    }
                    break;

                case 'promptpay_display_qr_code':
                    if (intent.next_action.promptpay_display_qr_code.hosted_instructions_url) {
                        window.location.href = intent.next_action.promptpay_display_qr_code.hosted_instructions_url;
                    } else {
                        console.log('QR Code:', intent.next_action.promptpay_display_qr_code.qr_code);
                    }
                    break;

                default:
                    console.error('Unsupported next_action type:', type);
                    // Handle unknown types (e.g., show error message to user)
                    break;
            }
        };

        window.addEventListener("fluent_cart_validate_checkout_stripe", function (e) {
            if (!window.is_stripe_ready) {
                const errorDiv = document.createElement('div');
                errorDiv.className = 'fct-error-message';
                errorDiv.textContent = that.$t('Card details are not valid!');

                const orderSummary = document.querySelector('.fluent-cart-checkout-page-checkout-form-order-summary');
                orderSummary.appendChild(errorDiv);

                stripe.confirmPayment({
                    elements,
                    confirmParams: {
                        return_url: 'https://stripe.com',
                    },
                    redirect: 'if_required'
                });
            }
        });
    }
}

window.addEventListener("fluent_cart_load_payments_stripe", function (e) {

    window.dispatchEvent(new CustomEvent('fluent_cart_payment_method_loading', {
        detail: {
            payment_method: 'stripe'
        }
    }));

    const translate = window.fluentcart.$t;
    const stripeContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_stripe');
    removeErrorMessages();
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

        if (response?.intent?.amount <= 0 && 'subscription' !== response?.intent?.mode) {
            const translations = window.fct_stripe_data?.translations || {};

            function $t(string) {
                return translations[string] || string;
            }

            displayErrorMessage($t('Total amount is not valid, please add some items to cart!'));
            const loadingElement = document.getElementById('fct_loading_payment_processor');
            if (loadingElement) {
                loadingElement.remove();
            }
            return;
        }

        new StripeCheckout(e.detail.form, response, e.detail.paymentLoader).init();
    }).catch(error => {
        const translations = window.fct_stripe_data?.translations || {};

        function $t(string) {
            return translations[string] || string;
        }

        displayErrorMessage($t('An error occurred while parsing the response.'));
    });

    function displayErrorMessage(message) {
        if (!message) {
            return;
        }
        const errorDiv = document.createElement('div');
        errorDiv.className = 'fct-error-message';
        errorDiv.textContent = message;
        stripeContainer.appendChild(errorDiv);

        const loadingElement = document.getElementById('fct_loading_payment_processor');
        if (loadingElement) {
            loadingElement.remove();
        }
        return;
    }

    function addLoadingText() {
        const loadingMessage = document.createElement('p');
        loadingMessage.id = 'fct_loading_payment_processor';
        const translations = window.fct_stripe_data?.translations || {};

        function $t(string) {
            return translations[string] || string;
        }

        loadingMessage.textContent = $t('Loading Payment Processor...');
        stripeContainer.appendChild(loadingMessage);
    }

    function removeErrorMessages() {
        const errorMessages = document.querySelectorAll('.fct-error-message');
        errorMessages.forEach(msg => msg.remove());
    }
});
