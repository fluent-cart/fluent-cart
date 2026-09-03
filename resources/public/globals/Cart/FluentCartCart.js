import UtmManager from "../utils/UTMManager";

export default class FluentCartCart {
    static #instance = null;
    static #channel = null;

    #cartData = [];
    #statusUrl = '?action=fluent_cart_checkout_routes&fc_checkout_action=fluent_cart_cart_status'
    #cartUpdateUrl = '?action=fluent_cart_checkout_routes&fc_checkout_action=fluent_cart_cart_update'
    #baseUrl = window.fluentCartRestVars.ajaxurl;
    #defaultOpen = false;
    #isAdminBarEnabled = window.fluentcart_drawer_vars?.is_admin_bar_showing;
    #cartDrawerToggleClass = 'open';
    #cartDrawerOverlayActiveClass = 'active';
    #shouldHiddenCartDrawer = window.fluentcart_drawer_vars?.is_drawer_hidden  == '1'

    init() {
        if (FluentCartCart.#instance !== null) {
            return FluentCartCart.#instance;
        }
        FluentCartCart.#instance = this;

        this.#handleMenuBarCartToggleButton();

        if(!this.#shouldHiddenCartDrawer) {
            this.#bindActionToDrawerToggleButton();
        }

        this.#handleOutSideClick();

        this.#setupDeleteButtonAction();
        this.#setupIncreaseButtonAction();
        this.#setupDecreaseButtonAction();
        this.#setupQuantityInputAction();
        this.#setupCrossTabSync();
        return this;
    }

