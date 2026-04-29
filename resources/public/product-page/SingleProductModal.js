export default class SingleProductModal {
    #loadingModal = false;
    #baseUrl = window.fluentCartRestVars.rest.url;
    #singleProductViewUrl = '/public/single-product-views';
    #fadeTimeOut = 700;
    #currentModal = null; // Track the currently open modal
    static cachedView = {};
    static cacheThreshold = 10;

    constructor() {

        this.#bindModalOpenerAction();
        this.#bindModalClosingAction();
        this.#bindEscKeyAction();
    }

    #closeButtonPositionSticky() {
        const closeButton = document.querySelector('[data-fluent-cart-shop-app-single-product-modal-close]');
        const parentDiv = document.querySelector('.fluent-cart-shop-app-single-product-modal-body');

        if (closeButton && parentDiv) {
            const updateButtonPosition = () => {
                const parentRect = parentDiv.getBoundingClientRect();
                const scrollTop = parentDiv.scrollTop;

                closeButton.style.right = `${window.innerWidth - parentRect.right + 5}px`;
                closeButton.style.top = `${parentRect.top + 5}px`;
                if (scrollTop >= 100) {
                    closeButton.classList.add('hovered')
                } else {
                    closeButton.classList.remove('hovered')
                }
            };

            // Initial positioning
            updateButtonPosition();

            // Update position when parent scrolls
            parentDiv.addEventListener('scroll', updateButtonPosition);

            // Update position when window is resized or scrolled
            window.addEventListener('resize', updateButtonPosition);
            window.addEventListener('scroll', updateButtonPosition);
        }
    }

    async openModal(productId, element) {
        const ref = this;
        if (ref.#loadingModal) return;
        ref.#loadingModal = true;

        // Add loading class to the clicked element
        //const clickedElement = document.querySelector(`[data-product-id="${productId}"]`);
        if (element) {
            element.classList.add('loading');
        }
        let modalElementParent = document.createElement('div');
        let modalElement = document.createElement('div');
        modalElement.className = 'fluent-cart-shop-app-single-product-modal';
        modalElement.setAttribute('data-fluent-cart-shop-app-single-product-modal', '');

        const overlayElement = document.createElement('div');
        overlayElement.className = 'fluent-cart-shop-app-single-product-modal-overlay';
        overlayElement.setAttribute('data-fluent-cart-shop-app-single-product-modal-overlay', '');
        modalElement.appendChild(overlayElement);

        const modalBodyElement = document.createElement('div');
        modalBodyElement.className = 'fluent-cart-shop-app-single-product-modal-body';

        const loaderElement = document.createElement('div');
        loaderElement.className = 'fct-loader-wrap show';
        loaderElement.setAttribute('data-fluent-cart-cart-drawer-loader', '');
        const loaderSpinWrap = document.createElement('div');
        loaderSpinWrap.className = 'fct-loader-spinner';
        loaderElement.appendChild(loaderSpinWrap);

        modalBodyElement.appendChild(loaderElement);
        modalElement.appendChild(modalBodyElement);
        modalElementParent.style.opacity = '1';
        modalElementParent.style.pointerEvents = 'auto';
        modalElementParent.appendChild(modalElement);
        document.body.appendChild(modalElementParent);

        // Store reference to current modal
        ref.#currentModal = modalElement;

        const view = await ref.#fetchModalView(productId);


        if (view) {
            // Create modal element and append to body
            modalElement.innerHTML = view;
            ref.#currentModal = modalElement;

            // close button position sticky top
            //this.#closeButtonPositionSticky();

            // Fade in animation
            this.#fadeIn(modalElement, ref.#fadeTimeOut);

            ref.#initSingleProductJs(modalElement);
            // add dispatch event listener
            window.dispatchEvent(new CustomEvent('fluentCartSingleProductModalOpened', {
                detail: {
                    productId: productId
                }
            }));
        }

        document.body.style.overflow = 'hidden';
    }

    #bindModalOpenerAction() {
        const ref = this;


        document.addEventListener('click', async function (event) {
            const target = event.target.closest('[data-fluent-cart-single-product-card-view-button]');
            if (!target) return;

            target.classList.add('fct-loading');
            const productId = target.dataset.productId;
            await ref.openModal(productId, target);
            target.classList.remove('fct-loading');
        });
    }

    #bindEscKeyAction() {
        const ref = this;

        document.addEventListener('keydown', function (event) {
            // Check if ESC key is pressed and modal is open
            if (event.key === 'Escape' && ref.#currentModal) {
                const modal = ref.#currentModal.querySelector('[data-fluent-cart-shop-app-single-product-modal]');
                if (modal) {
                    ref.closeModal(ref.#currentModal);
                }
            }
        });
    }

    #initSingleProductJs(view) {
        const containers = view.querySelectorAll('[data-fluent-cart-single-product-page]');
        containers.forEach(container => {
            (new window.FluentCartSingleProduct()).init(container, 0);
        });
    }

    async #fetchModalView(productId) {
        if (SingleProductModal.cachedView[productId]) {
            this.#loadingModal = false;
            return SingleProductModal.cachedView[productId];
        }
        this.#loadingModal = true;

        const {request, controller} = window.fluentCartAjax.get({
            action: 'fluent_cart_checkout_routes',
            fc_checkout_action: 'get_product_modal_view',
            product_id: productId
        }, true);

        const response = await request;
        const view = response.view;

        SingleProductModal.cachedView[productId] = view;
        const keys = Object.keys(SingleProductModal.cachedView);
        if (keys.length > SingleProductModal.cacheThreshold) {
            delete SingleProductModal.cachedView[keys[0]];
        }
        this.#loadingModal = false;

        return view;
    }

    #bindModalClosingAction() {
        const ref = this;
        document.addEventListener('click', function (event) {
            const overlay = document.querySelector('[data-fluent-cart-shop-app-single-product-modal-overlay]');
            const closeButton = document.querySelector('[data-fluent-cart-shop-app-single-product-modal-close]');
            const modal = event.target.closest('[data-fluent-cart-shop-app-single-product-modal]');
            if (overlay) {
                if (overlay.contains(event.target)) {
                    ref.closeModal(modal.parentElement);
                }
            }
            if (closeButton) {
                if (closeButton.contains(event.target)) {
                    ref.closeModal(modal.parentElement);
                }
            }
        });
    }

    closeModal(view) {
        this.#fadeOut(view, this.#fadeTimeOut, () => {
            view.remove();
            document.body.style.overflow = '';
            this.#currentModal = null; // Clear the reference
        });
    }

    // Helper method for fade in animation
    #fadeIn(element, duration) {
        element.style.opacity = '0';
        element.style.transition = `opacity ${duration}ms ease-in-out`;

        // Force reflow
        element.offsetHeight;

        element.style.opacity = '1';
        setTimeout(() => {
            element.style.pointerEvents = 'auto';
        }, duration);
    }

    // Helper method for fade out animation
    #fadeOut(element, duration, callback) {
        element.style.transition = `opacity ${duration}ms ease-in-out`;
        element.style.opacity = '0';

        setTimeout(() => {
            if (callback) callback();
        }, duration);
    }
}
