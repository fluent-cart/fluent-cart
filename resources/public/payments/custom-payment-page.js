import PaymentLoader from "./payment-loader";

const $ = jQuery;
$(() => {
    const paymentVars = window.fluentcart_checkout_info;
    const baseUrl = paymentVars.baseUrl;
    const paymentMode = paymentVars.paymentMode;
    const orderHash = paymentVars.orderHash;
    const rest_url = paymentVars.rest_url;
    const checkout_nonce = paymentVars?.rest.nonce;
    const ajaxurl = paymentVars.ajaxurl;
    const variationId = paymentVars.variationId;
    const orderConfirmation = paymentVars.order_information_url;
    const custom_checkout_url = paymentVars.custom_checkout_url;
    const translate = window.fluentcart.$t;


    let form = $($('form[data-fluent-cart-checkout-page-checkout-form]')[0]);
    const url = orderConfirmation;


    // This page has no submit-button disable, so without an in-flight guard a
    // rapid double submit fires two concurrent checkout POSTs.
    let isPlacingOrder = false;

    const orderHandler = async (e) => {

        if (e !== undefined) {
            e.preventDefault();
        }

        if (isPlacingOrder) {
            return false;
        }

        const loaderElement = jQuery('.fct-loader');

        const payMethod = paymentLoader.getPaymentMethod();

        const paymentElementState = window['is_' + payMethod + '_ready']; //is_stripe_ready, is_paypal_ready
        if (payMethod !== 'offline_payment' && !paymentElementState) {
            window.dispatchEvent(new CustomEvent('fluent_cart_validate_checkout_' + payMethod));
            return false;
        }

        const checkoutUrl = custom_checkout_url;
        // get params from the search string
        const params = new URLSearchParams(window.location.search);

        let licenseKey = params.get('license_key') || '';
        let coupon = params.get('coupon') || '';


        loaderElement.addClass('active');
        isPlacingOrder = true;
        try {
            return await jQuery.post(checkoutUrl, {
                order_hash: orderHash,
                mode: paymentMode,
                payment_method: payMethod,
                variation_id: variationId,
                license_key: licenseKey,
                coupon: coupon,
                _wpnonce: checkout_nonce
            }).then(response => {
                if (response.actionName === 'custom') {
                    window.dispatchEvent(new CustomEvent('fluent_cart_payment_next_action_' + response.nextAction, {
                        detail: {
                            // form: this.form,
                            response: response
                        }
                    }));
                } else if (response.redirect_to) {
                    window.location.href = response?.redirect_to;
                } else if (response?.status === 'failed') {
                    alert(response?.message || translate('Payment failed. Please try again.'));
                } else {
                    loaderElement.removeClass('active');
                }
                // always return the response
                return response;
            }).catch((response) => {
                loaderElement.removeClass('active');
                let errorMessage = '';
                if (response?.responseJSON?.data?.message) {
                    errorMessage = response.responseJSON.data.message;
                } else if (response?.responseJSON?.message) {
                    errorMessage = response.responseJSON.message;
                } else {
                    const errors = response?.responseJSON?.data?.errors || response?.responseJSON?.errors || {};
                    for (const key in errors) {
                        if (errors.hasOwnProperty(key)) {
                            errorMessage += `${key}: ${errors[key]}\n`;
                        }
                    }
                }
                alert(errorMessage || translate('Payment failed. Please try again.'));
            });
        } finally {
            isPlacingOrder = false;
        }
    };

    form.on('submit', orderHandler);
    const paymentLoader = new PaymentLoader({form: form[0], orderHandler, customPayment: true});    paymentLoader.load()
});
