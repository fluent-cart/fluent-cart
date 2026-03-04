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
            this.#setupVariationButtons();

            this.#setup();

            this.#initTabOnDemand();
            this.#setMobileViewClass();
            this.#listenWindowResize();


            FluentCartSingleProduct.#instance = this;

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

            this.#resetQuantity();
            const variationId = button.dataset.cartId;

            // get parent data-fluent-cart-product-pricing-section
            const pricingSection = button.closest('[data-fluent-cart-product-pricing-section]');
            // get data-fluent-cart-product-payment-type from pricingSection and add class is-hidden to all
            const paymentTypes = pricingSection.querySelectorAll('[data-fluent-cart-product-payment-type]');
            const itemPrices = pricingSection.querySelectorAll('[data-fluent-cart-product-item-price]');
            if (paymentTypes) {
                //paymentTypes.forEach(type => type.classList.add('is-hidden'));
            }
            if (itemPrices) {
                //itemPrices.forEach(type => type.classList.add('is-hidden'));
            }

            const variationContent = pricingSection.querySelectorAll('.fluent-cart-product-variation-content[data-variation-id]');

            if (variationContent) {
                variationContent.forEach(type => type.classList.add('is-hidden'));
            }

            const selectedVariationContent = pricingSection.querySelectorAll(
                `.fluent-cart-product-variation-content[data-variation-id="${variationId}"]`
            );

            if (selectedVariationContent) {
                selectedVariationContent.forEach(type => type.classList.remove('is-hidden'));
            }
            


            this.#currentlySelectedVariationId = variationId;
            // const status = button.dataset.itemStock;

            let checkStockStatus = window.fluentcart_single_product_vars?.in_stock_status;
            let stockManagement = button?.dataset.stockManagement;
            let status = checkStockStatus;
            if (stockManagement === 'yes') {
                status = button?.dataset.itemStock;
            }

            if (variationId !== undefined) {
                this.#thumbnailControls.forEach(control => control.classList.remove('active'));
                this.#addToCartButtons.forEach(button => {
                    button.setAttribute('data-cart-id', variationId);
                });

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

            // For in-stock subscriptions, hide add-to-cart (subscriptions use Buy Now only)
            const outOfStockSt = window.fluentcart_single_product_vars.out_of_stock_status;
            if (button.dataset.paymentType === 'subscription' && status !== outOfStockSt) {
                this.#addToCartButtons.forEach(btn => btn.classList.add('is-hidden'));
            }

            // Update SKU on variation change
            const skuValue = button.dataset.sku || '';
            const productScope = this.#container.closest('.fct-product-summary') || this.#container.closest('.product-info-block-wrapper') || this.#container.parentElement;
            const skuElement = productScope?.querySelector('[data-fluent-cart-product-sku]');
            if (skuElement) {
                skuElement.textContent = skuValue;
                const skuWrapper = skuElement.closest('.fct-product-sku');
                if (skuWrapper) {
                    skuWrapper.style.display = skuValue ? '' : 'none';
                }
            }

            button.classList.add('selected');

            // Update aria-label on Buy Now and Add To Cart buttons with variant name
            const variantName = button.getAttribute('aria-label') || '';
            if (variantName) {
                this.#buyNowButtons.forEach(btn => {
                    const baseText = btn.textContent.trim();
                    btn.setAttribute('aria-label', baseText + ' - ' + variantName);
                });
                this.#addToCartButtons.forEach(btn => {
                    const textEl = btn.querySelector('.text');
                    const baseText = textEl ? textEl.textContent.trim() : btn.textContent.trim();
                    btn.setAttribute('aria-label', baseText + ' - ' + variantName);
                });
            }

            // Update variant price info
            const priceInfoElements2 = this.findInContainer('[data-fluent-cart-single-product-page-product-variant-price-info]');
            if (priceInfoElements2) {
                priceInfoElements2.forEach(el => el.classList.remove('selected'));
            }
            const selectedPriceInfo2 = this.findOneInContainer(`[data-fluent-cart-single-product-page-product-variant-price-info][data-cart-id="${variationId}"]`);
            if (selectedPriceInfo2) {
                selectedPriceInfo2?.classList.add('selected');
            }

            // Update quantity elements
            const quantityElements2 = this.findInContainer(`[data-fluent-cart-single-product-page-product-quantity][data-cart-id="${variationId}"]`);
            if (quantityElements2) {
                quantityElements2.forEach(el => el.classList.add('selected'));
            }


            window.dispatchEvent(new CustomEvent('fluentCartSingleProductVariationChanged', {
                detail: {
                    productId: this.#productId,
                    variationId: variationId
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

        #updateProductStatus(status) {

            if (!status) return;
            const outOfStockStatus = window.fluentcart_single_product_vars.out_of_stock_status;
            const isOutOfStock = status === outOfStockStatus;

            // Update stock badge text and classes
            // Stock badge is outside the pricing section container, so search in the broader product scope
            const productScope = this.#container.closest('.fct-product-summary') || this.#container.closest('.product-info-block-wrapper') || this.#container.parentElement;
            const statusElement = productScope?.querySelector("[data-fluent-cart-product-stock]");
            if (statusElement) {
                statusElement.innerHTML = this.$t(this.toTitleCase(status.replaceAll('-', ' ')));

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
                    const textEl = button.querySelector('.text');
                    if (textEl) {
                        textEl.textContent = window.fluentcart_single_product_vars.out_of_stock_button_text;
                    }
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
                    const textEl = button.querySelector('.text');
                    if (textEl) {
                        textEl.textContent = window.fluentcart_single_product_vars.cart_button_text;
                    }
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

    // Initialize all single product pages
    document.querySelectorAll('[data-fluent-cart-product-pricing-section]').forEach((container, index) => {
        new FluentCartSingleProduct().init(container, index);
    });

    window.addEventListener('fluentCartSingleProductModalOpened', function (event) {
        document.querySelectorAll('[data-fluent-cart-product-pricing-section]').forEach((container, index) => {
            // new FluentCartSingleProduct().init(container, index);
        });
    });

    window.FluentCartSingleProduct = FluentCartSingleProduct;
});
