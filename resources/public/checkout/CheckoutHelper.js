export default class CheckoutHelper {

    static quantity = null;

    static excludeKeys = ['fluent-cart', 'fct_cart_hash'];

    /**
     * Extra query params to attach to every checkout request.
     *
     * `window.fluentCartCheckoutParams` is an array of functions, each
     * returning a plain object of params. An add-on that resolves its own
     * checkout cart registers one so the server can tell its requests apart:
     *
     *     (window.fluentCartCheckoutParams ||= []).push(() => ({my_flag: 'yes'}));
     *
     * Called on EVERY request build rather than once, so a value that only
     * becomes known later — a cart hash minted on first interaction — is picked
     * up without re-registering. Contributors run last and therefore win,
     * including over excludeKeys, which strips fct_cart_hash out of the
     * inherited page query string.
     *
     * A throwing contributor is skipped; a broken add-on must not take checkout
     * down with it.
     */
    static contributedParams() {
        const params = {};

        for (const contribute of (window.fluentCartCheckoutParams || [])) {
            try {
                Object.assign(params, contribute() || {});
            } catch (e) {
                // Deliberately swallowed — see above.
            }
        }

        return params;
    }

    static handleFragments(fragments) {
        let fargmentsReplaced = false;
        
        fragments.forEach((fragment) => {
            const element = document.querySelector(fragment.selector);
            if (!element) return;
            
            if (fragment.selector && fragment.type === 'replace') {
                fargmentsReplaced = true;
            }
            
            if (fragment.type === 'replace') {
                element.innerHTML = fragment.content;
            } else {
                element.insertAdjacentHTML('beforeend', fragment.content);
            }
        });

        if (fargmentsReplaced) {
            setTimeout(() => {
                window.dispatchEvent(new CustomEvent('fluentCartFragmentsReplaced', {
                    // return only the selectors as array if fragmenst type is replace
                    detail: {
                        selectors: fragments.filter((fragment) => fragment.type === 'replace').map((fragment) => fragment.selector)
                    }
                }));
            }, 10);
        }

        // Notify other modules that summary view DOM changed
        window.dispatchEvent(new CustomEvent('fluentCartNotifySummaryViewUpdated'));
    }


    static handleSummaryViewUpdated() {
        // call the ajax route fluent_cart_checkout_routes with the action render_checkout_summary
        fetch(CheckoutHelper.buildUrl(`${window.fluentcart_checkout_vars.ajaxurl}?action=fluent_cart_checkout_routes&fc_checkout_action=get_checkout_summary_view`).toString(), {
            headers: {
                'X-WP-Nonce': window.fluentCartRestVars.rest.nonce
            },
            credentials: 'include'
        })
        .then(response => {
            return response.json();
        })
        .then(response => {
            this.handleFragments(response.fragments);
            window.dispatchEvent(new CustomEvent('fluentCartCheckoutDataChanged', {
                detail: {
                    response: response
                }
            }));
        })
        .catch(error => {
        });
        
    }


    static getParams() {
        const searchParams = new URLSearchParams(window.location.search);
        const params = {};


        if(window.fluentcart_checkout_vars.is_instant_checkout === 'yes'){
            params['fct_cart_hash'] = window.fluentcart_checkout_vars.cart_hash;
        }

        if(window.fluentcart_checkout_vars.is_modal_checkout === 'yes'){
            params['modal_checkout'] = 'yes';
        }



        if (searchParams.has('quantity')) {
            params['quantity'] = searchParams.get('quantity');
        }

        const coupons = window.fluentCartCouponService?.getCoupons();
        if (Array.isArray(coupons) && coupons.length > 0) {
            params['coupons'] = coupons.join(',');
        }

        const shippingChargeId = window.fluent_cart_shipping_service?.currentShippingMethodId;

        if(shippingChargeId){
            params['shipping_method_id'] = shippingChargeId;
        }

        // Last, so a contributor wins over everything above — same rule as
        // _build(). Both builders are used, so both have to honour it.
        Object.assign(params, CheckoutHelper.contributedParams());

        return params;
    }

    static buildUrl(rawUrl, extraParams = {}) {


        const handler = {
            get(target, prop) {
                // Intercept string coercion and direct access
                if (
                    prop === 'toString' ||
                    prop === 'valueOf' ||
                    prop === Symbol.toPrimitive
                ) {
                    return () => CheckoutHelper._build(target.rawUrl, extraParams);
                }
                // Allow access to rawUrl if needed
                if (prop === 'rawUrl') {
                    return target.rawUrl;
                }
                // Fallback to normal behavior for other properties
                return Reflect.get(target, prop);
            }
        };

        return new Proxy({rawUrl}, handler);
    }

    static _build(url, extraParams) {

        const searchParams = new URLSearchParams(window.location.search);

        if (CheckoutHelper.quantity == null) {
            CheckoutHelper.quantity = searchParams.get('quantity') || 1;
        }

        for (const [key, value] of Object.entries(extraParams)) {
            searchParams.set(key, value); // set overwrites existing key or adds new
        }
        try {
            const fullUrl = new URL(url);
            for (const [key, value] of searchParams) {
                if(CheckoutHelper.excludeKeys.includes(key)) continue;
                fullUrl.searchParams.set(key, value);
            }

            if(window.fluentcart_checkout_vars.is_instant_checkout === 'yes'){
                fullUrl.searchParams.set('fct_cart_hash', window.fluentcart_checkout_vars.cart_hash);

            }

            if(window.fluentcart_checkout_vars.is_modal_checkout === 'yes'){
                fullUrl.searchParams.set('modal_checkout', 'yes');
            }

            // Last, so a contributor can override anything above — including
            // the fct_cart_hash that excludeKeys strips.
            for (const [key, value] of Object.entries(CheckoutHelper.contributedParams())) {
                fullUrl.searchParams.set(key, value);
            }

            // remove action param from url if exists
         //   fullUrl.searchParams.delete('action');

            fullUrl.searchParams.set(
                'quantity',
                CheckoutHelper.quantity
            );

            const coupons = window.fluentCartCouponService?.getCoupons();
            if (Array.isArray(coupons) && coupons.length > 0) {
                fullUrl.searchParams.set('coupons', coupons.join(','));
            }

            const shippingChargeId = window.fluent_cart_shipping_service?.currentShippingMethodId;

            if(shippingChargeId){
                fullUrl.searchParams.set('shipping_method_id', shippingChargeId);
            }

            return fullUrl.href;
        } catch (error) {
            return url; // Fallback to raw URL
        }
    }

    /**
     * Handle checkout redirect based on checkout mode (modal or single page)
     * @param {string} redirectUrl - The URL to redirect to
     * @param {string} targetOrigin - Optional target origin for postMessage (defaults to '*')
     */
    static handleCheckoutRedirect(redirectUrl, targetOrigin = '*') {
        if (!redirectUrl) {
            return;
        }

        const isModalCheckout = window.fluentcart_checkout_vars?.is_modal_checkout === 'yes';

        if (isModalCheckout) {
            // For modal checkout, send message to parent window
            window.parent.postMessage({
                type: 'fluentCartCheckoutComplete',
                redirectUrl: redirectUrl
            }, targetOrigin);
        } else {
            // For single page checkout, redirect directly
            window.location.href = redirectUrl;
        }
    }
}
