import Utils from "@/utils/Utils";

document.addEventListener('DOMContentLoaded', () => {

    class SearchBarApp {

        #searchBarApp;
        #searchBar;
        #searchClear;
        #resultContainer;
        #termId;
        #urlMode = 'new-tab';
        #linkWithShopApp = false;

        constructor(searchBarApp) {
            this.#searchBarApp = searchBarApp;
            this.#termId = this.#searchBarApp.querySelector('[data-fluent-cart-search-bar-app-taxonomy]');
            this.#searchBar = this.#searchBarApp.querySelector('[data-fluent-cart-search-bar]');
            this.#searchClear = this.#searchBarApp.querySelector('[data-fluent-cart-search-clear]');
            this.#resultContainer = this.#searchBarApp.querySelector('[data-fluent-cart-search-bar-lists-wrapper]');
            this.#urlMode = this.#searchBarApp.dataset.urlMode || 'new-tab';

            this.init();

            this.#linkWithShopApp = this.#searchBarApp.dataset.linkWithShopApp || false;
        }

        isLinkedWithShopApp() {
            const isShopAppExists = Array.isArray(window.fluentCartShopApps) && window.fluentCartShopApps.length > 0;

            return this.#linkWithShopApp && isShopAppExists;
        }

        init() {
            const debouncedHandleKeyUp = Utils.debounce(this.#handleKeyUp, 300);
            this.#searchBar.addEventListener('keyup', debouncedHandleKeyUp);

            if(this.#termId){
                this.#termId.addEventListener('change', debouncedHandleKeyUp);
            }
            this.#resultContainer.parentElement.style.display = 'none';

            // Show searchClear when searchBar has value
            this.#searchBar.addEventListener('input', () => {
                if (this.#searchBar.value.trim() !== '') {
                    this.#searchClear.style.display = 'block';
                } else {
                    this.#searchClear.style.display = 'none';
                }
            });

            // Handle clear button click event
            this.#searchClear.addEventListener('click', () => {
                this.#searchBar.value = '';
                this.#searchClear.style.display = 'none';

                this.#clearFilterResult();
            });
        }

        #handleKeyUp = (e) => {
            e.preventDefault();
            const searchBar = this.#searchBar;
            const termId = this.#termId?.value;
            const searchParam = searchBar.value;
            this.#fetchData(termId, searchParam);
        }

        #fetchData = (termId, searchParam) => {
            
            this.#searchBar.classList.add('is-active');
            if (this.#linkWithShopApp) {
                this.#resultContainer.parentElement.style.display = 'none';
            } else {
                this.#resultContainer.parentElement.style.display = 'block';
            }

            const restUrl = window.fluentcart_search_bar_vars.rest.url;
            const baseUrl = `${restUrl}/public/product-search/`;

            // check if window.fluentCartShopApps is defined
            if (this.isLinkedWithShopApp()) {
                const params = {};

                if(searchParam){
                    params['wildcard'] = searchParam;
                }else{
                    params['wildcard'] = '';
                }
                if(termId){
                    params['product-categories'] = termId;
                }

                window.fluentCartShopApps[0].applyFilters(params);

                return;
            }

            let url = new URL(baseUrl);
            const params = {
                post_title: searchParam,
                url_mode: this.#urlMode,
                termId: termId || '',
            };

            if (Object.keys(params).length > 0) {
                for (let [key, value] of Object.entries(params)) {
                    url.searchParams.append(key, value);
                }
            }

            const fullUrl = url.href;

            if (!searchParam) {
                this.#clearFilterResult();
                return;
            } else {
                fetch(fullUrl)
                    .then(response => response.json())
                    .then(data => {
                        const viewResult = data.htmlView;
                        this.#resultContainer.innerHTML = viewResult;
                        const h5Element = this.#resultContainer.parentElement.querySelector('h5');
                        if (h5Element) {
                            h5Element.style.display = 'block';
                        }

                        if (viewResult.trim() === '') {
                            this.#resultContainer.innerHTML = '<li class="is-empty">No results found.</li>';
                            if (h5Element) {
                                h5Element.style.display = 'none';
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching data:', error);
                    });
            }
        }

        #clearFilterResult() {
            this.#searchBar.classList.remove('is-active');
            this.#resultContainer.parentElement.style.display = 'none';
            this.#resultContainer.innerHTML = '';
            if (this.#linkWithShopApp) {
                if (typeof window.fluentCartShopApps !== 'undefined' && window.fluentCartShopApps.length > 0) {
                    window.fluentCartShopApps[0].applyFilters({'wildcard': '', 'product-categories': ''});
                }
            }
        }
    }

    // Initialize all search bar apps
    const searchBarApps = document.querySelectorAll('[data-fluent-cart-search-bar-app-wrapper]');
    searchBarApps.forEach(searchBarApp => {
        new SearchBarApp(searchBarApp);
    });

});
