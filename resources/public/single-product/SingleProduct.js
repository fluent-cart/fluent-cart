import Tab from "./tab/Tab.js";
import ImageGallery from "./ImageGallery";

window.FluentCartImageGallery = ImageGallery;

document.addEventListener('DOMContentLoaded', () => {
    class FluentCartSingleProduct {
        static #instance = null;
        #container;
        #variationButtons;
        #quantity;
        #quantityContainer;
        #increaseButton;
        #decreaseButton;
        #addToCartButtons;
        #buyNowButtons;
        #thumbnailControls;
        #thumbnailControlsWrapper;
        #tab;
        #index;
        #currentlySelectedVariationId = 0;
        #itemPrice;
        #subscriptionInfo;
        #productId;
        #pricingSection;
        #variationController;

        toTitleCase(str) {
            return str.replace(
                /\w\S*/g,
                function (txt) {
                    return txt.charAt(0).toUpperCase() + txt.substr(1).toLowerCase();
                }
            );
        }

        $t(str) {
            return window.fluentcart_single_product_vars.trans[str] || str;
        }

        // Helper method to find elements within container
        findInContainer(selector) {
            return this.#container.querySelectorAll(selector);
        }

        findOneInContainer(selector) {
            return this.#container.querySelector(selector);
        }

        init(container, index) {
            this.#index = index;
            this.#container = container;

            this.#variationButtons = this.findInContainer('[data-fluent-cart-product-variant]');
            this.#productId = this.#container.getAttribute('data-product-id');

            this.#increaseButton = this.findOneInContainer('[data-fluent-cart-product-qty-increase-button]');
            this.#decreaseButton = this.findOneInContainer('[data-fluent-cart-product-qty-decrease-button]');
            this.#quantity = this.findOneInContainer('[data-fluent-cart-single-product-page-product-quantity-input]');
            this.#quantityContainer = this.findOneInContainer('[data-fluent-cart-product-quantity-container]');
            this.#addToCartButtons = this.findInContainer('[data-fluent-cart-add-to-cart-button]');
            this.#buyNowButtons = this.findInContainer('[data-fluent-cart-direct-checkout-button]');
            this.#thumbnailControls = this.findInContainer('[data-fluent-cart-thumb-control-button]');
            this.#thumbnailControlsWrapper = this.findOneInContainer('[data-fluent-cart-single-product-page-product-thumbnail-controls]');
            this.#itemPrice = this.findOneInContainer('[data-fluent-cart-product-item-price]');
            this.#subscriptionInfo = this.findOneInContainer('[data-fluent-cart-product-payment-type]');
            this.#pricingSection = this.findOneInContainer('[data-fluent-cart-product-pricing-section]');

            this.#setupIncreaseButton();
            this.#setupDecreaseButton();
            this.#setupQuantityInput();
            this.#setupCartButtons();

            // Hand off to the advanced-variation controller (registered by Pro
            // via window.FluentCartVariationControllers.advanced) when this
            // product is an advanced variation. Falls back to the standard
            // variation-button setup when the controller is unavailable —
            // either Pro is inactive, or this is a simple-variation product.
            FluentCartSingleProduct.#instance = this;
            window.fluentCartSingleProduct = this;

            const advancedWrap = this.findOneInContainer('.fct-advanced-variation-wrap');
            const Controller = (window.FluentCartVariationControllers || {}).advanced || null;
            if (advancedWrap && Controller) {
                this.#variationController = new Controller();
                this.#variationController.init(advancedWrap);
            } else {
                this.#setupVariationButtons();
            }

            this.#setup();

            this.#initTabOnDemand();
            this.#setMobileViewClass();
            this.#listenWindowResize();

            return this;
        }

        #listenWindowResize() {
            window.addEventListener('resize', _ => {
                this.#setMobileViewClass();
            });
        }

        #setMobileViewClass() {
            const productPage = document.querySelector('.fluent-cart-single-product-page');
            if (!productPage) return;
            if (productPage.offsetWidth <= 815) {
                productPage.classList.add('is-mobile');
            } else {
                productPage.classList.remove('is-mobile');
            }
        }

        #getImageIndexFromAlbum(album, imageSrc) {
            if (!Array.isArray(album)) {
                return -1;
            }
            return album.findIndex(item => item.link === imageSrc);
        }

        #initTabOnDemand() {
            const tabContainer = this.findOneInContainer('[data-fluent-cart-product-tab]');
            if (tabContainer) {
                this.#tab = new Tab(this.#container);
                this.#tab.init();
            }
        }


        #setup() {
            const activeVariationButton = this.findOneInContainer('.selected[data-fluent-cart-product-variant]');

            const cartId = activeVariationButton?.dataset.cartId;
            const itemPrice = activeVariationButton?.dataset.itemPrice;
            const subscriptionTerms = activeVariationButton?.dataset.subscriptionTerms;
            const activeVariantPaymentType = activeVariationButton?.dataset.paymentType;
            // const stockStatus = activeVariationButton?.dataset.itemStock;

            let checkStockStatus = window.fluentcart_single_product_vars?.in_stock_status;
            let stockManagement = activeVariationButton?.dataset.stockManagement;
            let stockStatus = checkStockStatus;
            if (stockManagement === 'yes') {
                stockStatus = activeVariationButton?.dataset.itemStock;
            }

            // For simple products, also check product-level stock from the buy now button
            const buyNowStockAttr = this.#buyNowButtons[0]?.dataset.stockAvailability;
            if (buyNowStockAttr === window.fluentcart_single_product_vars?.out_of_stock_status) {
                stockStatus = buyNowStockAttr;
            }

            // if (this.#itemPrice && itemPrice) {
            //     this.#itemPrice.textContent = itemPrice;
            //     const priceSuffix = activeVariationButton?.dataset.priceSuffix;
            //     if (priceSuffix) {
            //         this.#itemPrice.insertAdjacentHTML('beforeend', ' <span class="fct_price_suffix">' + priceSuffix + '</span>');
            //     }
            // }

            // if (this.#subscriptionInfo && subscriptionTerms) {
            //     this.#subscriptionInfo.innerHTML = subscriptionTerms;
            // }

            // if (activeVariationButton?.dataset.comparePrice && this.#subscriptionInfo) {
            //     this.#subscriptionInfo.insertAdjacentHTML('afterbegin', ' <span class="fct-compare-price" style="margin-right: 4px;"><del> ' + activeVariationButton?.dataset.comparePrice + '</del></span>');
            //     this.#subscriptionInfo.classList.remove('is-hidden');
            // }

            if (cartId !== undefined) {
                this.#addToCartButtons.forEach(button => {
                    button.setAttribute('data-cart-id', cartId);
                });
                this.#setupBuyNowButton(cartId, stockStatus);
            }

            // set direct checkout button for simple product
            if (cartId === undefined && this.#variationButtons.length === 0) {
                const cartId = this.#buyNowButtons[0]?.dataset.cartId;
                const status = this.#buyNowButtons[0]?.dataset.stockAvailability;
                this.#setupBuyNowButton(cartId, status);
            }

            const controlButtons = this.#thumbnailControlsWrapper?.querySelectorAll('[data-fluent-cart-thumb-control-button]:not(.is-hidden)');
            const selectedVariantButton = this.#pricingSection?.querySelector('[data-fluent-cart-product-variant].selected');

            if (selectedVariantButton) {
                this.#currentlySelectedVariationId = selectedVariantButton?.dataset?.cartId || 0;
            } else {
                this.#currentlySelectedVariationId = controlButtons?.dataset?.variationId || 0;
            }
            this.#setupControlWrapper(controlButtons);

            const url = new URL(window.location.href);
            const searchParams = url.searchParams;
            if (searchParams.has('selected')) {
                const variationId = searchParams.get('selected');
                const button = this.findOneInContainer(`[data-fluent-cart-product-variant][data-cart-id="${variationId}"]`);
                if (button) {
                    this.#handleVariationChange(button);
                }
            }

            this.#initiallyHideOutOfStockButton();
            this.#initiallyHideAddToCartButton();

            const paymentType = this.#variationButtons[0]?.dataset.paymentType || this.#quantityContainer?.dataset.paymentType;
            const variationType = this.#buyNowButtons[0]?.dataset.variationType || this.#quantityContainer?.dataset.variationType;

            if (paymentType === 'subscription' && variationType === 'simple') {
                this.#subscriptionInfo?.classList.remove('is-hidden');
            }

            if (activeVariantPaymentType === 'subscription') {
                this.#subscriptionInfo?.classList.remove('is-hidden');
            }

            // if(variationType === 'simple' && paymentType !== 'subscription') {
            //     this.#quantityContainer?.classList.remove('is-hidden');
            // }

            if (activeVariantPaymentType === 'onetime') {
                //this.#quantityContainer?.classList.remove('is-hidden');
            }

            if (activeVariationButton?.dataset.cartId === activeVariationButton?.dataset.defaultVariationId) {
                this.#thumbnailControls.forEach(control => {
                    control.classList.remove('is-hidden');
                });
            }

            this.#updateProductStatus(stockStatus);
        }

        #setupBuyNowButton(cartId, status) {
            const st = (window.fluentcart_single_product_vars?.out_of_stock_status || '').toString();

            this.#buyNowButtons.forEach(button => {
                if (status !== st) {
                    const quantity = button.dataset.quantity;
                    let url = button.getAttribute('data-url') + cartId + '&quantity=' + quantity;
                    button.setAttribute('href', url);
                    button.setAttribute('data-cart-id', cartId);
                    button.classList.remove('is-hidden');
                } else {
                    button.removeAttribute('href');
                    button.classList.add('is-hidden');
                }
            });
        }

        #updateBuyNowButtonUrl(quantity) {
            this.#buyNowButtons.forEach(button => {
                button.setAttribute('data-quantity', quantity);
                const cartId = button.getAttribute('data-cart-id');
                if (cartId) {
                    let url = button.getAttribute('data-url') + cartId + '&quantity=' + quantity;
                    button.setAttribute('href', url);
                }
            });
        }

        #updateAddToCartQuantity(quantity) {
            this.#addToCartButtons.forEach(button => {
                button.setAttribute('data-quantity', quantity);
            });
        }

        #setupVariationButtons() {
            this.#variationButtons.forEach(button => {
                button.addEventListener('click', (event) => {
                    this.#handleVariationChange(button);
                });

                button.addEventListener('keydown', (event) => {
                    const radiogroup = button.closest('[role="radiogroup"]');
                    if (!radiogroup) return;

                    const radios = Array.from(radiogroup.querySelectorAll('[role="radio"]'));
                    const currentIndex = radios.indexOf(button);
                    let targetIndex = -1;

                    switch (event.key) {
                        case 'Enter':
                        case ' ':
                            event.preventDefault();
                            this.#handleVariationChange(button);
                            return;
                        case 'ArrowDown':
                        case 'ArrowRight':
                            event.preventDefault();
                            targetIndex = (currentIndex + 1) % radios.length;
                            break;
                        case 'ArrowUp':
                        case 'ArrowLeft':
                            event.preventDefault();
                            targetIndex = (currentIndex - 1 + radios.length) % radios.length;
                            break;
                        case 'Home':
                            event.preventDefault();
                            targetIndex = 0;
                            break;
                        case 'End':
                            event.preventDefault();
                            targetIndex = radios.length - 1;
                            break;
                        default:
                            return;
                    }

                    if (targetIndex >= 0) {
                        radios[targetIndex].focus();
                        this.#handleVariationChange(radios[targetIndex]);
                    }
                });
            });
        }

        #setupControlWrapper(controlButtons) {
            if (controlButtons && controlButtons.length > 0) {
                const control = controlButtons[0];
                control.classList.add('active');
                this.#setThumbImage(control);
            }
        }

        #handleVariationChange(button) {
            this.#variationButtons.forEach(btn => {
                btn.classList.remove('selected');
                btn.setAttribute('aria-checked', 'false');
                btn.setAttribute('tabindex', '-1');
            });

            button.setAttribute('aria-checked', 'true');
            button.setAttribute('tabindex', '0');
            button.classList.add('selected');

            const variationId = button.dataset.cartId;

            // Clear the stale thumbnail highlight BEFORE selectVariation(). That
            // call dispatches fluentCartSingleProductVariationChanged synchronously,
            // and ImageGallery reacts by activating this variant's thumbnail. Doing
            // the removal afterwards (as before) wiped the class ImageGallery had
            // just set, leaving the image swapped but no thumbnail selected.
            this.#thumbnailControls.forEach(control => control.classList.remove('active'));

            this.selectVariation(variationId);
            this.#resetQuantity();
            this.#currentlySelectedVariationId = variationId;

            const productScope = this.#container.closest('.fct-product-summary') || this.#container.closest('.product-info-block-wrapper') || this.#container.parentElement;

            let status = window.fluentcart_single_product_vars?.in_stock_status;
            if (button.dataset.stockManagement === 'yes') {
                status = button.dataset.itemStock;
            }

            if (variationId !== undefined) {
                this.#addToCartButtons.forEach(btn => btn.setAttribute('data-cart-id', variationId));
                this.#setupBuyNowButton(variationId, status);

                if (button.dataset.paymentType !== 'subscription') {
                    this.#addToCartButtons.forEach(btn => {
                        btn.classList.remove('is-hidden');
                        btn.setAttribute('data-cart-id', variationId);
                    });
                }
            }

            if (status !== undefined) {
                this.#updateProductStatus(status);
            }

            // For in-stock subscriptions, hide add-to-cart
            const outOfStockSt = window.fluentcart_single_product_vars.out_of_stock_status;
            if (button.dataset.paymentType === 'subscription' && status !== outOfStockSt) {
                this.#addToCartButtons.forEach(btn => btn.classList.add('is-hidden'));
            }

            // Update SKU
            const skuValue = button.dataset.sku || '';
            const skuElement = productScope?.querySelector('[data-fluent-cart-product-sku]');
            if (skuElement) {
                skuElement.textContent = skuValue;
                const skuWrapper = skuElement.closest('.fct-product-sku');
                if (skuWrapper) {
                    skuWrapper.style.display = skuValue ? '' : 'none';
                }
            }

            // Update aria-labels on purchase buttons
            const variantName = button.getAttribute('aria-label') || '';
            if (variantName) {
                this.#buyNowButtons.forEach(btn => {
                    btn.setAttribute('aria-label', btn.textContent.trim() + ' - ' + variantName);
                });
                this.#addToCartButtons.forEach(btn => {
                    const textEl = btn.querySelector('.text');
                    const baseText = textEl ? textEl.textContent.trim() : btn.textContent.trim();
                    btn.setAttribute('aria-label', baseText + ' - ' + variantName);
                });
            }

            // Update variant price info
            this.findInContainer('[data-fluent-cart-single-product-page-product-variant-price-info]')
                .forEach(el => el.classList.remove('selected'));
            this.findOneInContainer(`[data-fluent-cart-single-product-page-product-variant-price-info][data-cart-id="${variationId}"]`)
                ?.classList.add('selected');

            // Update quantity elements
            this.findInContainer(`[data-fluent-cart-single-product-page-product-quantity][data-cart-id="${variationId}"]`)
                .forEach(el => el.classList.add('selected'));
        }

        selectVariation(variationId) {
            const productScope = this.#container.closest('.fct-product-summary') || this.#container.closest('.product-info-block-wrapper') || this.#container.parentElement;
            const scope = productScope || this.#pricingSection;
            scope.querySelectorAll('.fluent-cart-product-variation-content[data-variation-id]')
                .forEach(el => el.classList.add('is-hidden'));
            scope.querySelectorAll(`.fluent-cart-product-variation-content[data-variation-id="${variationId}"]`)
                .forEach(el => el.classList.remove('is-hidden'));

            window.dispatchEvent(new CustomEvent('fluentCartSingleProductVariationChanged', {
                detail: {
                    productId:   this.#productId,
                    variationId: variationId,
                }
            }));
        }

        updateGalleryByVariation(variationId = 0) {
            const variationImages = document.querySelectorAll(`[data-fluent-cart-thumb-control-button][data-variation-id="${variationId}"]`);
            if (variationImages.length > 0) {


                const otherImages = document.querySelectorAll(`[data-fluent-cart-thumb-control-button][data-variation-id]:not([data-variation-id="${variationId}"])`);
                otherImages.forEach(img => img.classList.add('is-hidden'));
                variationImages.forEach(img => img.classList.remove('is-hidden'));
            } else {
                const defaultImages = document.querySelectorAll(`[data-fluent-cart-thumb-control-button][data-variation-id="0"]`);
                defaultImages.forEach(img => img.classList.remove('is-hidden'));
            }
        }

        #updateButtonText(button, newText) {
            // If button is explicitly icon-only, don't update text
            if (button.getAttribute('data-icon-only') === 'true') {
                return;
            }

            const textEl = button.querySelector('.text');
            if (!textEl) return;

            // Check if there's an existing text span (for icon + text buttons)
            let textSpan = textEl.querySelector('span');

            if (textSpan) {
                // Update existing text span
                textSpan.textContent = newText;
            } else {
                // No text span found, just replace the text
                textEl.textContent = newText;
            }
        }

        #updateProductStatus(status) {

            if (!status) return;
            const outOfStockStatus = window.fluentcart_single_product_vars.out_of_stock_status;
            const isOutOfStock = status === outOfStockStatus;

            // Update stock badge text and classes
            // Stock badge is outside the pricing section container, so search in the broader product scope
            const productScope = this.#container.closest('.fct-product-summary') || this.#container.closest('.product-info-block-wrapper') || this.#container.parentElement;
            const statusElement = productScope?.querySelector("[data-fluent-cart-product-stock]");
            if (statusElement) {
                // Prefer a per-status custom label (e.g. from the Bricks Product Stock
                // element) when present; otherwise fall back to the generic label map.
                const customText = isOutOfStock
                    ? statusElement.getAttribute('data-out-of-stock-text')
                    : statusElement.getAttribute('data-in-stock-text');
                if (customText) {
                    // getAttribute() returns the decoded string, so assign via textContent
                    // to avoid reparsing any markup as HTML (matches the advanced-selector path).
                    statusElement.textContent = customText;
                } else {
                    statusElement.innerHTML = this.$t(this.toTitleCase(status.replaceAll('-', ' ')));
                }

                // Update badge class (fct_status_badge_*)
                statusElement.className = statusElement.className.replace(/fct_status_badge_[\w-]+/g, '');
                statusElement.classList.add('fct_status_badge_' + status);

                // Update parent wrapper class
                const stockWrapper = statusElement.closest('.fct-product-stock');
                if (stockWrapper) {
                    stockWrapper.classList.remove('in-stock', 'out-of-stock');
                    stockWrapper.classList.add(status);
                }
            }

            if (isOutOfStock) {
                this.#addToCartButtons.forEach(button => {
                    this.#updateButtonText(button, window.fluentcart_single_product_vars.out_of_stock_button_text);
                    button.setAttribute('disabled', 'disabled');
                    button.classList.add('out-of-stock');
                    // Always show "Not Available" button, even for subscriptions
                    button.classList.remove('is-hidden');
                });

                // Hide Buy Now button when out of stock
                this.#buyNowButtons.forEach(button => {
                    button.classList.add('is-hidden');
                    button.removeAttribute('href');
                });
            } else {
                this.#addToCartButtons.forEach(button => {
                    this.#updateButtonText(button, window.fluentcart_single_product_vars.cart_button_text);
                    button.classList.remove('out-of-stock');
                    button.removeAttribute('disabled');
                });
                // Show Buy Now button when in stock
                this.#buyNowButtons.forEach(button => {
                    button.classList.remove('is-hidden');
                });
            }
        }

        #setupIncreaseButton() {
            if (!this.#increaseButton) return;

            this.#increaseButton.addEventListener('click', async (event) => {
                event.preventDefault();


                const variantCurrentSelector = document.querySelector(`[data-fluent-cart-product-variant][data-cart-id="${this.#currentlySelectedVariationId}"]`);
                const availableStock = variantCurrentSelector?.dataset.availableStock;

                // Get current quantity, defaulting to 1 if empty
                let quantity = parseInt(this.#quantity.value, 10) || 1;
                let maxAttr = this.#quantity.getAttribute('max');
                let maxQuantity = maxAttr ? parseInt(maxAttr, 10) : 10000;

                // Stop if already at max
                if (quantity >= maxQuantity) {
                    if (window.Toastify) {
                        new Toastify({
                            text: `You can only purchase a maximum of ${maxQuantity} item${maxQuantity > 1 ? 's' : ''}.`,
                            className: "warning",
                            duration: 3000,
                            gravity: "top",
                            position: 'right',
                            slideFrom: "right",
                            type: "warning",
                        }).showToast();
                    }

                    return;
                }

                // Increase quantity, but not above max
                quantity++;

                if (availableStock !== 'unlimited' && quantity > parseInt(availableStock)) {
                    // Using Toastify if available, otherwise could use alert or custom notification
                    if (window.Toastify) {
                        new Toastify({
                            text: 'You have reached the maximum quantity.',
                            className: "warning",
                            duration: 3000,
                            gravity: "top",
                            position: 'right',
                            slideFrom: "right",
                            type: "warning",
                        }).showToast();
                    }
                    return;
                }

                // Update the quantity field with the new value
                this.#quantity.value = quantity;
                this.#quantity.dispatchEvent(new Event('input'));
            });
        }

        #setupDecreaseButton() {
            if (!this.#decreaseButton) return;

            this.#decreaseButton.addEventListener('click', (event) => {
                event.preventDefault();

                // Get current quantity, defaulting to 1 if empty
                let quantity = parseInt(this.#quantity.value, 10) || 1;

                // Decrease quantity, ensuring it doesn't go below 1
                if (quantity > 1) {
                    quantity--;
                }

                // Update the quantity field with the new value
                this.#quantity.value = quantity;
                this.#quantity.dispatchEvent(new Event('input'));
            });
        }

        #setupQuantityInput() {
            if (!this.#quantity) return;

            this.#quantity.addEventListener('input', () => {
                let quantity = parseInt(this.#quantity.value, 10);

                // Ensure the input is a valid number and doesn't exceed 10,000
                if (isNaN(quantity) || quantity < 1) {
                    quantity = 1;
                } else if (quantity > 10000) {
                    quantity = 10000;
                }

                // Update the quantity input field with the validated value
                this.#quantity.value = quantity;
                this.#updateBuyNowButtonUrl(quantity);
                this.#updateAddToCartQuantity(quantity);
            });
        }

        #resetQuantity() {
            if (this.#quantity) {
                this.#quantity.value = 1;
                this.#quantity.dispatchEvent(new Event('input'));
            }
        }

        #setupCartButtons() {
            const actionName = 'single_product_page_cart_updated_' + this.#index;
            this.#addToCartButtons.forEach(button => {
                button.setAttribute('data-action-name', actionName);
                button.setAttribute('data-error-action-name', actionName);
            });

            document.addEventListener(actionName, () => {
                this.#resetQuantity();
            });
        }

        #initiallyHideAddToCartButton() {
            let paymentType = this.#variationButtons[0]?.dataset.paymentType;
            if (paymentType === 'subscription') {
                this.#addToCartButtons.forEach(button => {
                    button.setAttribute('data-cart-id', '');
                });
            }
        }

        #initiallyHideOutOfStockButton() {
            // Check variant-level stock
            let checkStockStatus = window.fluentcart_single_product_vars?.in_stock_status;
            let stockManagement = this.#variationButtons[0]?.dataset.stockManagement;
            let stockStatus = checkStockStatus;
            if (stockManagement === 'yes') {
                stockStatus = this.#variationButtons[0]?.dataset.itemStock;
            }

            // Also check product-level stock from the buy now button's data attribute
            const buyNowStock = this.#buyNowButtons[0]?.dataset.stockAvailability;
            if (buyNowStock === window.fluentcart_single_product_vars.out_of_stock_status) {
                stockStatus = buyNowStock;
            }

            if (stockStatus === window.fluentcart_single_product_vars.out_of_stock_status) {
                this.#updateProductStatus(stockStatus);
            }
        }

        #disabledAddToCartButton() {
            this.#addToCartButtons.forEach(button => {
                button.setAttribute('data-cart-id', '');
            });
        }

        #setupThumbnailControls() {
            this.#thumbnailControls.forEach(control => {
                control.addEventListener('click', (event) => {
                    this.#handleThumbnailChange(control);
                });
            });
        }

        #handleThumbnailChange(control) {
            this.#thumbnailControls.forEach(ctrl => {
                ctrl.classList.remove('active');
                ctrl.setAttribute('aria-pressed', 'false');
            });
            control.classList.add('active');
            control.setAttribute('aria-pressed', 'true');
            this.#setThumbImage(control);
        }

        #setThumbImage(control) {
            const productThumbnail = this.findOneInContainer('[data-fluent-cart-single-product-page-product-thumbnail]');
            if (!productThumbnail) return;

            let thumbnailUrl = control.dataset.url;
            if (thumbnailUrl === undefined) {
                thumbnailUrl = productThumbnail.dataset.defaultImageUrl;
            }

            productThumbnail.setAttribute('src', thumbnailUrl);
        }

    }

    // init - host plugins call this after injecting FluentCart product HTML.
    // Idempotent — sections already wired by an earlier call are skipped.
    FluentCartSingleProduct.init = function (root) {
        (root || document).querySelectorAll(
            '[data-fluent-cart-product-pricing-section]:not([data-fluent-cart-single-product-initialized])'
        ).forEach(container => {
            container.setAttribute('data-fluent-cart-single-product-initialized', '1');
            const index = document.querySelectorAll(
                '[data-fluent-cart-product-pricing-section][data-fluent-cart-single-product-initialized]'
            ).length - 1;
            new FluentCartSingleProduct().init(container, index);
        });
    };

    // reinit - call this when the host replaces our HTML after first init
    // (e.g. Elementor's innerHTML wipe on popup/show drops our listeners).
    // Clears stale init flags, re-binds, and signals ImageGallery to rebind.
    FluentCartSingleProduct.reinit = function (root, source) {
        if (!root) return;
        root.querySelectorAll('[data-fluent-cart-product-pricing-section][data-fluent-cart-single-product-initialized]')
            .forEach(c => c.removeAttribute('data-fluent-cart-single-product-initialized'));
        FluentCartSingleProduct.init(root);
        window.dispatchEvent(new CustomEvent('fluentCartSingleProductModalOpened', {
            detail: { source: source || 'external' }
        }));
    };

    FluentCartSingleProduct.init(document);

    window.FluentCartSingleProduct = FluentCartSingleProduct;
});
