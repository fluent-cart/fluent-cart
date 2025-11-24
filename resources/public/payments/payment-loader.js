import CheckoutHelper from "../checkout/CheckoutHelper";

export default class PaymentLoader {
    #form;
    payMethod;
    #basePaymentInfoUrl = `${window.fluentcart_checkout_vars.ajaxurl}?action=fluent_cart_checkout_routes&fc_checkout_action=get_order_info`
    #orderHandler;
    checkoutHandler;
    customPayment;
    #submitButton;
    #checkoutTotal = null;
    #isZeroPayment = false;
    #hasSubscriptions = false;
    #checkoutUiService = null;
    #translate = window.fluentcart.$t;

    constructor({form, orderHandler, checkoutHandler = null, customPayment = false}) {
        this.#orderHandler = orderHandler;
        this.#form = form;
        this.payMethod = this.#form.querySelector('input[name="_fct_pay_method"]:checked')?.value;
        this.checkoutHandler = checkoutHandler;
        this.#checkoutUiService = checkoutHandler.checkoutUiService;
        this.customPayment = customPayment;
        this.#isZeroPayment = window.fluentcart_checkout_info?.is_zero_payment === 'yes';
        this.#hasSubscriptions = window.fluentcart_checkout_info?.has_subscription === 'yes';
        this.buttons = this.#form.querySelector('[data-fluent-cart-checkout-page-checkout-button]');
        this.paymenMethodsWithCustomCheckoutButtons = Array.isArray(window.fluentcart_checkout_vars?.payment_methods_with_custom_checkout_buttons)
            ? window.fluentcart_checkout_vars.payment_methods_with_custom_checkout_buttons
            : [];

        this.#submitButton = window.fluentcart_checkout_vars?.submit_button;

        const checkedInput = this.#form.querySelector('input[name="_fct_pay_method"]:checked');

        if (checkedInput) {
            this.payMethod = checkedInput.value;
        } else {
            const firstPaymentMethod = this.#form.querySelector('input[name="_fct_pay_method"]');
            if (firstPaymentMethod) {
                this.payMethod = firstPaymentMethod.value;
            }
        }


        if (this.paymenMethodsWithCustomCheckoutButtons.includes(this.payMethod)) {
            this.#checkoutUiService.hideCheckoutButton();
            this.#checkoutUiService.enableCheckoutButton();
        }

        this.bindPaymentMethodListeners();

        window.addEventListener('fluentCartCheckoutDataChanged', (event) => {
            this.checkTotal(event.detail?.response);
        });

        window.addEventListener('fluentCartFragmentsReplaced', (event) => {
            if (event.detail?.selectors?.includes("[data-fluent-cart-checkout-payment-methods]")) {
                this.reinitializePaymentMethods();
            }
        });

        window.addEventListener('fluent_cart_payment_method_loading', (event) => {
            const paymentMethod = event.detail.payment_method;
            if (paymentMethod) {
                document.querySelectorAll('.fct_payment_method_wrapper').forEach(el => {
                    el.classList.remove('fct-payment-loading');
                    el.classList.remove('fct-payment-loading-failed');
                });
                document.querySelectorAll('.fct_payment_method_' + paymentMethod).forEach(el => {
                    el.classList.add('fct-payment-loading');
                });
            }
        });


        window.addEventListener('fluent_cart_payment_method_loading_failed', (event) => {
            const paymentMethod = event.detail.payment_method;
            if (paymentMethod) {
                document.querySelectorAll('.fct_payment_method_' + paymentMethod).forEach(el => {
                    el.classList.remove('fct-payment-loading');
                    el.classList.add('fct-payment-loading-failed');
                });
            }
        });

        window.addEventListener('fluent_cart_payment_method_loading_success', (event) => {
            const paymentMethod = event.detail.payment_method;
            if (paymentMethod) {
                document.querySelectorAll('.fct_payment_method_' + paymentMethod).forEach(el => {
                    el.classList.remove('fct-payment-loading');
                    el.classList.remove('fct-payment-loading-failed');
                });
            }
        });


    }


    reinitializePaymentMethods() {
        const currentPayMethod = this.payMethod;

        this.payMethod = this.#form.querySelector('input[name="_fct_pay_method"]:checked')?.value || currentPayMethod;

        this.bindPaymentMethodListeners();

        const checkedInput = this.#form.querySelector('input[name="_fct_pay_method"]:checked');
        if (checkedInput) {
            this.#form.querySelectorAll('input[name="_fct_pay_method"]').forEach(el => {
                el.parentNode.classList.remove('active');
            });

            checkedInput.parentNode.classList.add('active');

