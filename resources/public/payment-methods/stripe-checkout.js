// Checkout re-inits StripeCheckout on every payment-method switch AND every
// fragment replacement (coupon apply, shipping change). Window listeners
// registered per init would stack: each stale copy re-runs confirmPayment
// with an unmounted Elements instance — duplicate confirm AJAX plus a false
// "payment failed" toast on an otherwise successful payment. One delegating
// listener + a reassignable handler keeps only the latest instance live.
let stripeNextActionHandler = null;
let stripeValidateCheckoutHandler = null;
let stripeSaveCardConsentHandler = null;
// Confirm re-inits (payment-method switch / fragments) must not leave a
// MutationObserver from a previous confirm live — it would hide loaders on
// an unrelated iframe (PayPal, next Stripe mount) the same way stacked
// next-action listeners used to double-confirm.
let stripeChallengeWatch = null;

window.addEventListener('fluent_cart_payment_next_action_stripe', (e) => {
    if (typeof stripeNextActionHandler === 'function') {
        stripeNextActionHandler(e);
    }
});

window.addEventListener('fluent_cart_validate_checkout_stripe', (e) => {
    if (typeof stripeValidateCheckoutHandler === 'function') {
        stripeValidateCheckoutHandler(e);
    }
});

// Same reasoning for the save-card checkbox, which lives outside the Stripe
// container and so survives every re-init. Delegated from the document because
// the checkbox is rendered by an add-on and may not exist when this module runs.
document.addEventListener('change', (e) => {
    const box = e.target;
    if (!box || box.name !== 'save_payment_method'
        || typeof stripeSaveCardConsentHandler !== 'function') {
        return;
    }

    // Unlike the two events above, this one is not gateway-scoped: the checkbox
    // outlives payment-method switches and only StripeCheckout.init() replaces the
    // handler, so a change can arrive while another gateway is selected — the
    // add-on unticks the box itself on switch, which fires exactly that. Updating
    // the last, now unmounted, Elements instance there achieves nothing, and its
    // failure path would load Stripe over the gateway the buyer actually chose.
    const method = (box.form || document).querySelector('input[name="_fct_pay_method"]:checked');
    if (!method || method.value !== 'stripe') {
        return;
    }

    stripeSaveCardConsentHandler(box);
});

function stopStripeChallengeWatch() {
    if (!stripeChallengeWatch) {
        return;
    }
    stripeChallengeWatch.disconnect();
    stripeChallengeWatch = null;
}

function nodeLooksLikeStripeChallenge(node) {
    if (!node || node.nodeType !== 1) {
        return false;
    }

    const paymentRoot = document.querySelector('.fluent-cart-checkout_embed_payment_container_stripe');
    if (paymentRoot && paymentRoot.contains(node)) {
        return false;
    }

    if (node.closest?.('.fct-order-processing, .fct-loader')) {
        return false;
    }

    if (node.tagName === 'IFRAME') {
        return true;
    }

    return Boolean(node.querySelector?.('iframe'));
}

function hideLoadersForStripeChallenge(paymentLoader, loaderElement) {
    paymentLoader?.hideLoader();
    loaderElement?.classList?.remove('active');
}

