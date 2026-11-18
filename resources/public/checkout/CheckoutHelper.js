const LINE_ITEM_TOOLTIP_HINT_SELECTOR = '.fct_line_items .fct_item_tax_hint';
const TOOLTIP_GAP_FROM_HINT_PX = 8; // the in-flow tooltip's mt-2
const TOOLTIP_VIEWPORT_MARGIN_PX = 8;

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

    // A scrolling list must take focus so keyboard users can scroll it; a short one must not add a Tab stop.
    static syncLineItemsScrollState(lineItems) {
        const isScrollable = lineItems.scrollHeight > lineItems.clientHeight + 1;
        if (isScrollable) {
            lineItems.tabIndex = 0;
        } else {
            lineItems.removeAttribute('tabindex');
        }
        // Overlay scroll bars (the macOS default) take no width, so they would cover the prices.
        const style = getComputedStyle(lineItems);
        const borderWidth = parseFloat(style.borderLeftWidth) + parseFloat(style.borderRightWidth);
        const scrollbarWidth = lineItems.offsetWidth - lineItems.clientWidth - borderWidth;
        lineItems.classList.toggle('has-overlay-scrollbar', isScrollable && scrollbarWidth < 1);
    }

    // Below the hint, end-aligned like the in-flow CSS; above when only that fits; always inside the window.
    static getTooltipPosition(hintRect, tooltipSize, viewportSize, {alignToHintStart = false, isRtl = false} = {}) {
        let left = alignToHintStart !== isRtl ? hintRect.left : hintRect.right - tooltipSize.width;
        left = Math.max(TOOLTIP_VIEWPORT_MARGIN_PX, Math.min(left, viewportSize.width - tooltipSize.width - TOOLTIP_VIEWPORT_MARGIN_PX));

        let top = hintRect.bottom + TOOLTIP_GAP_FROM_HINT_PX;
        if (top + tooltipSize.height > viewportSize.height - TOOLTIP_VIEWPORT_MARGIN_PX && hintRect.top - TOOLTIP_GAP_FROM_HINT_PX - tooltipSize.height >= TOOLTIP_VIEWPORT_MARGIN_PX) {
            top = hintRect.top - TOOLTIP_GAP_FROM_HINT_PX - tooltipSize.height;
        }
        top = Math.max(TOOLTIP_VIEWPORT_MARGIN_PX, Math.min(top, viewportSize.height - tooltipSize.height - TOOLTIP_VIEWPORT_MARGIN_PX));

        return {left: Math.round(left), top: Math.round(top)};
    }

    // Hidden by CSS until opened, so the in-flow :hover tooltip (clipped by the list) never shows.
    static prepareLineItemTooltips(root, targetWindow = window) {
        if (!targetWindow.HTMLElement || !('showPopover' in targetWindow.HTMLElement.prototype)) {
            return;
        }
        root.querySelectorAll(LINE_ITEM_TOOLTIP_HINT_SELECTOR + ' .fct_item_tax_tooltip').forEach(tooltip => {
            if (!tooltip.hasAttribute('popover')) {
                tooltip.setAttribute('popover', 'manual');
            }
        });
    }

    // Line-item tooltips sit inside the scrolling, masked item list, which clips them.
    // Where the Popover API exists they open in the top layer, placed next to their hint.
    static initLineItemTooltips(targetDocument = document, targetWindow = window) {
        if (!('showPopover' in targetWindow.HTMLElement.prototype)) {
            return;
        }

        CheckoutHelper.prepareLineItemTooltips(targetDocument, targetWindow);

        let openTooltip = null;
        let hideTooltipTimer = 0;
        let followFrame = 0;
        // Tracked from pointer events: during a click, :hover is not yet updated when focus moves.
        let hoveredHint = null;

        const findTooltipHint = target => (target instanceof targetWindow.Element ? target.closest(LINE_ITEM_TOOLTIP_HINT_SELECTOR) : null);

        const positionTooltip = (hint, tooltip) => {
            const position = CheckoutHelper.getTooltipPosition(
                hint.getBoundingClientRect(),
                {width: tooltip.offsetWidth, height: tooltip.offsetHeight},
                {width: targetDocument.documentElement.clientWidth, height: targetWindow.innerHeight},
                {
                    alignToHintStart: tooltip.classList.contains('fct_unit_price_rounding_tooltip'),
                    isRtl: targetWindow.getComputedStyle(hint).direction === 'rtl'
                }
            );
            tooltip.style.left = position.left + 'px';
            tooltip.style.top = position.top + 'px';
        };

        const hideTooltip = () => {
            targetWindow.clearTimeout(hideTooltipTimer);
            if (openTooltip && openTooltip.isConnected && openTooltip.matches(':popover-open')) {
                openTooltip.hidePopover();
            }
            openTooltip = null;
        };

        const showTooltip = hint => {
            targetWindow.clearTimeout(hideTooltipTimer);
            const tooltip = hint.querySelector('.fct_item_tax_tooltip');
            if (!tooltip || tooltip === openTooltip) {
                return;
            }
            if (openTooltip) {
                hideTooltip();
            }
            if (!tooltip.hasAttribute('popover')) {
                tooltip.setAttribute('popover', 'manual');
            }
            if (!tooltip.matches(':popover-open')) {
                tooltip.showPopover();
            }
            openTooltip = tooltip;
            positionTooltip(hint, tooltip);
        };

        // Follow the hint; close it once the list has scrolled it out of view.
        const followOpenTooltip = () => {
            if (!openTooltip) {
                return;
            }
            const hint = openTooltip.isConnected ? openTooltip.closest('.fct_item_tax_hint') : null;
            const lineItems = hint ? hint.closest('.fct_line_items') : null;
            if (!lineItems) {
                openTooltip = null;
                return;
            }
            const hintRect = hint.getBoundingClientRect();
            const lineItemsRect = lineItems.getBoundingClientRect();
            const outsideList = hintRect.bottom < lineItemsRect.top || hintRect.top > lineItemsRect.bottom;
            const outsideWindow = hintRect.bottom < 0 || hintRect.top > targetWindow.innerHeight;
            if (outsideList || outsideWindow) {
                hideTooltip();
                return;
            }
            positionTooltip(hint, openTooltip);
        };

        // Close a hovered tooltip; a hint that still has keyboard focus gets its tooltip back.
        const closeHoveredTooltip = () => {
            hideTooltip();
            const focusedHint = findTooltipHint(targetDocument.activeElement);
            if (focusedHint) {
                showTooltip(focusedHint);
            }
        };

        targetDocument.addEventListener('pointerover', event => {
            const hint = findTooltipHint(event.target);
            // A touch here may start a scroll; a tap opens it through click.
            if (hint && event.pointerType !== 'touch') {
                hoveredHint = hint;
                showTooltip(hint);
            }
        });

        targetDocument.addEventListener('pointerout', event => {
            const hint = findTooltipHint(event.target);
            if (hint && !hint.contains(event.relatedTarget)) {
                hoveredHint = null;
            }
            // Touch has no hover: a tap elsewhere closes it (pointerdown). Keyboard focus keeps it open.
            // The delay lets the mouse reach the tooltip.
            if (hint && event.pointerType !== 'touch' && !hint.contains(event.relatedTarget) && !hint.matches(':focus-within')) {
                hideTooltipTimer = targetWindow.setTimeout(closeHoveredTooltip, 300);
            }
        });

        targetDocument.addEventListener('click', event => {
            const hint = findTooltipHint(event.target);
            if (hint) {
                showTooltip(hint);
            }
        });

        targetDocument.addEventListener('pointerdown', event => {
            if (openTooltip && !findTooltipHint(event.target)) {
                hideTooltip();
            }
        });

        targetDocument.addEventListener('focusin', event => {
            const hint = findTooltipHint(event.target);
            if (hint) {
                showTooltip(hint);
            }
        });

        targetDocument.addEventListener('focusout', event => {
            const hint = findTooltipHint(event.target);
            // A click on the tooltip text moves focus to the list; the pointer is still on it.
            if (hint && !hint.contains(event.relatedTarget) && hint !== hoveredHint) {
                hideTooltip();
            }
        });

        targetDocument.addEventListener('keydown', event => {
            if (event.key === 'Escape' && openTooltip) {
                hideTooltip();
            }
        });

        const scheduleFollowOpenTooltip = () => {
            if (openTooltip && !followFrame) {
                followFrame = targetWindow.requestAnimationFrame(() => {
                    followFrame = 0;
                    followOpenTooltip();
                });
            }
        };

        targetWindow.addEventListener('scroll', scheduleFollowOpenTooltip, {capture: true, passive: true});
        // Mobile toolbars resize the window while the page scrolls.
        targetWindow.addEventListener('resize', scheduleFollowOpenTooltip, {passive: true});
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
                // Keep the scrolled line-item list where the shopper left it.
                const oldLineItems = element.querySelector('.fct_line_items');
                const savedScrollTop = oldLineItems ? oldLineItems.scrollTop : 0;
                const lineItemsHadFocus = !!oldLineItems && oldLineItems === document.activeElement;

                element.innerHTML = fragment.content;

                CheckoutHelper.prepareLineItemTooltips(element);

                const newLineItems = element.querySelector('.fct_line_items');
                if (newLineItems) {
                    CheckoutHelper.syncLineItemsScrollState(newLineItems);
                    newLineItems.scrollTop = savedScrollTop;
                    if (lineItemsHadFocus && newLineItems.tabIndex === 0) {
                        newLineItems.focus({preventScroll: true});
                    }
                }
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