            const wrapper = checkedInput.parentNode;
            const embed = wrapper.querySelector('.fluent-cart-checkout_embed_payment_container');
            if (embed) {
                // const allEmbeds = document.querySelectorAll('.fluent-cart-checkout_embed_payment_container');
                // allEmbeds.forEach(e => e.style.display = 'none');

                // embed.style.display = 'block';

                const allInstructions = document.querySelectorAll('.fct_payment_method_instructions');
                allInstructions.forEach(i => i.style.display = 'none');

                const instructions = wrapper.querySelector('.fct_payment_method_instructions');
                if (instructions) {
                    instructions.style.display = 'block';
                }

                this.load(this.payMethod);
            }
        }

        // Update button state
        if (this.paymenMethodsWithCustomCheckoutButtons.includes(this.payMethod)) {
            this.#checkoutUiService.hideCheckoutButton();
            this.#checkoutUiService.enableCheckoutButton();
        } else {
            this.#checkoutUiService.showCheckoutButton();
            this.#checkoutUiService.enableCheckoutButton();
        }
    }

    bindPaymentMethodListeners() {
        const paymentMethodInputs = this.#form.querySelectorAll('input[name="_fct_pay_method"]');
        paymentMethodInputs.forEach(input => {
            input.addEventListener('change', () => {
                this.payMethod = this.#form.querySelector('input[name="_fct_pay_method"]:checked')?.value;

                paymentMethodInputs.forEach(el => el.parentNode.classList.remove('active'));
                input.parentNode.classList.add('active');

                if (this.paymenMethodsWithCustomCheckoutButtons.includes(this.payMethod)) {
                    this.#checkoutUiService.hideCheckoutButton();
                    this.#checkoutUiService.enableCheckoutButton();
                } else {
                    this.#checkoutUiService.showCheckoutButton();
                    this.#checkoutUiService.enableCheckoutButton();
                }

                // const embeds = document.querySelectorAll('.fluent-cart-checkout_embed_payment_container');
                // embeds.forEach(embed => {
                //     embed.style.display = 'none';
                // });
                const allInstructions = document.querySelectorAll('.fct_payment_method_instructions');
                allInstructions.forEach(node => {
                    node.style.display = 'none';
                });

                const embed = input.parentNode.querySelector('.fluent-cart-checkout_embed_payment_container');
                if (embed) {
                    //embed.style.display = 'block';
                    const instructions = input.parentNode.querySelector('.fct_payment_method_instructions');
                    if (instructions) {
                        instructions.style.display = 'block';
                    }
                    this.load(this.payMethod);
                }
            });
        });
    }

    checkTotal(checkoutSummary) {
        if (checkoutSummary?.total !== this.#checkoutTotal) {
            if (checkoutSummary?.total > 0) {
                this.#isZeroPayment = false;
            } else if (checkoutSummary?.total <= 0 && !this.#hasSubscriptions) {
                this.#isZeroPayment = true;
            }
            this.#checkoutTotal = checkoutSummary?.total;
            this.togglePaymentMethods(checkoutSummary);
        }
    }

    decodeHtml(html) {
        const txt = document.createElement("textarea");
        txt.innerHTML = html;
        return txt.value;
    };

    togglePaymentMethods(checkoutSummary, forceZeroPayment = false) {
        let total = 0;

        if (forceZeroPayment) {
            total = 0;
            this.#hasSubscriptions = false;
        } else {
            total = checkoutSummary?.total;
            this.#hasSubscriptions = checkoutSummary?.has_subscriptions ? true : false;
        }

        if (!this.#hasSubscriptions && total <= 0) {
            // check if any payment method is available, if not then return
            const paymentMethods = this.#form.querySelectorAll('input[name="_fct_pay_method"]');
            if (paymentMethods.length === 0) {
                return;
            }
            const paymentMethodsWrapper = this.#form.querySelector('[data-fluent-cart-checkout-payment-methods]');
            paymentMethodsWrapper.style.display = 'none';

            this.payMethod = 'offline_payment';
            this.#form.querySelector('input[name="_fct_pay_method"]').checked = false;

            this.#form.querySelectorAll('input[name="_fct_pay_method"]').forEach(input => {
                input.checked = false;
                input.removeAttribute('checked');
            });

            window['is_offline_payment_ready'] = true;
            this.#checkoutUiService.showCheckoutButton();
            this.#checkoutUiService.enableCheckoutButton();
        } else {
            const paymentMethodsWrapper = this.#form.querySelector('[data-fluent-cart-checkout-payment-methods]');
            paymentMethodsWrapper.style.display = 'block';

            let selectedPaymentMethod = this.#form.querySelector('input[name="_fct_pay_method"]:checked')?.value;
            this.payMethod = selectedPaymentMethod;
            if (!selectedPaymentMethod) {
                // Always select and load the first payment method when no specific method is selected
                const firstPaymentMethod = this.#form.querySelector('input[name="_fct_pay_method"]');
                if (firstPaymentMethod) {
                    firstPaymentMethod.checked = true;
                    this.payMethod = firstPaymentMethod.value;
                    this.load();
                }
            } else {
                this.load(selectedPaymentMethod);
            }
        }
    }

    disableCheckoutButton(message) {
        this.#form.classList.add('fluent-cart-checkout-order-processing');
        this.#checkoutUiService.disableCheckoutButton();
        this.#checkoutUiService.setCheckoutButtonText(message);
    }

    enableCheckoutButton(message) {
        this.#form.classList.add('fluent-cart-checkout-order-processing');
        this.#checkoutUiService.enableCheckoutButton();
        this.#checkoutUiService.setCheckoutButtonText(message);
    }


    showCheckoutButton() {
        this.buttons.style.display = 'block';
        this.buttons.disabled = false;
    }

    triggerPaymentCompleteEvent(response) {
        // window.dispatchEvent(new CustomEvent('fluent_cart_payments_completed', {
        //     detail: response
        // }));

        // call ajax
        const params = new URLSearchParams({
            action: 'fluent_cart_run_order_actions',
            order_hash: response?.order?.uuid,
        });

        const xhr = new XMLHttpRequest();
        xhr.open('POST', window.fluentcart_checkout_vars.ajaxurl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

        xhr.onload = function () {
            if (xhr.status >= 200 && xhr.status < 300) {
                // do your stuff
            } else {
                console.error('Network response was not ok');
            }
        };

        xhr.send(params.toString());
    }

    load(paymentMethod) {
        if (paymentMethod === undefined || paymentMethod === null) {
            const firstPaymentMethodInput = this.#form.querySelector('input[name="_fct_pay_method"]');
            if (firstPaymentMethodInput) {
                paymentMethod = firstPaymentMethodInput.value;

                firstPaymentMethodInput.checked = true;

                this.#form.querySelectorAll('input[name="_fct_pay_method"]').forEach(el => {
                    el.parentNode.classList.remove('active');
                });

                firstPaymentMethodInput.parentNode.classList.add('active');


                const initialWrapper = firstPaymentMethodInput.parentNode;
                const initialEmbed = initialWrapper.querySelector('.fluent-cart-checkout_embed_payment_container');
                // if (initialEmbed) {
                //     document.querySelectorAll('.fluent-cart-checkout_embed_payment_container').forEach(embed => {
                //         embed.style.display = 'none';
                //     });
                //     initialEmbed.style.display = 'block';
                // }
                const initialInstructions = initialWrapper.querySelector('.fct_payment_method_instructions');
                if (initialInstructions) {
                    document.querySelectorAll('.fct_payment_method_instructions').forEach(instruction => {
                        instruction.style.display = 'none';
                    });
                    initialInstructions.style.display = 'block';
                }

                //console.log('FluentCart: Loading first payment method in order:', paymentMethod);
            } else {
                //console.warn('FluentCart: No payment methods found');
                return;
            }
        }

        this.payMethod = paymentMethod;
        if (this.#isZeroPayment && !this.#hasSubscriptions) {
            return this.togglePaymentMethods({}, true);
        }

        window['is_' + paymentMethod + '_ready'] = true;

        let searchParams = new URLSearchParams(window.location.search);

        const url = this.#basePaymentInfoUrl;

        let params = {
            'method': paymentMethod
        };

        if (window.fluentcart_checkout_info?.paymentMode) {
            params['mode'] = window.fluentcart_checkout_info.paymentMode;
        }
        if (window.fluentcart_checkout_info?.orderHash) {
            params['order_hash'] = window.fluentcart_checkout_info.orderHash;
        }

        if (searchParams.has('mode') && searchParams.get('variation_id')) {
            params['mode'] = searchParams.get('mode');
            params['variation_id'] = searchParams.get('variation_id');
        }

        const paymentInfoUrl = CheckoutHelper.buildUrl(url.toString(), params).toString();

        window.dispatchEvent(new CustomEvent('fluent_cart_load_payments_' + paymentMethod, {
            detail: {
                form: this.#form,
                paymentInfoUrl: paymentInfoUrl,
                nonce: window.fluentcart_checkout_info.checkout_nonce,
                orderHandler: this.#orderHandler,
                paymentLoader: this,
                isZeroPayment: this.#isZeroPayment,
                hasSubscriptions: this.#hasSubscriptions
            }
        }));
    }

    changeLoaderStatus(status) {
        const loaderStatus = document.querySelector('.fct-order-processing .loading-status');
        if (!loaderStatus) {
            return;
        }

        const statusMessages = {
            'processing': this.#translate('Payment Processing...'),
            'confirming': this.#translate('Confirming Payment...'),
            'completed': this.#translate('Payment completed! Updating order...'),
            'redirecting': this.#translate('Redirecting to receipt...'),
            'failed': this.#translate('Payment failed!'),
            'canceled': this.#translate('Payment canceled!'),
            'rejected': this.#translate('Payment Process rejected!'),
        };

        loaderStatus.textContent = statusMessages[status] || status;
    }

    hideLoader() {
        const loader = document.querySelector('.fct-order-processing');
        if (loader) {
            loader.classList.add('hidden');
        }
    }

    getPaymentMethod() {
        return this.payMethod;
    }
}
