import Filter from '../Filter';

export default class Paginator {
    container;
    filterContainer;
    productView;
    productList;
    productGridParentDiv;
    filter;
    filterForm;
    filters = [];
    #defaultFilters = {};
    #allowOutOfStock = false;

    applyingFilter = false;
    placeholderImage;
    perPage;
    #accessorId;

    loading;
    #baseUrl = window.fluentCartRestVars.rest.url + '/public/product-views';
    //#baseUrl = '/public/product-views';

    paginator = {
        currentPage: 1,
        perPage: 10,
        total: 0,
        lastPage: 0,
        from: 0,
        to: 0,
    };

    #lastTotal = 1;
    usingCursor = false;
    cursor = null;
    priceFormat = 'starts_from';
    orderType = 'DESC';
    liveFilter = false;
    productLoaderElement;

    constructor(container, accessorId, currentPage = 1) {
        this.#accessorId = accessorId;
        this.container = container;

        this.filterContainer = container.querySelector('[data-fluent-cart-shop-app-filter-wrapper]');
        this.productView = container.querySelectorAll('[data-fluent-cart-shop-app-single-product]');
        this.productList = container.querySelector('[data-fluent-cart-shop-app-product-list]');


        this.placeholderImage = container.getAttribute('data-placeholder');
        this.productGridParentDiv = container.querySelector('.fluent-cart-shop-app-products');
        this.perPage = container.getAttribute('data-per-page');
        this.paginator.currentPage = currentPage;

        this.productLoaderElement = container.querySelector('[data-fluent-cart-product-loader]');

        container.removeAttribute('data-per-page');
        this.priceFormat = container.getAttribute('data-price-format') || 'starts_from';
        this.orderType = container.getAttribute('data-order-type') || 'DESC';
        this.liveFilter = !!container.getAttribute('data-live-filter');
        container.removeAttribute('data-price-format');
        container.removeAttribute('data-order-type');

        this.bindFilter();
        this.handleDefaultFilters();

        window.addEventListener("fluentCartNotifyCartDrawerItemChanged", () => this.notifyDataSetChanged());
        this.reRenderAddToCartButton();
        let filters = this.parseDefaultUrlFilters(window.location);
        if(filters.hasOwnProperty('filters')){
            this.setFilters(filters.filters);
        }
    }

     parseDefaultUrlFilters(url) {
        const params = new URL(url).searchParams;
        const result = {};

        for (const [key, value] of params.entries()) {
            // Split key by brackets and dots into segments
            const segments = key.split(/\[|\]/).filter(Boolean);

            let current = result;
            for (let i = 0; i < segments.length; i++) {
                const part = segments[i];

                // Last segment → assign value
                if (i === segments.length - 1) {
                    if (current[part] === undefined) {
                        current[part] = value;
                    } else if (Array.isArray(current[part])) {
                        current[part].push(value);
                    } else {
                        current[part] = [current[part], value];
                    }
                } else {
                    // Create nested object if not exists
                    if (!current[part]) current[part] = {};
                    current = current[part];
                }
            }
        }

        return result;
    }




    handleDefaultFilters() {
        const defaultFilters = this.container.getAttribute('data-default-filters');
        if (defaultFilters) {
            const parsed = JSON.parse(defaultFilters);
            if (parsed.enabled) {
                // delete parsed.enabled;
                this.#defaultFilters = {...parsed};
            }
            this.#allowOutOfStock = this.#defaultFilters.allow_out_of_stock === true;
            delete this.#defaultFilters.allow_out_of_stock;
            this.container.removeAttribute('data-default-filters');
        }
    }

    bindFilter() {
        this.filterForm = this.container.querySelector('form[data-fluent-cart-product-filter-form]');

        if (!this.filterForm || this.filterForm.nodeName !== 'FORM') {
            return;
        }
        this.filter = new Filter(
            this.filterForm,
            this.#accessorId,
            this.container,
            this.filterContainer,
            this.liveFilter,
            this
        );
    }

    setFilters(filters) {
        this.filters = filters;
    }

    onFilterApplied() {
        this.showProductLoader();
        this.beforeFiltering();
        this.addSkeletonLoader();
        this.loadData(true).then(() => {
            this.productList.scrollIntoView({behavior: 'smooth'});
            this.hideProductLoader();
        });
    }

    beforeFiltering() {
        this.paginator.currentPage = 0;
        this.applyingFilter = true;
    }

    appendParams(searchParams, key, value) {
        if (Array.isArray(value)) {
            value.forEach(item => {
                searchParams.append(`${key}[]`, item);
            });
        } else if (typeof value === 'object' && value !== null) {
            Object.keys(value).forEach(subKey => {
                this.appendParams(searchParams, `${key}[${subKey}]`, value[subKey]);
            });
        } else {
            searchParams.append(key, value);
        }
    }

    getFilterState() {
        return this.filters;
    }

