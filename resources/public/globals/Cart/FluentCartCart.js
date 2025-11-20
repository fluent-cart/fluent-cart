import UtmManager from "../utils/UTMManager";

export default class FluentCartCart {
    static #instance = null;

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
        return this;
    }

    async getCart() {

        let data = await new Promise((resolve, reject) => {

            fetch(this.#baseUrl + this.#statusUrl, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    // add other headers if needed, e.g., Authorization
                },
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


    async addProduct(productId, quantity = 1, byInput = false, openCart = false) {
        return this.#updateCart(productId, quantity, byInput, openCart);
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


    async #updateCart(productId = null, quantity = 1, byInput = false, openCart = false) {
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
            quantity: quantity
        };

        if (byInput) {
            params['by_input'] = true;
        }
        if (this.#defaultOpen || openCart || byInput) {
            params['open_cart'] = true;
        }
        params['is_admin_bar_enabled'] = this.#isAdminBarEnabled;

        params = this.appendUtmSource(params);

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

                            if (response && response?.fragments) {
                                if (response.fragments) {
                                    // check if array response.fragments
                                    if (Array.isArray(response.fragments)) {
                                        response.fragments.forEach((fragment) => {
                                            const element = document.querySelector(fragment.selector);
                                            if (!element && fragment.selector === '[data-fluent-cart-cart-drawer-container]') {
                                                document.body.insertAdjacentHTML('beforeend', fragment.content);
                                            }
                                            if (element && fragment.type === 'replace') {
                                                element.outerHTML = fragment.content;
                                            }
                                        });
                                    } else {
                                        const element = document.querySelector(response.fragments.selector);
                                        if (!element && response.fragments.selector === '[data-fluent-cart-cart-drawer-container]') {
                                            document.body.insertAdjacentHTML('beforeend', response.fragments.content);
                                        }
                                        if(element && response.fragments.type === 'replace') {
                                            element.outerHTML = response.fragments.content;
                                        }
                                    }

                                }
                            }
                            if (response && response?.data?.cart?.cart_data) {
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
                if (cartDrawer) cartDrawer.classList.add(ref.#cartDrawerToggleClass);
                if (drawerOverlay) drawerOverlay.classList.add(ref.#cartDrawerOverlayActiveClass);
                bodyElement.style.overflow = 'hidden';
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

    appendUtmSource(params) {

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
}
