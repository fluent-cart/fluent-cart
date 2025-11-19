import ScrollPaginator from './Paginator/ScrollPaginator.js';
import LengthAwarePaginator from './Paginator/LengthAwarePaginator.js';

document.addEventListener('DOMContentLoaded', () => {
    class FluentCartShopApp {
        static #instance = null;

        #gridViewToggle;
        #listViewToggle;
        #productWrapper;
        #products = {};
        #fluentCartCart = window.fluentCartCart;
        #cartDrawerToggleClass = 'open';
        #cartDrawerOverlayActiveClass = 'active';
        #paginator;

        init(wrapper) {
            if (FluentCartShopApp.#instance !== null) {
                //return FluentCartShopApp.#instance;
            }

            this.#setupInitialLayout(wrapper);
            this.#bindActionToLayoutToggleButton();
            this.#prefetchProduct();
            this.#bindPaginator();


            FluentCartShopApp.#instance = this;
            return this;
        }

        #setupInitialLayout(wrapper) {
            this.#productWrapper = wrapper;

            const productWrapperElement = wrapper.closest('[data-fluent-cart-product-wrapper]');
            this.#gridViewToggle = productWrapperElement.querySelector('[data-fluent-cart-shop-app-grid-view-button]');
            this.#listViewToggle = productWrapperElement.querySelector('[data-fluent-cart-shop-app-list-view-button]');

            if (window.fluentcart_shop_vars?.is_admin_bar_showing && this.#productWrapper) {
                this.#productWrapper.classList.add('admin_bar_enabled');
            }
        }

        #bindActionToLayoutToggleButton() {

            if (!this.#listViewToggle || !this.#gridViewToggle) return;

            this.#listViewToggle.addEventListener('click', () => {
                this.#productWrapper.classList.add('mode-list');
                this.#productWrapper.classList.remove('mode-grid');
                this.#listViewToggle.classList.add('active');
                this.#gridViewToggle.classList.remove('active');
            });

            this.#gridViewToggle.addEventListener('click', () => {
                this.#productWrapper.classList.remove('mode-list');
                this.#gridViewToggle.classList.add('active');
                this.#listViewToggle.classList.remove('active');
            });
        }

        #prefetchProduct() {
            this.#productWrapper.removeAttribute('data-products');
        }

        #bindPaginator() {
            const perPage = parseInt(this.#productWrapper.dataset.perPage);
            const priceFormat = this.#productWrapper.dataset.priceFormat;
            let paginator = this.#productWrapper.dataset.paginator;
            if (paginator === 'numbers') {
                this.#paginator = new LengthAwarePaginator(this.#productWrapper, this.#products, this, 0, perPage);
            } else {
                this.#paginator = new ScrollPaginator(this.#productWrapper, this.#products, this, 0, perPage);
            }
        }

        getCurrentFilterState(){
            return this.#paginator.getFilterState();
        }

        applyFilters(filters) {
            this.#paginator.setFilters(filters);
            this.#paginator.onFilterApplied();
        }

        onProductFetched(views) {
            // Hook for external use if needed
        }
    }

    window.fluentCartShopApps = [];
    document.querySelectorAll('[data-fluent-cart-product-wrapper-inner]').forEach(function (el) {
        const app = new FluentCartShopApp().init(el)
        window.fluentCartShopApps.push(app);
    });

});
