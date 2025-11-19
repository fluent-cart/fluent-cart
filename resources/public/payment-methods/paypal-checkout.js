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

        let paypalButtonContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_paypal');
        paypalButtonContainer.innerHTML = '';
        // Hide payment methods
        const paymentMethods = this.form.querySelector('.fluent_cart_payment_methods');
        if (paymentMethods) {
            paymentMethods.style.display = 'none';
        }

        let orderData = null;

        if ('payment' === intentMode) {
            this.onetimePaymentHandler(ref, paymentData, orderData, paypalButtonContainer);
        } else if ('subscription' === intentMode) {
            this.subscriptionPaymentHandler(ref, paymentData, orderData, paypalButtonContainer);
        }
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
            createOrder: (data, actions) => {
                that.paymentLoader?.changeLoaderStatus('processing');
                return actions.order.create({
                    purchase_units: [orderData.response],
                    application_context: {
                        shipping_preference: 'NO_SHIPPING'
                    },
                    intent: 'CAPTURE',
                    reference_id: orderData?.data?.order?.uuid ? orderData?.data?.order?.uuid : that.$t('uuid not found')
                });
            },
            onApprove: (data, actions) => {
                that.paymentLoader?.changeLoaderStatus('confirming');
                return actions.order.capture().then((details) => {
                    if (details.id) {
                        that.paymentLoader?.changeLoaderStatus('completed');
                        const params = new URLSearchParams({
                            action: 'fluent_cart_confirm_paypal_payment',
                            payId: details.id,
                            ref_id: orderData?.data?.transaction.uuid ? orderData?.data?.transaction.uuid : that.$t('uuid not found'),
                            mode: mode
                        });
                        const xhr = new XMLHttpRequest();
                        xhr.open('POST', window.fluentcart_checkout_vars.ajaxurl, true);
                        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

                        xhr.onload = function () {
                            if (xhr.status >= 200 && xhr.status < 300) {
                                try {
                                    const res = JSON.parse(xhr.responseText);
                                    if(res?.redirect_url) {
                                        that.paymentLoader.triggerPaymentCompleteEvent(res);
                                        that.paymentLoader?.changeLoaderStatus('redirecting');
                                        window.location.href = res.redirect_url;
                                    }
                                } catch (error) {
                                    console.error('An error occurred while parsing the response:', error?.message);
                                }
                            } else {
                                console.error('Network response was not ok');
                            }
                        };

                        xhr.onerror = function () {
                            try {
                                const err = JSON.parse(xhr.responseText);
                            } catch (e) {
                                console.error('An error occurred:', e);
                            }
                        };

                        xhr.send(params.toString());
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

        buttons.render(paypalButtonContainer).then(() => {
            window.dispatchEvent(new CustomEvent('fluent_cart_payment_method_loading_success', {
                detail: {
                    payment_method: 'paypal'
                }
            }));
            const loadingElement = document.getElementById('fct_loading_payment_processor');
            if (loadingElement) {
                loadingElement.remove();
            }
        });

        // Add extra text
        const extraText = document.createElement('p');
        extraText.id = 'fct-extra-checkout-text-for-paypal';
        extraText.style.textAlign = 'center';
        extraText.style.marginTop = '10px';
        extraText.style.fontSize = '14px';
        extraText.style.color = '#666';
        extraText.textContent = that.$t('Choose any option to continue');
        paypalButtonContainer.appendChild(extraText);
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
                    'custom_id': orderData.data.subscription.uuid
                });
            },
            onApprove: function (data, actions) {
                that.paymentLoader?.changeLoaderStatus('confirming');
                if (data.subscriptionID) {
                    console.log(orderData, data);
                    that.paymentLoader?.changeLoaderStatus('completed');
                    const params = new URLSearchParams({
                        action: 'fluent_cart_confirm_paypal_subscription',
                        order_id: data.orderID,
                        subscription_id: data.subscriptionID,
                        ref_id: orderData?.data?.transaction.uuid,
                        mode: mode
                    });

                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', window.fluentcart_checkout_vars.ajaxurl, true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

                    xhr.onload = function () {
                        if (xhr.status >= 200 && xhr.status < 300) {
                            try {
                                const res = JSON.parse(xhr.responseText);
                                if (res?.redirect_url) {
                                    that.paymentLoader.triggerPaymentCompleteEvent(res);
                                    that.paymentLoader?.changeLoaderStatus('redirecting');
                                    window.location.href = res.redirect_url;
                                }
                            } catch (error) {
                                console.error('An error occurred while parsing the response:', error);
                            }
                        } else {
                            console.error('Network response was not ok');
                        }
                    };
                    xhr.send(params.toString());
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

        buttons.render(paypalButtonContainer).then(() => {
            window.dispatchEvent(new CustomEvent('fluent_cart_payment_method_loading_success', {
                detail: {
                    payment_method: 'paypal'
                }
            }));
            const loadingElement = document.getElementById('fct_loading_payment_processor');
            if (loadingElement) {
                loadingElement.remove();
            }
        });

        // in future settings will be from store settings specific
        const extraText = document.createElement('p');
        extraText.id = 'fct-extra-checkout-text-for-paypal';
        extraText.style.textAlign = 'center';
        extraText.style.marginTop = '10px';
        extraText.style.fontSize = '14px';
        extraText.style.color = '#666';
        extraText.textContent = that.$t('Choose any option to continue');
        paypalButtonContainer.appendChild(extraText);
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
