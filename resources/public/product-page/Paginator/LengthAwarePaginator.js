import Paginator from './Paginator';

export default class LengthAwarePaginator extends Paginator {

    #listener;
    #products = {};
    #paginatorInfo = {};

    paginatorWrapper;
    paginatorPageSelector;
    paginatorItem;

    paginationFrom;
    paginationTo;
    paginationTotal;
    #initiallyLoaded = false;
    paginationJump = 5;

    constructor(element, initialData, listener, accessorId, perPage = 10) {
        let currentPage = 1;
        const url = new URL(window.location.href);
        const searchParams = url.searchParams;
        if (searchParams.has('current_page')) {
            currentPage = !isNaN(searchParams.get('current_page')) ? searchParams.get('current_page') : 1;
        }

        super(element, accessorId, currentPage);
        this.container = element.closest('[data-fluent-cart-product-wrapper]');
        // this.container = element;
        this.#products = initialData;
        this.#listener = listener;
        this.paginator.perPage = perPage;

        this.#init();
        this.#attachListeners();
    }

    #init() {
        this.paginatorWrapper = this.container.querySelector('[data-fluent-cart-shop-app-paginator-items-wrapper]');
        this.paginatorItem = this.container.querySelector('[data-fluent-cart-shop-app-paginator-item]');
        this.paginatorPageSelector = this.container.querySelector('[data-fluent-cart-shop-app-paginator-per-page-selector]');
        this.paginationFrom = this.container.querySelector('[data-fluent-cart-shop-app-paginator-info-pagination-from]');
        this.paginationTo = this.container.querySelector('[data-fluent-cart-shop-app-paginator-info-pagination-to]');
        this.paginationTotal = this.container.querySelector('[data-fluent-cart-shop-app-paginator-info-pagination-total]');