    static #getChannelToken() {
        const key = 'fc_bc_token';
        let token = localStorage.getItem(key);
        if (!token) {
            token = (typeof crypto !== 'undefined' && crypto.randomUUID)
                ? crypto.randomUUID()
                : Math.random().toString(36).slice(2);
            localStorage.setItem(key, token);
        }
        return token;
    }

    #setupCrossTabSync() {
        if (typeof BroadcastChannel === 'undefined') return;
        if (FluentCartCart.#channel) return;
        const siteId = window.fluentCartRestVars?.ajaxurl || window.location.origin;
        FluentCartCart.#channel = new BroadcastChannel('fluent_cart_cart:' + siteId);
        FluentCartCart.#channel.onmessage = (event) => {
            if (event.data && event.data.type && event.data._token === FluentCartCart.#getChannelToken()) {
                this.#applyCrossTabUpdate(event.data);
            }
        };
    }

    #deriveCartCounts(cartData) {
        return {
            distinctCount: cartData.length,
            totalQty: cartData.reduce((sum, item) => sum + (item.quantity || 1), 0),
        };
    }

    #applyCrossTabUpdate(payload) {
        if (payload.type === 'cart_updated' && Array.isArray(payload.fragments)) {
            const cartData = Array.isArray(payload.cart_data) ? payload.cart_data : [];
            const { distinctCount, totalQty } = this.#deriveCartCounts(cartData);

            if (window.fluentcart_drawer_vars) {
                window.fluentcart_drawer_vars.cart_item_count = distinctCount;
                window.fluentcart_drawer_vars.cart_total_quantity = totalQty;
            }
            this.updateCartTotalPrice(cartData);
            this.#updateBadgeCounts(distinctCount, totalQty);
            document.querySelectorAll('.fct-cart-badge-count').forEach(el => {
                el.textContent = distinctCount.toString();
            });

            const drawerEl = document.querySelector('[data-fluent-cart-cart-drawer]');
            const overlayEl = document.querySelector('[data-fluent-cart-cart-drawer-overlay]');
            if (drawerEl) drawerEl.style.transition = 'none';
            if (overlayEl) overlayEl.style.transition = 'none';

            this.#applyFragments(payload.fragments, true);

            requestAnimationFrame(() => {
                const el = document.querySelector('[data-fluent-cart-cart-drawer]');
                const ov = document.querySelector('[data-fluent-cart-cart-drawer-overlay]');
                if (el) el.style.transition = '';
                if (ov) ov.style.transition = '';
            });

            // Drawer title count span — updated directly because the drawer container
            // fragment is skipped when the drawer already exists.
            document.querySelectorAll('[data-fluent-cart-cart-total-item]').forEach(el => {
                el.textContent = distinctCount.toString();
            });

            this.#cartData = cartData;
            this.#renderView();
        }

        if (payload.type === 'cart_cleared') {
            this.#cartData = [];
            if (window.fluentcart_drawer_vars) {
                window.fluentcart_drawer_vars.cart_item_count = 0;
                window.fluentcart_drawer_vars.cart_total_quantity = 0;
            }
            this.#updateBadgeCounts(0, 0);
            document.querySelectorAll('.fct-cart-badge-count').forEach(el => {
                el.textContent = '0';
            });
            this.updateCartTotalPrice([]);
            this.#renderView();
        }
    }

    #applyFragments(fragments, preserveExistingDrawer = false) {
        const drawerSelector = '[data-fluent-cart-cart-drawer-container]';
        fragments.forEach((fragment) => {
            const element = document.querySelector(fragment.selector);
            if (fragment.selector === drawerSelector) {
                if (!element) {
                    document.body.insertAdjacentHTML('beforeend', fragment.content);
                    if (preserveExistingDrawer) this.closeModal();
                } else if (!preserveExistingDrawer && fragment.type === 'replace') {
                    element.outerHTML = fragment.content;
                }
                return;
            }
            if (element && fragment.type === 'replace') {
                if (!fragment.content) {
                    element.remove();
                } else {
                    element.outerHTML = fragment.content;
                }
            }
        });
    }

    broadcastCartCleared() {
        FluentCartCart.broadcastCartUpdate([], null);
    }

    static broadcastCartUpdate(cartData, fragments) {
        if (!FluentCartCart.#channel) return;
        const token = FluentCartCart.#getChannelToken();
        if (!cartData || cartData.length === 0) {
            FluentCartCart.#channel.postMessage({ _token: token, type: 'cart_cleared' });
        } else {
            FluentCartCart.#channel.postMessage({
                _token: token,
                type: 'cart_updated',
                cart_data: cartData,
                fragments: fragments || [],
            });
        }
    }

    async getCart() {

        let data = await new Promise((resolve, reject) => {

            const headers = {
                'Content-Type': 'application/json',
            };
            const nonce = window.fluentCartRestVars?.rest?.nonce;
            if (nonce) {
                headers['X-WP-Nonce'] = nonce;
            }

            fetch(this.#baseUrl + this.#statusUrl, {
                method: 'GET',
                headers,
            })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(response => {

                    if (response && response.cart_data) {
                        resolve(response.cart_data)
                    } else {
                        resolve([])
                    }
                })
                .catch((errors) => {
                    reject([])
                })
        });
        this.#cartData = data;
        window.dispatchEvent(new Event('fluentCartNotifyCartDrawerItemChanged'));
        return data;
    }


    async addProduct(productId, quantity = 1, byInput = false, openCart = false, isCustom = false) {
        return this.#updateCart(productId, quantity, byInput, openCart, isCustom);
    }

    async removeProduct(variationId, openCart = false) {
        variationId = variationId.toString();
        // const index = Object.keys(this.#cartData).find(key => this.#cartData[key].object_id.toString() == (variationId));
        return await this.#updateCart(variationId, 0, false, openCart);
    }

    async incrementProduct(variationId, quantity = 1) {
        const cartDrawerOverlay = document.querySelector('[data-fluent-cart-cart-drawer-overlay]');
        if (cartDrawerOverlay) {
            if (cartDrawerOverlay.classList.contains('active')) {
                this.#defaultOpen = true;
            }
        }

        variationId = variationId.toString();
        quantity = Math.abs(parseInt(quantity.toString()))
        return await this.#updateCart(variationId, quantity)
    }

    async decrementProduct(variationId, quantity = 1) {
        const cartDrawerOverlay = document.querySelector('[data-fluent-cart-cart-drawer-overlay]');
        if (cartDrawerOverlay) {
            if (cartDrawerOverlay.classList.contains('active')) {
                this.#defaultOpen = true;
            }
        }
        variationId = variationId.toString();
        quantity = Math.abs(parseInt(quantity.toString()));
        return await this.#updateCart(variationId, quantity * -1)
    }

    async updateProductQuantity(variationId, quantity = 1, byInput = false) {
        quantity = parseInt(quantity.toString(), 10);

        //prevent invalid values
        if (isNaN(quantity) || quantity < 1) {
            quantity = 1;
        }
        variationId = variationId.toString();

        return await this.#updateCart(variationId, quantity, byInput)

    }


    async #updateCart(productId = null, quantity = 1, byInput = false, openCart = false, isCustom = false) {
        if (productId == null) {
            return;
        }
        const drawer = document.querySelector('[data-fluent-cart-cart-drawer]');
        let drawerLoader = '';
        if (drawer) {
            drawerLoader = drawer.querySelector('[data-fluent-cart-cart-drawer-loader]');
            if (drawerLoader) {
                drawerLoader.classList.add('show');
            }
        }
        const ref = this;
        let params = {
            item_id: productId,
            quantity: quantity,
            is_custom: isCustom,
        };

        if (byInput) {
            params['by_input'] = true;
        }
        if (this.#defaultOpen || openCart || byInput) {
            params['open_cart'] = true;
        }
        params['is_admin_bar_enabled'] = this.#isAdminBarEnabled;

        params = this.appendUtmSource(params);

        let capturedFragments = null;
        let capturedIsCartResponse = false;

        let data = await new Promise((resolve, reject) => {
            const url = new URL(this.#baseUrl + this.#cartUpdateUrl);

            Object.entries(params).forEach(([key, value]) => {
                url.searchParams.append(key, value);
            });

            const xhr = new XMLHttpRequest();
            xhr.open('GET', url.toString(), true);
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.setRequestHeader('X-WP-Nonce', window.fluentCartRestVars.rest.nonce);
            // add other headers if needed, e\.g\., Authorization

            xhr.onreadystatechange = function () {
                if (xhr.readyState === XMLHttpRequest.DONE) {
                    try {
                        if (xhr.status >= 200 && xhr.status < 300) {
                            const response = JSON.parse(xhr.responseText);


                            const cartData = response.data?.cart?.cart_data;
                            if (Array.isArray(cartData)) {
                                const { distinctCount, totalQty } = ref.#deriveCartCounts(cartData);
                                window.fluentcart_drawer_vars.cart_item_count = distinctCount;
                                window.fluentcart_drawer_vars.cart_total_quantity = totalQty;
                                ref.#updateBadgeCounts(distinctCount, totalQty);
                                ref.updateCartTotalPrice(cartData);
                            }

                            if (response?.fragments) {
                                const frags = Array.isArray(response.fragments)
                                    ? response.fragments
                                    : [response.fragments];
                                capturedFragments = Array.isArray(response.fragments) ? response.fragments : null;
                                ref.#applyFragments(frags);
                                const drawer = document.querySelector('[data-fluent-cart-cart-drawer]');
                                if (drawer && drawer.classList.contains(ref.#cartDrawerToggleClass)) {
                                    ref.openModal();
                                }
                            }
                            if (response?.data?.cart?.cart_data) {
                                capturedIsCartResponse = true;
                                resolve(response.data.cart.cart_data);
                            } else {
                                if (response.message) {
                                    new Toastify({
                                        text: response.message,
                                        className: "warning",
                                        duration: 3000,
                                        gravity: "top",
                                        position: 'right',
                                        slideFrom: "right",
                                        type: "warning",
                                    }).showToast();
                                }
                                resolve([]);
                            }
                            if (drawerLoader) {
                                drawerLoader.classList.remove('show');
                            }
                        } else {
                            const errorMsg = xhr.responseText
                                ? (JSON.parse(xhr.responseText).message || "An error occurred")
                                : "An error occurred";
                            new Toastify({
                                text: errorMsg,
                                className: "info",
                                duration: 2000,
                                style: {
                                    background: "#eabe11",
                                }
                            }).showToast();
                            if (drawerLoader) {
                                drawerLoader.classList.remove('show');
                            }
                            reject(new Error(errorMsg));
                        }
                    } catch (errors) {
                        if (errors.message) {
                            new Toastify({
                                text: errors.message,
                                className: "info",
                                duration: 2000,
                                style: {
                                    background: "#eabe11",
                                }
                            }).showToast();
                        }
                        if (drawerLoader) {
                            drawerLoader.classList.remove('show');
                        }
                        reject(errors);
                    }
                }
            };
            xhr.send();
        });

        if (data.length === 0) {
            // return data;
        }

        this.#cartData = data;

        if (capturedIsCartResponse) {
            FluentCartCart.broadcastCartUpdate(data, capturedFragments);
        }

        const searchParams = new URLSearchParams(window.location.search);
        if (!searchParams.has('fct_cart_hash')) {
            window.dispatchEvent(new CustomEvent('fluentCartNotifyCartDrawerItemChanged', {
                detail: {
                    response: data
                }
            }));
        }
        return data;
    }

    #setupDeleteButtonAction(){
        const ref = this;
        document.addEventListener('click', async function (e) {
            const deleteBtn = e.target.closest('[data-fluent-cart-cart-list-item-delete-button]');
            if (deleteBtn) {
                const itemId = deleteBtn.dataset.itemId;
                //if(!itemId) return;
                const data = await ref.removeProduct(itemId, true);
                if (data != null) {
                    ref.#cartData = data;
                    ref.#renderView();
                }
            }
        });
    }

    #setupIncreaseButtonAction(){
        const ref = this;
        document.addEventListener('click', async function (e) {
            const btn = e.target.closest('[data-fluent-cart-cart-list-item-increase-button]');
            // add show class to drawerLoader
            if (btn) {
                const itemId = btn.dataset.itemId;
                //if(!itemId) return;
                const data = await ref.incrementProduct(itemId);
                if (data != null) {
                    ref.#cartData = data;
                    ref.#renderView();
                }
            }
        });
    }

    #setupDecreaseButtonAction(){
        const ref = this;
        document.addEventListener('click', async function (e) {
            const btn = e.target.closest('[data-fluent-cart-cart-list-item-decrease-button]');
            if (btn) {
                const itemId = btn.dataset.itemId;
                //if(!itemId) return;
                const data = await ref.decrementProduct(itemId);
                if (data != null) {
                    ref.#cartData = data;
                    ref.#renderView();
                }
            }
        });
    }

    #setupQuantityInputAction() {
        const ref = this;
        document.addEventListener('change', async function (e) {
            const btn = e.target.closest('[data-fluent-cart-cart-list-item-quantity-input]');
            if (btn) {
                const itemId = btn.dataset.itemId;
                let value = parseInt(event.target.value, 10);
                const oldValue = parseInt(event.target.dataset.oldValue || "0", 10); // ensure number

                if (value < 1) {
                    value = 1;
                }
                let diff = value;
                const data = await ref.updateProductQuantity(itemId, diff, true);
                if (data != null) {
                    ref.#cartData = data;
                    ref.#renderView();
                }
            }
        });
    }

    #renderView() {
        if (this.#isCartEmpty()) {
            const totalItemElements = document.querySelectorAll('[data-fluent-cart-cart-total-item]');
            const checkoutCountElements = document.querySelectorAll('[data-fluent-cart-checkout-page-cart-item-count]');
            const totalWrapperElements = document.querySelectorAll('[data-fluent-cart-cart-total-wrapper]');
            const expandButtons = document.querySelectorAll('[data-fluent-cart-cart-expand-button]');
            const checkoutButtons = document.querySelectorAll('[data-fluent-cart-cart-checkout-button-wrap]');

            if (totalItemElements) {
                totalItemElements.forEach(el => el.textContent = '0');
            }
            if (checkoutCountElements) {
                checkoutCountElements.forEach(el => el.textContent = '0');
            }
            if (totalWrapperElements) {
                totalWrapperElements.forEach(el => el.style.display = 'none');
            }
            if (checkoutButtons) {
                checkoutButtons.forEach(el => el.style.display = 'none');
            }
            if (expandButtons) {
                expandButtons.forEach(el => el.classList.add('is-hidden'));
            }

            setTimeout(() => {
                this.closeModal();
            }, 300);
        }
    }

    #isCartEmpty() {
        return (this.#cartData === undefined || this.#cartData == null || Object.keys(this.#cartData).length === 0);
    }

    closeModal() {
        const drawerOverlay = document.querySelector('[data-fluent-cart-cart-drawer-overlay]');
        const drawer = document.querySelector('[data-fluent-cart-cart-drawer]');
        const bodyElement = document.body;

        if (drawerOverlay) {
            drawerOverlay.classList.remove(this.#cartDrawerOverlayActiveClass);
        }
        if (drawer) {
            drawer.classList.remove(this.#cartDrawerToggleClass);
        }
        bodyElement.style.overflow = '';
    }

    openModal() {
        const drawerOverlay = document.querySelector('[data-fluent-cart-cart-drawer-overlay]');
        const drawer = document.querySelector('[data-fluent-cart-cart-drawer]');
        const bodyElement = document.body;

        if (drawerOverlay) {
            drawerOverlay.classList.add(this.#cartDrawerOverlayActiveClass);
        }
        if (drawer) {
            drawer.classList.add(this.#cartDrawerToggleClass);
        }
        bodyElement.style.overflow = 'hidden';
    }

    #handleOutSideClick() {
        const ref = this;
        document.addEventListener('click', function(event) {
            const drawerOverlay = document.querySelector('[data-fluent-cart-cart-drawer-overlay]');
            if (drawerOverlay) {
                if (drawerOverlay.contains(event.target)) {
                    ref.closeModal();
                }
            }
        });
    }

    #bindActionToDrawerToggleButton() {
        const ref = this;
        const bodyElement = document.body;

        // Toggle button
        document.addEventListener('click', (e) => {
            const cartDrawerWrapper = document.querySelector('[data-fluent-cart-cart-drawer-container]');
            const cartDrawer = document.querySelector('[data-fluent-cart-cart-drawer]');
            const drawerOverlay = document.querySelector('[data-fluent-cart-cart-drawer-overlay]');
            const toggleButton = e.target.closest('[data-fluent-cart-cart-toggle-button]');
            const expandButton = e.target.closest('[data-fluent-cart-cart-expand-button], .fcart-cart-toggle-button');
            const collapseButton = e.target.closest('[data-fluent-cart-cart-collapse-button]');

            // Handle toggle button
            if (toggleButton) {
                if (cartDrawer) {
                    cartDrawer.classList.toggle(ref.#cartDrawerToggleClass);
                }
                return;
            }

            // Handle expand button
            if (expandButton) {
                if (cartDrawer) {
                    cartDrawer.classList.add(ref.#cartDrawerToggleClass);
                    bodyElement.style.overflow = 'hidden';
                }
                if (drawerOverlay) drawerOverlay.classList.add(ref.#cartDrawerOverlayActiveClass);
                return;
            }

            // Handle collapse button
            if (collapseButton) {
                if (cartDrawer) cartDrawer.classList.remove(ref.#cartDrawerToggleClass);
                if (drawerOverlay) drawerOverlay.classList.remove(ref.#cartDrawerOverlayActiveClass);
                bodyElement.style.overflow = '';
                return;
            }
        });
    }


    #handleMenuBarCartToggleButton() {

        this.#updateBadgeCounts(
            window.fluentcart_drawer_vars?.cart_item_count || 0,
            window.fluentcart_drawer_vars?.cart_total_quantity || 0
        );

        const menuButtonContainer = document.querySelector('.fluent-cart-menu-cart-open-button-container');
        if (menuButtonContainer) {
            const containerParent = menuButtonContainer.closest('li');
            const menuItemClone = containerParent?.previousElementSibling;

            if (menuItemClone && containerParent) {
                const clonedElement = menuItemClone.cloneNode(true);
                clonedElement.removeAttribute('id');
                clonedElement.innerHTML = '';
                clonedElement.appendChild(menuButtonContainer.cloneNode(true));
                containerParent.parentNode.replaceChild(clonedElement, containerParent);
            }
        }
    }

    /**
     * Add-to-cart forwards the campaign straight off the URL, so it never goes
     * through UTMManager.store() and the consent gate there does not cover it.
     * Ask the manager for the decision directly.
     *
     * No manager on the page means the globals bundle was not loaded and there
     * is no gate to consult, which leaves the pre-gate behaviour intact.
     */
    appendUtmSource(params) {
        const utmManager = window.fluentCartUtmManager;
        if (utmManager && !utmManager.hasConsent()) {
            return params;
        }

        const searchParams = new URLSearchParams(window.location.search);
        UtmManager.getUtmParams().forEach((param) => {
            if (searchParams.has(param)) {
                params[param] = searchParams.get(param);
            }
        })
        return params;
    }

    getState() {
        return this.#cartData
    }

    updateCartTotalPrice(cartData = []) {
        // Calculate total from subtotal (in cents)
        let totalCents = 0;
        const currencySymbol = window.fluentcart_drawer_vars?.currency_settings?.currency_sign || '$';

        cartData.forEach(item => {
            if (item.subtotal) {
                totalCents += item.subtotal;
            }
        });

        // Convert cents to dollars and format
        const totalDollars = String(parseFloat((totalCents / 100).toFixed(2)));
        const formattedTotal = `${currencySymbol}${totalDollars}`;

        // Update all elements
        document.querySelectorAll('[data-fluent-cart-cart-total-price]').forEach(el => {
            el.textContent = formattedTotal;
        });

        return formattedTotal;
    }

    #updateBadgeCounts(distinctCount, totalQty) {
        document.querySelectorAll('[data-cart-badge-count]').forEach(el => {
            const mode = el.dataset.cartCountMode || 'distinct_products';
            el.textContent = (mode === 'total_quantity' ? totalQty : distinctCount).toString();
        });
    }
}