    loadData(clearContainerAfterDataLoaded = false) {
        const noResultElement = this.container.querySelector('[data-fluent-cart-shop-no-result-found]');
        return new Promise((resolve, reject) => {
            if (!this.shouldLoadData() && !this.applyingFilter) {
                resolve('');
                return;
            }

            this.applyingFilter = false;
            this.loading = true;
            this.onLoadingData();
            const params = this.getQueryParams();

            params['with'] = [
                ...['licensesMeta', 'detail', 'variants', 'categories'],
                ...params['with'] ?? []
            ];

            const query = {
                ...params,
                filters: this.filters,
                default_filters: {
                    ...this.#defaultFilters
                },
                per_page: this.paginator.perPage || 10,
                price_format: this.priceFormat,
                order_type: this.orderType,
                template_provider: ''
            };

            const firstElement = this.container.querySelector("[data-fct-product-card]");
            if (firstElement) {
                const clientId = firstElement.getAttribute('data-fluent-client-id');
                const templateProvider = firstElement.getAttribute('data-template-provider');
                if (clientId) {
                    query.client_id = clientId;
                }
                if (templateProvider) {
                    query.template_provider = templateProvider;
                }
            }

            if (this.#allowOutOfStock) {
                query['allow_out_of_stock'] = true;
            }


            const url = new URL(this.#baseUrl);

            Object.keys(query).forEach(key => {
                this.appendParams(url.searchParams, key, query[key]);
            });


            fetch(url)
                .then(res => res.json())
                .then(response => {
                    const views = response.products.views;
                    const isHidden = true;
                    // if (noResultElement) {
                    //     noResultElement.classList.contains('hide');
                    // }
                    if (views.length > 0) {
                        this.paginator = {
                            currentPage: response.products.current_page,
                            perPage: response.products.per_page,
                            next_cursor: response.products.next_cursor,
                            total: response.products.total,
                            lastPage: response.products.last_page,
                            from: response.products.from,
                            to: response.products.current_page,
                        };
                        if (isHidden) {
                            if (noResultElement) {
                                setTimeout(() => {
                                    // noResultElement.classList.add('hide');
                                }, 200);
                            }
                        }
                    } else {
                        if (isHidden) {
                            if (noResultElement) {
                                setTimeout(() => {
                                    // noResultElement.classList.remove('hide');
                                }, 200);
                            }
                        }
                    }

                    if (clearContainerAfterDataLoaded) {
                        this.clearContainer();
                    }
                    this.onDataLoaded(response, views);
                    resolve(true);
                })
                .catch(errors => {
                    this.onDataLoadError(errors);
                    reject(errors);
                })
                .finally(() => {
                    this.onApiCallFinished();
                });
        });
    }

    addSkeletonLoader(event = null) {
        return;
        if (event !== "onChangePageSize" && event !== "onPageScroll") {
            const items = this.productGridParentDiv.querySelectorAll('.fluent-cart-shop-app-product-single-col .fluent-cart-shop-app-single-product');
            items.forEach(item => {
                const loader = document.createElement('div');
                loader.className = 'fluent-cart-shop-app-product-skeleton-loader';
                item.appendChild(loader);
            });
        } else {
            const loaderContainer = document.createElement('div');
            loaderContainer.className = 'scroll-loader-container';

            const loaderSpan = document.createElement('span');
            loaderSpan.className = 'scroll-loader';
            loaderContainer.appendChild(loaderSpan);

            this.productGridParentDiv.appendChild(loaderContainer);
        }
    }

    notifyDataSetChanged() {
        this.reRenderAddToCartButton();
    }

    renderNewProducts(views, clearContainer = false) {
        this.#lastTotal = this.paginator.total;

        if (clearContainer) {
            this.clearContainer();
        }

        const isInEmptyState = this.productList.querySelector('[data-fluent-cart-shop-app-no-products]');
        if (isInEmptyState) {
            this.clearContainer();
        }

        const noResultElement = this.container.querySelector('[data-fluent-cart-shop-no-result-found]');

        // check if 'fct-empty-el' class found in views
        if (views.includes('fluent-cart-shop-no-result-found')) {
            if (noResultElement) {
                noResultElement.outerHTML = views;
                setTimeout(() => {
                    noResultElement.classList.remove('hide');
                }, 300);
            } else {
                this.productList.insertAdjacentHTML('beforebegin', views);
            }
        } else {
            this.productList.insertAdjacentHTML('beforeend', views);
        }

        // this.productList.innerHTML = views;

        this.reRenderAddToCartButton();
    }

    reRenderAddToCartButton() {
        if (!window.fluentCartCart) return;

        const cartData = window.fluentCartCart.getState();
        document.querySelectorAll('[data-cart-item-count]').forEach(el => {
            el.classList.add('is-cart-count-hidden');
        });

        Object.keys(cartData).forEach(itemId => {
            const objectId = cartData[itemId].object_id;
            const buttons = document.querySelectorAll(`[data-fluent-cart-shop-app-add-to-cart-button][data-product-id="${objectId}"]`);
            buttons.forEach(button => {
                const itemCountWrapper = button.querySelector('[data-cart-item-count]');
                if (itemCountWrapper) {
                    itemCountWrapper.classList.remove('is-cart-count-hidden');
                    itemCountWrapper.textContent = cartData[itemId].quantity;
                }
            });
        });
    }

    clearContainer() {
        this.productList.innerHTML = '';
    }

    // Abstract methods
    getQueryParams() {
        throw new Error("You should override `getQueryParams` method...");
    }

    onLoadingData() {
        // Optional: show spinner, etc.
    }

    onDataLoaded(response) {
        throw new Error("You should override `onDataLoaded` method...");
    }

    onDataLoadError(errors) {
        throw new Error("You should override `onDataLoadError` method...");
    }

    onApiCallFinished() {
        throw new Error("You should override `onApiCallFinished` method...");
    }

    shouldLoadData() {
        throw new Error("You should override `shouldLoadData` method...");
    }

    showProductLoader() {
        if(this.productList) {
            this.productList.classList.add('product-list-hide');
        }

        const emptyState = this.container.querySelector('[data-fluent-cart-shop-no-result-found]');
        if (emptyState) {
            emptyState.classList.add('hide');
        }

        if (this.productLoaderElement) {
            this.productLoaderElement.classList.remove('loader-hidden');
        }
    }

    hideProductLoader() {
        if(this.productList) {
            this.productList.classList.remove('product-list-hide');
        }

        if (this.productLoaderElement) {
            this.productLoaderElement.classList.add('loader-hidden');
        }
    }
}