function watchStripeChallengeAndHideLoaders(paymentLoader, loaderElement) {
    stopStripeChallengeWatch();

    if (!document.body) {
        return;
    }

    stripeChallengeWatch = new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            for (const node of mutation.addedNodes) {
                if (nodeLooksLikeStripeChallenge(node)) {
                    hideLoadersForStripeChallenge(paymentLoader, loaderElement);
                    stopStripeChallengeWatch();
                    return;
                }
            }
        }
    });

    stripeChallengeWatch.observe(document.body, {
        childList: true,
        subtree: true,
    });
}

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

    /**
     * Keep the Elements config in step with the "save my card" checkbox.
     *
     * Elements runs in deferred-intent mode, so Stripe rejects the confirmation
     * unless the PaymentIntent created at place-order carries the SAME
     * setup_future_usage the Elements instance was configured with. The server
     * resolves its half from the checkbox posted with the order, so this half has
     * to track the same checkbox — otherwise ticking the box after the element
     * mounted is a guaranteed "setup_future_usage does not match" failure.
     *
     * elements.update() is used rather than re-fetching the payment config,
     * because the checkbox renders BELOW the card element: a re-mount would
     * discard whatever the buyer had already typed.
     *
     * The checkbox is rendered by an add-on (saved payment methods). When no
     * add-on renders it, there is nothing to bind and the config stays as the
     * server sent it.
     */
    bindSaveCardConsent(elements) {
        // Drop the previous instance's handler first: only the live Elements
        // instance may be updated, and a checkout that re-inits into a mode this
        // does not manage (subscription) must leave no stale handler behind.
        stripeSaveCardConsentHandler = null;

        const saveBox = this.form.querySelector('input[name="save_payment_method"]');
        if (!saveBox || !elements || typeof elements.update !== 'function') {
            return;
        }

        // A subscription checkout resolves its own vaulting mode server-side and
        // must not be driven from here. This mirrors the server half exactly: it
        // returns the value core chose whenever the cart has a subscription, and
        // only consults consent for a plain one-time checkout. A store-managed
        // subscription charged as a one-time payment still reports mode
        // 'payment', so the mode check alone would miss it.
        const hasSubscription = window.fluentcart_checkout_info?.has_subscription === 'yes';
        if (hasSubscription || this.intent?.mode === 'subscription' || this.intent?.mode === 'setup') {
            return;
        }

        stripeSaveCardConsentHandler = (box) => {
            // Map the checkbox straight to a value — never fall back to the value
            // the config was fetched with. On a one-time checkout that value is
            // itself consent-derived (the config request carries the checkbox), so
            // restoring it would leave Elements vaulting after the buyer unticks,
            // while the posted order asks the server not to vault. That mismatch
            // is exactly the Stripe confirmation failure this is here to prevent.
            //
            // off_session (not on_session): a saved card is charged later without
            // the buyer present — one-tap checkout and renewals. null clears it.
            const next = box.checked ? 'off_session' : null;

            try {
                elements.update({setupFutureUsage: next});
            } catch (e) {
                // If this Stripe.js build will not accept the update, fall back to
                // a full re-fetch so the two halves still agree. The buyer loses
                // typed card details, which is worse UX but never a failed payment.
                this.paymentLoader?.load('stripe');
            }
        };

        // The config request reads the checkbox, but the fetch, elements() and
        // mount() that follow are all async — a toggle inside that window fires
        // before any handler is installed and is otherwise lost, leaving the
        // element on the old config while the order posts the new consent.
        // Sync once, and only when the two actually differ: a redundant update()
        // that threw would hit the catch above and re-enter load(), which lands
        // back here, and the guard is what stops that looping.
        const mounted = this.intent?.setup_future_usage || null;
        if ((saveBox.checked ? 'off_session' : null) !== mounted) {
            stripeSaveCardConsentHandler(saveBox);
        }
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
        
        // Use appearance configuration from the response if available
        const elementsOptions = { ...this.intent };
        if (this.data.appearance) {
            elementsOptions.appearance = this.data.appearance;
        }
        elementsOptions.locale = window.fct_stripe_data?.locale || 'auto';

        const elements = await stripe.elements(elementsOptions);

        // Configure payment element options
        const paymentElementOptions = {
            fields: {
                billingDetails: {
                    name: 'never',
                    email: 'never'
                }
            },
            terms: {
                card: 'never',
                wallet: 'never',
                apple_pay: 'never',
                google_pay: 'never',
                amazon_pay: 'never',
                paypal: 'never',
            },
        };

        const paymentElement = await elements.create('payment', paymentElementOptions);
        paymentElement.mount('.fluent-cart-checkout_embed_payment_container_stripe');

        this.bindSaveCardConsent(elements);

        // System (auto-charged) subscription checkout: card-network rules require an
        // explicit save-and-auto-charge disclosure next to the payment element. The
        // checkout can re-initialize on the same page (cart/payment state changes),
        // so a stale notice from a previous system render must be removed when the
        // current response carries no consent.
        //
        // Zero-payable setup flow (free trial, consent_required): the disclosure
        // becomes a REQUIRED checkbox — without a saved card the trial can never
        // bill. The checkbox is a named form field so the placed order carries the
        // consent flag; the server rejects the setup flow without it.
        this.consentCheckbox = null;
        if (stripeContainer) {
            const staleConsentEl = stripeContainer.parentNode.querySelector('.fct-system-charge-consent');
            if (staleConsentEl) {
                staleConsentEl.remove();
            }

            if (this.data.system_consent) {
                let consentEl;
                if (this.data.consent_required) {
                    consentEl = document.createElement('label');
                    consentEl.className = 'fct-system-charge-consent';
                    consentEl.style.cssText = 'display:flex;gap:6px;align-items:flex-start;margin-top:8px;font-size:12px;opacity:0.85;cursor:pointer;';

                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.name = '_fct_system_consent';
                    checkbox.value = 'yes';
                    checkbox.style.cssText = 'margin-top:2px;flex-shrink:0;';
                    consentEl.appendChild(checkbox);

                    const textSpan = document.createElement('span');
                    textSpan.textContent = this.data.system_consent;
                    consentEl.appendChild(textSpan);

                    this.consentCheckbox = checkbox;
                } else {
                    consentEl = document.createElement('p');
                    consentEl.className = 'fct-system-charge-consent';
                    consentEl.style.cssText = 'margin-top:8px;font-size:12px;opacity:0.75;';
                    consentEl.textContent = this.data.system_consent;
                }
                stripeContainer.insertAdjacentElement('afterend', consentEl);
            }
        }

        // Required consent gates the submit button together with element readiness.
        const consentOk = () => !this.data.consent_required || (this.consentCheckbox && this.consentCheckbox.checked);
        this.consentOk = consentOk;

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
            window.is_stripe_ready = true;

            window.dispatchEvent(new CustomEvent('fluent_cart_payment_method_loading_success', {
                detail: {
                    payment_method: 'stripe'
                }
            }));

            requestAnimationFrame(() => {
                const stripeElement = document.querySelector(
                    '.__PrivateStripeElement'
                );

                if (stripeElement) {
                    stripeElement.style.setProperty('display', 'flex', 'important');
                    stripeElement.style.setProperty('justify-content', 'center', 'important');
                    stripeElement.style.setProperty(
                        'padding',
                        '8px 0 0 0',
                        'important'
                    );
                }
            });

            const loadingElement = document.getElementById('fct_loading_payment_processor');
            if (loadingElement) {
                loadingElement.remove();
            }

            if (button && button.id === 'fluent_cart_custom_checkout_btn') {
                that.paymentLoader.disableCheckoutButton(that.$t('Pay Now'));
            } else {
                const paymentMethod = that.form.querySelector('input[name="_fct_pay_method"]:checked');
                if (paymentMethod && paymentMethod.value === 'stripe' && that.consentOk()) {
                    that.paymentLoader.enableCheckoutButton(submitButton.text);
                } else if (paymentMethod && paymentMethod.value === 'stripe') {
                    that.paymentLoader.disableCheckoutButton(submitButton?.text ? submitButton.text : that.$t('Place Order'));
                }
            }

            const refreshSubmitState = function () {
                const checkedMethod = that.form.querySelector('input[name="_fct_pay_method"]:checked');
                if (checkedMethod && checkedMethod.value !== 'stripe') {
                    return;
                }

                if (window.is_stripe_ready && that.consentOk()) {
                    if (button && button.id === 'fluent_cart_custom_checkout_btn') {
                        that.paymentLoader.enableCheckoutButton(that.$t('Pay Now'));
                    } else {
                        that.paymentLoader.enableCheckoutButton(submitButton.text);
                    }
                } else {
                    that.paymentLoader.disableCheckoutButton(submitButton?.text ? submitButton.text : that.$t('Place Order'));
                }
            };

            if (that.consentCheckbox) {
                that.consentCheckbox.addEventListener('change', refreshSubmitState);
            }

            paymentElement.addEventListener('change', function (e) {
                window.is_stripe_ready = e.complete;

                // Remove existing error messages
                const errorMessages = document.querySelectorAll('.fct-error-message');
                errorMessages.forEach(msg => msg.remove());

                refreshSubmitState();
            });

            stripeNextActionHandler = (e) => {
                stopStripeChallengeWatch();
                that.paymentLoader?.changeLoaderStatus('processing');
                const loaderElement = document.querySelector('.fct-loader');
                loaderElement?.classList?.add('active');

                const remoteResponse = e.detail?.response;
                let successUrl = remoteResponse?.payment_args?.success_url;
                // Issuer-forced 3DS redirects land on an internal route that confirms
                // before sending the buyer on, so confirmation never depends on where
                // the (filterable) success URL points.
                const gatewayReturnUrl = remoteResponse?.payment_args?.gateway_return_url || successUrl;
                const customPaymentUrl = remoteResponse?.payment_args?.custom_payment_url;
                const fcCustomer = remoteResponse?.fc_customer;

                let clientSecret = null;
                let intentType = 'intent';
                if (remoteResponse?.response?.object === 'subscription') {
                    intentType = remoteResponse?.payment_args?.vendor_subscription_info?.type;
                    clientSecret = remoteResponse?.payment_args?.vendor_subscription_info?.clientSecret;
                } else if (remoteResponse?.response?.object === 'setup_intent') {
                    // Zero-payable system (free-trial) checkout: the card is vaulted
                    // via a SetupIntent — nothing is charged today.
                    intentType = 'setup';
                    clientSecret = remoteResponse?.response?.client_secret;
                } else {
                    clientSecret = remoteResponse?.response?.client_secret;
                }

                const displayErrorMessage = function (message, duration = 2000) {
                    new Toastify({
                        text: message,
                        className: "warning",
                        duration: duration,
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
                            return_url: gatewayReturnUrl,
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

                    // With redirect: 'if_required' the 3DS challenge renders inline on
                    // this page, and both loaders are fixed, full-viewport, opaque and
                    // z-index 999999 — left up they paint over it, and Stripe records
                    // an abandoned authentication. Stripe does not say 3DS is coming
                    // before confirm, so dropping the overlay on every pay flashes
                    // the form (worse in modal checkout). Keep it up unless a
                    // challenge iframe appears outside the Payment Element.
                    watchStripeChallengeAndHideLoaders(that.paymentLoader, loaderElement);

                    return confirmIntent(confirmData).then((result) => {
                        stopStripeChallengeWatch();
                        loaderElement?.classList?.add('active');
                        that.paymentLoader?.changeLoaderStatus('confirming');

                        const trxHash = remoteResponse?.payment_args?.vendor_subscription_info?.trx_hash
                            || remoteResponse?.payment_args?.trx_hash;

                        if (result?.error) {
                            // Report the outcome so the transaction lands as `failed`.
                            // Left pending, CheckoutProcessor does not bump
                            // `payment_attempt`, so the retry reuses the same
                            // idempotency seed and replays Stripe's cached response
                            // for an intent that can no longer be paid.
                            const failedIntentId = result.error?.payment_intent?.id
                                || result.error?.setup_intent?.id;

                            // Checkout stays disabled, and both loaders stay up, until
                            // the server has recorded the failure. Releasing first lets
                            // a fast buyer resubmit while the transaction is still
                            // `pending` — the stale-seed replay this report prevents.
                            const releaseCheckout = function (reported) {
                                loaderElement?.classList?.remove('active');
                                that.paymentLoader?.hideLoader();

                                if (!reported) {
                                    // Still `pending`, so the seed will not roll and a
                                    // retry would replay the cached response. Only a
                                    // reload gives a fresh attempt, so the notice stays
                                    // up and the button does not come back.
                                    displayErrorMessage(
                                        that.$t('We could not record that failed attempt. Please reload the page before trying again.'),
                                        -1
                                    );

                                    return;
                                }

                                that.paymentLoader.enableCheckoutButton(submitButton.text);
                                displayErrorMessage(result?.error?.message);
                            };

                            if (failedIntentId && trxHash) {
                                const failedParams = new URLSearchParams({
                                    action: 'fluent_cart_confirm_stripe_payment',
                                    intentId: failedIntentId,
                                    trx_hash: trxHash
                                }).toString();
                                const failureXhr = new XMLHttpRequest();
                                failureXhr.open('POST', window.fluentcart_checkout_vars.ajaxurl, true);
                                failureXhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                                // The HTTP status cannot say whether the failure landed: a
                                // 400 is also how an invalid request and an unfinished
                                // challenge answer, and a 500 means nothing was written.
                                // The endpoint reports the transaction's own status, and
                                // only a terminal one rolls the seed on retry.
                                failureXhr.timeout = 15000;
                                failureXhr.onload = function () {
                                    let recorded = failureXhr.status === 200;

                                    try {
                                        const reported = JSON.parse(failureXhr.responseText || '{}');
                                        recorded = recorded
                                            || reported?.transaction_status === 'failed'
                                            || reported?.transaction_status === 'succeeded';
                                    } catch (parseError) {
                                        // Unparseable body: the HTTP status is all there is.
                                    }

                                    releaseCheckout(recorded);
                                };
                                failureXhr.onerror = function () {
                                    releaseCheckout(false);
                                };
                                failureXhr.ontimeout = function () {
                                    releaseCheckout(false);
                                };
                                failureXhr.send(failedParams);
                            } else {
                                // No intent id to report (the confirm never reached Stripe),
                                // so nothing is pending server-side and the seed is untouched.
                                releaseCheckout(true);
                            }

                            return;
                        }

                        const intent = result[accessor];

                        if (intent?.status && (intent.status === 'succeeded' || intent.status === 'processing' || intent.status === 'requires_capture')) {
                            that.paymentLoader?.changeLoaderStatus('completed');
                            const mode = new URLSearchParams(window.location.search).get('mode') || 'order';
                            const params = new URLSearchParams({
                                action: 'fluent_cart_confirm_stripe_payment',
                                intentId: intent.id,
                                mode,
                                ...(trxHash ? { trx_hash: trxHash } : {})
                            }).toString();

                            const xhr = new XMLHttpRequest();
                            xhr.open('POST', window.fluentcart_checkout_vars.ajaxurl, true);
                            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                            xhr.onreadystatechange = function () {
                                if (xhr.readyState === 4) {
                                    if (xhr.status >= 200 && xhr.status < 300) {
                                        try {
                                            let responseJSON = JSON.parse(xhr.responseText);
                                            if (responseJSON) {
                                                that.paymentLoader.triggerPaymentCompleteEvent(responseJSON);
                                                if (responseJSON.redirect_url) {
                                                    successUrl = responseJSON.redirect_url;
                                                }
                                            }

                                            that.paymentLoader?.changeLoaderStatus('redirecting');
                                            // Handle redirect based on checkout mode (modal or single page)
                                            if (window.CheckoutHelper) {
                                                window.CheckoutHelper.handleCheckoutRedirect(successUrl);
                                            } else {
                                                // Fallback if CheckoutHelper is not available
                                                window.location.href = successUrl;
                                            }
                                        } catch (e) {
                                            that.paymentLoader?.changeLoaderStatus(that.$t('Payment confirmation failed'));
                                            setTimeout(function () {
                                                that.paymentLoader?.hideLoader();
                                                that.paymentLoader?.enableCheckoutButton(submitButton?.text);
                                            }, 1000);
                                        }
                                    } else {
                                        that.paymentLoader?.changeLoaderStatus(that.$t('Payment confirmation failed'));
                                        setTimeout(function () {
                                            that.paymentLoader?.hideLoader();
                                            that.paymentLoader?.enableCheckoutButton(submitButton?.text);
                                        }, 1000);
                                    }
                                }
                            };
                            xhr.onerror = function () {
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
                    stopStripeChallengeWatch();
                    loaderElement?.classList?.remove('active');
                    that.paymentLoader?.changeLoaderStatus(that.$t('Payment confirmation failed'));
                    that.paymentLoader?.hideLoader();
                    // window.location.href = `${customPaymentUrl}&status=failed&method=stripe&reason=${error?.message}`;
                });
            };
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
                    }
                    break;

                case 'promptpay_display_qr_code':
                    if (intent.next_action.promptpay_display_qr_code.hosted_instructions_url) {
                        window.location.href = intent.next_action.promptpay_display_qr_code.hosted_instructions_url;
                    } else {
                    }
                    break;

                default:
                    // Handle unknown types (e.g., show error message to user)
                    break;
            }
        };

        stripeValidateCheckoutHandler = function (e) {
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
        };
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
            window.dispatchEvent(new CustomEvent('fluent_cart_payment_method_loading_failed', {
                detail: {
                    payment_method: 'stripe'
                }
            }));
            return;
        }

        if (response?.intent?.amount <= 0 && 'subscription' !== response?.intent?.mode && 'setup' !== response?.intent?.mode) {
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
        window.dispatchEvent(new CustomEvent('fluent_cart_payment_method_loading_failed', {
            detail: {
                payment_method: 'stripe'
            }
        }));
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