        if (this.productView && this.productList) {
            // Handle if elements are arrays (from jQuery conversion)
            if (Array.isArray(this.productList)) {
                this.productList = this.productList[0];
                this.productView = this.productView[0];
                this.paginatorWrapper = this.paginatorWrapper[0];
                this.paginatorItem = this.paginatorItem[0];
                this.paginatorPageSelector = this.paginatorPageSelector[0];
            }
            // this.loadData();
        } else {
            this.productView = null;
            this.productList = null;
            this.paginatorWrapper = null;
            this.paginatorItem = null;
        }
    }

    #attachListeners() {
        const ref = this;

        // Event delegation for pagination items
        if (this.paginatorWrapper) {
            this.paginatorWrapper.addEventListener('click', function (event) {
                const target = event.target.closest('[data-fluent-cart-shop-app-paginator-item]');
                if (target) {
                    event.preventDefault();
                    const page = target.getAttribute('data-page');
                    ref.#gotoPage(page);
                }
            });
        }

        // Page size selector change event
        if (this.paginatorPageSelector) {
            this.paginatorPageSelector.addEventListener('change', function () {
                const pageSize = this.value;
                // Update all page selectors if there are multiple
                const allSelectors = ref.container.querySelectorAll('[data-fluent-cart-shop-app-paginator-per-page-selector]');
                allSelectors.forEach(selector => {
                    selector.value = pageSize;
                });
                ref.#pageChangePageSize(pageSize);
            });
        }
    }

    getQueryParams() {
        return {
            current_page: this.paginator.currentPage,
            per_page: this.paginator.perPage,
            search: ''
        };
    }

    onDataLoaded(response, views) {
        const noResultElement = this.container.querySelector('[data-fluent-cart-shop-no-result-found]');

        this.#initiallyLoaded = true;
        this.#paginatorInfo = {...response.products};
        delete this.#paginatorInfo.data;

        if (typeof this.#listener.onProductFetched === "function") {
            this.#listener.onProductFetched(views);
            if (this.productList) {
                const top = this.productList.getBoundingClientRect().top + window.scrollY;
                window.scrollTo({
                    top: top - 50,  // 50px padding
                    behavior: "smooth"
                });
            }
        }

        if (views.length > 0) {
            this.renderNewProducts(views, true);
            this.#renderPaginator();
            if (noResultElement) {
                setTimeout(() => {
                    noResultElement.classList.add('hide');
                }, 200);
            }
        } else {
            if (noResultElement) {
                setTimeout(() => {
                    noResultElement.classList.remove('hide');
                }, 200);
            }
        }
    }

    onDataLoadError(errors) {
        console.log(errors);
    }

    onApiCallFinished() {
        this.loading = false;
    }

    shouldLoadData() {
        return (this.productView !== null || !this.loading);
    }

    #renderPaginator() {
        if (!this.paginator || !this.paginatorWrapper) return;

        const totalPages = this.paginator.lastPage;
        const currentPage = parseInt(this.paginator.currentPage);
        const jumpSize = this.paginationJump || 3; // Default jump size = 3

        const createButton = (label, page, isActive = false, isArrow = false) => {
            // Clone a clean paginator item
            const btn = this.paginatorItem.cloneNode(true);

            // Always reset first
            btn.classList.remove('active', 'arrow');

            btn.innerHTML = label;
            btn.setAttribute('data-page', page);

            if (isActive) btn.classList.add('active');
            if (isArrow) btn.classList.add('arrow');

            return btn;
        };

        const svgLeft = `<svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M12.9168 15.8333L7.7906 10.7071C7.40008 10.3166 7.40008 9.68342 7.7906 9.29289L12.9168 4.16667" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>`;
        const svgRight = `<svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M7.91667 4.16666L13.0429 9.29289C13.4334 9.68341 13.4334 10.3166 13.0429 10.7071L7.91667 15.8333" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>`;

        // Clear wrapper
        this.paginatorWrapper.innerHTML = '';

        // Update pagination info
        if (this.paginationFrom) this.paginationFrom.textContent = this.#paginatorInfo.from;
        if (this.paginationTo) this.paginationTo.textContent = this.#paginatorInfo.to;
        if (this.paginationTotal) this.paginationTotal.textContent = this.#paginatorInfo.total;

        const wrapper = this.paginatorWrapper;

        // ← Previous button
        if (currentPage > 1) {
            wrapper.appendChild(createButton(svgLeft, currentPage - 1, false, true));
        }

        // First page (1)
        wrapper.appendChild(createButton('1', 1, currentPage === 1));

        // « Jump back button
        if (currentPage > jumpSize + 1) {
            const jumpBack = Math.max(2, currentPage - jumpSize);
            wrapper.appendChild(createButton('«', jumpBack, false, true));
        }

        // Pages before current
        for (let i = currentPage - 2; i < currentPage; i++) {
            if (i > 1) {
                wrapper.appendChild(createButton(i, i));
            }
        }

        // Current page (if not first or last)
        if (currentPage !== 1 && currentPage !== totalPages) {
            wrapper.appendChild(createButton(currentPage, currentPage, true));
        }

        // Pages after current
        for (let i = currentPage + 1; i <= currentPage + 2; i++) {
            if (i < totalPages) {
                wrapper.appendChild(createButton(i, i));
            }
        }

        // » Jump forward button
        if (currentPage < totalPages - jumpSize) {
            const jumpForward = Math.min(totalPages - 1, currentPage + jumpSize);
            wrapper.appendChild(createButton('»', jumpForward, false, true));
        }

        // Last page
        if (totalPages > 1) {
            wrapper.appendChild(createButton(totalPages, totalPages, currentPage === totalPages));
        }

        // → Next button
        if (currentPage < totalPages) {
            wrapper.appendChild(createButton(svgRight, currentPage + 1, false, true));
        }
    }

    #gotoPage(pageNumber) {
        if (this.productView === null || this.loading) return;
        this.paginator.currentPage = pageNumber;
        const url = new URL(window.location.href);
        const searchParams = url.searchParams;
        searchParams.delete('current_page');
        searchParams.append('current_page', pageNumber);
        const newUrl = `${url.origin}${url.pathname}?${searchParams.toString()}${url.hash}`;
        history.pushState({path: newUrl}, '', newUrl);

        this.addSkeletonLoader();
        this.loadData();
    }

    #pageChangePageSize(size) {
        if (this.productView === null || this.loading) return;
        this.paginator.perPage = size;
        this.paginator.currentPage = 1;
        this.#gotoPage(this.paginator.currentPage);
    }

    #mergeProducts(products) {
        Object.entries(products).forEach(([key, product]) => {
            this.#products[product.ID] = product;
        });
    }
}
