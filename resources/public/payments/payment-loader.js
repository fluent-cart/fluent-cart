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
        this.paymenMethodsWithCustomCheckoutButtons = window.fluentcart_checkout_vars?.payment_methods_with_custom_checkout_buttons;

        this.#submitButton = window.fluentcart_checkout_vars?.submit_button;

        const checkedInput = this.#form.querySelector('input[name="_fct_pay_method"]:checked');

        if (checkedInput) {
            this.payMethod = checkedInput.value;
        } else {
            const firstPaymentMethod = this.#form.querySelector('input[name="_fct_pay_method"]');
            if (firstPaymentMethod) {
                this.payMethod = firstPaymentMethod.value;
            } else {
                this.payMethod = 'offline_payment';
                window['is_offline_payment_ready'] = true;
                this.#checkoutUiService.showCheckoutButton();
                this.#checkoutUiService.enableCheckoutButton();
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

                document.querySelector('.fluent-cart-checkout_embed_payment_container_' + paymentMethod).parentNode.classList.add('fct-payment-loading');

                if(document.querySelector('.fct_place_order_btn_wrap')) {
                    document.querySelector('.fct_place_order_btn_wrap').classList.add('fct-payment-loading');
                }
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

                document.querySelector('.fluent-cart-checkout_embed_payment_container_' + paymentMethod).parentNode.classList.remove('fct-payment-loading');

                if(document.querySelector('.fct_place_order_btn_wrap')) {
                    document.querySelector('.fct_place_order_btn_wrap').classList.remove('fct-payment-loading');
                }
            }
        });


    }


    reinitializePaymentMethods() {
        const currentPayMethod = this.payMethod;

        const paymentMethodsContainer = this.#form.querySelector('#fluent_payment_methods');

        const hasPaymentMethods = !!paymentMethodsContainer;

        if (!hasPaymentMethods) {
            this.payMethod = 'offline_payment';
            window['is_offline_payment_ready'] = true;
            this.#checkoutUiService.showCheckoutButton();
            this.#checkoutUiService.enableCheckoutButton();
        } else {
            this.payMethod = this.#form.querySelector('input[name="_fct_pay_method"]:checked')?.value || currentPayMethod;
        }

        this.bindPaymentMethodListeners();

        const checkedInput = this.#form.querySelector('input[name="_fct_pay_method"]:checked');
        if (checkedInput) {
            this.#form.querySelectorAll('input[name="_fct_pay_method"]').forEach(el => {
                el.parentNode.classList.remove('active');
            });

            checkedInput.parentNode.classList.add('active');

            const wrapper = checkedInput.parentNode;

            const embed = document.querySelector('.fluent-cart-checkout_embed_payment_container_' + this.payMethod);
            if (embed) {
                embed.parentNode.classList.add('active');

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

                const allInstructions = document.querySelectorAll('.fct_payment_method_instructions');
                allInstructions.forEach(node => {
                    node.style.display = 'none';
                });

                const embed = document.querySelector('.fluent-cart-checkout_embed_payment_container_' + this.payMethod);

                // remove active from all embed parents
                document.querySelectorAll('.fluent-cart-checkout_embed_payment_container').forEach(box => box.parentNode.classList.remove('active'));

                if (embed) {
                    // add active to current embed parent
                    embed.parentNode.classList.add('active');

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
        }
    }

    decodeHtml(html) {
        const txt = document.createElement("textarea");
        txt.innerHTML = html;
        return txt.value;
    };

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
            // Use the already-determined payment method (from checked radio) before falling back to first
            const resolvedMethod = this.payMethod || null;

            const targetInput = resolvedMethod
                ? this.#form.querySelector('input[name="_fct_pay_method"][value="' + resolvedMethod + '"]')
                : this.#form.querySelector('input[name="_fct_pay_method"]');

            if (targetInput) {
                paymentMethod = targetInput.value;

                targetInput.checked = true;

                this.#form.querySelectorAll('input[name="_fct_pay_method"]').forEach(el => {
                    el.parentNode.classList.remove('active');
                });

                targetInput.parentNode.classList.add('active');

                const embed = document.querySelector('.fluent-cart-checkout_embed_payment_container_' + targetInput.value);
                if (embed) {
                    embed.parentNode.classList.add('active');
                }

                const wrapper = targetInput.parentNode;
                const instructions = wrapper.querySelector('.fct_payment_method_instructions');
                if (instructions) {
                    document.querySelectorAll('.fct_payment_method_instructions').forEach(i => {
                        i.style.display = 'none';
                    });
                    instructions.style.display = 'block';
                }
            } else {
                return;
            }
        }

        this.payMethod = paymentMethod;

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
            loader.classList.add('fct-loader-hidden');
        }
    }

    getPaymentMethod() {
        return this.payMethod;
    }
}
