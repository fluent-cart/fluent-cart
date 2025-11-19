import Paginator from './Paginator'
export default class ScrollPaginator extends Paginator {

    #listener;
    #products = {}

    #timeoutThreshold = 500
    #maybeHasMoreData = true
    #maybeHasMoreDataTimeoutThreshold = (1000 * 60) * 5 // 5 minutes

    #page = 2 // Start From 2 as we have initial data
    #perPage;

    #observer = null;
    cursor;
    templateProvider;
    lastCursor = null;

    constructor(element, initialData, listener, accessorId, perPage = 10) {
        super(element, accessorId);
        this.container = element;
        this.#products = initialData;
        this.#listener = listener;
        this.paginator.perPage = perPage;
        this.#init();
        this.#setupCursor();
        this.#setupObserver();
    }

    beforeFiltering() {
        this.paginator.currentPage = 0;
        this.applyingFilter = true;
        this.cursor = null;
        this.templateProvider = null;
    }

    #setupCursor() {
        const firstElement = this.container.querySelector("[data-fct-product-card]");
        if (firstElement) {
            const dataCursor = firstElement.getAttribute('data-fluent-cart-cursor');
            const dataTemplateProvider = firstElement.getAttribute('data-template-provider');
            if (dataCursor) {
                this.cursor = dataCursor;
            }

            if (dataTemplateProvider) {
                this.templateProvider = dataTemplateProvider;
            }
        }
        this.lastCursor = this.cursor;
        if (this.cursor) {
            this.usingCursor = true;
        }
    }

    #init() {
        this.placeholderImage = this.container.getAttribute('data-placeholder');
        if (typeof this.productView === 'object' && typeof this.productList === 'object') {
            if (Array.isArray(this.productList)) {
                this.productList = this.productList[0];
            }
            if (Array.isArray(this.productView)) {
                this.productView = this.productView[0];
            }
        } else {
            this.productView = null;
            this.productList = null;
        }
    }

    getQueryParams() {
        if (this.usingCursor) {
            return {
                template_provider: this.templateProvider,
                cursor: this.cursor,
                per_page: this.paginator.perPage,
                paginate_using: 'cursor',
                search: ''
            }
        }
        return {
            template_provider: this.templateProvider,
            current_page: this.paginator.currentPage,
            per_page: this.paginator.perPage,
            search: ''
        }
    }

    parseCursorFromResponse(response) {
        // Parse into a document
        const parser = new DOMParser();
        const doc = parser.parseFromString(response.products.views, "text/html");

        // Get first element
        const firstElement = doc.querySelector("[data-fct-product-card]");

        if (firstElement) {
            const cursor = firstElement.getAttribute('data-fluent-cart-cursor');
            const templateProvider = firstElement.getAttribute('data-template-provider');
            if (templateProvider) {
                this.templateProvider = templateProvider;
            }
            if (cursor) {
                return cursor;
            }
        }
        return null;
    }

    onDataLoaded(response, views) {
        const noResultElement = this.container.querySelector('[data-fluent-cart-shop-no-result-found]');
        this.lastCursor = this.cursor;
        this.cursor = this.parseCursorFromResponse(response);

        if (typeof this.#listener.onProductFetched === "function") {
            this.#listener.onProductFetched(views)
        }

        if (views.length > 0) {
            this.#maybeHasMoreData = true;
            this.renderNewProducts(views)
            this.#observeLastItem(); // Observe the new last item
            if (noResultElement) {
                setTimeout(() => {
                    noResultElement.classList.add('hide');
                }, 200);
            }
        } else {
            this.#maybeHasMoreData = false;
            setTimeout(() => {
                this.#maybeHasMoreData = true
            }, this.#maybeHasMoreDataTimeoutThreshold);

            if (response?.products?.current_page <= response?.products?.last_page) {
                if (noResultElement) {
                    setTimeout(() => {
                        noResultElement.classList.remove('hide');
                    }, 200);
                }
                this.#maybeHasMoreData = false;
            }
        }

        const scrollLoaderContainer = this.container.querySelector('.fluent-cart-shop-app .scroll-loader-container');
        if (scrollLoaderContainer) {
            scrollLoaderContainer.remove();
        }
    }

    isCursorValidated() {
        if (!this.usingCursor) return true;
        return this.cursor !== undefined && this.cursor !== null && this.cursor.length > 0;
    }

    onDataLoadError(errors) {
        console.error(errors);
    }

    onApiCallFinished() {
        setTimeout(() => {
            this.loading = false;
            const loaderContainer = document.querySelector('.loader-container');
            if (loaderContainer) loaderContainer.remove();
        }, this.#timeoutThreshold)
    }

    shouldLoadData() {
        return this.isCursorValidated() && this.productView !== null && !this.loading && this.#maybeHasMoreData;
    }

    loadNextPage(clearContainerAfterDataLoaded = false) {
        this.paginator.currentPage += 1;
        this.addSkeletonLoader("onPageScroll")
        this.loadData(clearContainerAfterDataLoaded)
    }

    #setupObserver() {
        if (this.productView === null) return;

        const options = {
            root: null,
            rootMargin: '0px',
            threshold: 1.0
        };

        this.#observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting && this.shouldLoadData()) {
                    this.loadNextPage();
                }
            });
        }, options);

        this.#observeLastItem();
    }

    #observeLastItem() {
        if (!this.#observer || !this.productList) return;

        const children = this.productList.children;
        const lastItem = children[children.length - 1];

        if (lastItem) {
            this.#observer.disconnect(); // Prevent duplicate observation
            this.#observer.observe(lastItem);
        }
    }

    destroy() {
        if (this.#observer) {
            this.#observer.disconnect();
        }
    }
}
