export default class Filter {
    filterForm;
    filterListener;
    #accessorId;
    filterContainer;
    appContainer;
    sidebarFilterItemCollapse;
    liveFilter;
    shopAppWrapper;
    activeSortBy;

    constructor(
        filterForm,
        accessorId,
        appContainer,
        filterContainer,
        liveFilter,
        filterListener,
    ) {
        this.filterForm = filterForm;
        this.filterListener = filterListener;
        this.#accessorId = accessorId;
        // get data-fluent-cart-product-wrapper parent of appContainer
        this.appContainer = appContainer.closest('[data-fluent-cart-product-wrapper]');
        // this.appContainer = appContainer;
        this.filterContainer = filterContainer;
        this.liveFilter = liveFilter;
        this.isReseting = false;
        this.debouncedFilterApply = null;
        this.searchClear = null;
        this.shopAppWrapper = this.appContainer;
        this.activeSortBy = this.shopAppWrapper.querySelector('[data-sort-by]')?.getAttribute('data-sort-by') || 'name-asc';


        if (typeof this.filterForm === 'undefined' ||  this.filterForm === null){
            //return;
        }


        this.searchClear = this.filterContainer.querySelector('[data-fluent-cart-search-clear]');



        this.filterForm.addEventListener('submit', (event) => {
            event.preventDefault();
            this.applyFilter();
        });

        this.setupResetButton();
        this.prepareFilterContainer();
        this.handleCollapsibleFilter();
        this.handleSidebarItemCollapsible();
        this.loadSlider();
        this.listenForResize();
        this.listenForFilterValueChange();
        this.handleSorting();

        this.debouncedFilterApply = this.debounce(() => {
            if (this.liveFilter) {
                this.applyFilter();
            }
        }, 300);

        let productContainer = this.appContainer.querySelector('[data-fluent-cart-product-wrapper-inner]');
        if (this.appContainer.offsetWidth >= 767) {
            this.appContainer.querySelector('[data-fluent-cart-shop-app-filter-wrapper]').classList.remove('is-collapsed');
            if (this.appContainer.querySelector('[data-fluent-cart-shop-app-filter-toggle-button]')) {
                this.appContainer.querySelector('[data-fluent-cart-shop-app-filter-toggle-button]').classList.add('hide');
            }
            if ( productContainer ) {
                productContainer.classList.remove('fct-full-container-width');
            }
        } else {
            if (this.appContainer.querySelector('[data-fluent-cart-shop-app-filter-toggle-button]')) {
                this.appContainer.querySelector('[data-fluent-cart-shop-app-filter-toggle-button]').classList.remove('hide');
            }
            this.appContainer.querySelector('[data-fluent-cart-shop-app-filter-wrapper]').classList.add('is-collapsed');
            if ( productContainer ) {
                productContainer.classList.add('fct-full-container-width');
            }
        }

    }

    setActiveSortOption = () => {

        this.shopAppWrapper.querySelectorAll('[data-sort-item]')
            .forEach(item => item.classList.remove('selected'));

        const activeInput = this.shopAppWrapper.querySelector(`input[name="sort_by"][value="${this.activeSortBy}"]`);

        if (activeInput) {
            activeInput.checked = true; // sync radio input
            activeInput.closest('[data-sort-item]').classList.add('selected');
        }
    };


    handleSorting() {
        // run once on page load
        this.setActiveSortOption();

        const sortToggle   = this.shopAppWrapper.querySelector('[data-sort-toggle]');
        const sortDropdown = this.shopAppWrapper.querySelector('[data-sort-dropdown]');
        const radioInputs = this.shopAppWrapper.querySelectorAll('input[name="sort_by"]');

        if (!sortToggle) return;
        sortToggle.addEventListener('click', () => {
            sortToggle.classList.toggle('active');
            sortDropdown.classList.toggle('active');
        });

        radioInputs.forEach(input => {
            input.addEventListener('change', () => {

                this.activeSortBy = input.value; // update internal state

                this.setActiveSortOption();

                let data = this.getState();

                this.appendSearchParamToUrl(data);

                this.showLoading();

                this.applyFilter();

                setTimeout(() => {
                    this.hideLoading();
                }, 500);

            });
        });


        // close dropdown if clicking outside
        document.addEventListener('click', (e) => {
            if(sortDropdown){
                if (!sortDropdown.contains(e.target) && !sortToggle.contains(e.target)) {
                    sortDropdown.classList.remove('active');
                    sortToggle.classList.remove('active');
                }
            }
        });

    }

    showLoading() {
        const loadingOverlay = this.shopAppWrapper.querySelector('[data-shop-loading]');
        if (loadingOverlay) {
            loadingOverlay.classList.add('active');
        }
    }

    hideLoading() {
        const loadingOverlay = this.shopAppWrapper.querySelector('[data-shop-loading]');
        if (loadingOverlay) {
            loadingOverlay.classList.remove('active');
        }
    }

    setupResetButton() {


        const resetButtons = this.filterForm.querySelectorAll('input[type="reset"], button[type="reset"]');
        resetButtons.forEach(button => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                this.resetFilterData();
            });
        });

        if (this.searchClear) {
            this.searchClear.addEventListener('click', (event) => {
                event.preventDefault();
                this.resetFilterData();
                this.searchClear.classList.add('hide');
            });
        }

        document.addEventListener('click', (event) => {
            if (event.target.matches('[data-fluent-cart-shop-app-reset-button]')) {
                this.resetFilterData();
            }
        });
    }

    resetFilterData() {
        setTimeout(() => {


            this.isReseting = true;

            this.filterListener.setFilters({});
            this.filterListener.onFilterApplied();


            // Reset checkboxes
            const checkboxes = this.filterForm.querySelectorAll('input[type="checkbox"]');
            checkboxes.forEach(checkbox => checkbox.checked = false);

            // Reset text inputs
            const textInputs = this.filterForm.querySelectorAll('input[type="text"]');
            textInputs.forEach(input => input.value = '');

            this.resetSlider();
            this.appendSearchParamToUrl();
            this.applyFilter();

            setTimeout(() => {
                this.isReseting = false;
            }, 100);
        }, 100);
    }

    listenForFilterValueChange() {
        if (typeof this.filterForm !== 'undefined') {
            const inputs = this.filterForm.querySelectorAll('input');
            inputs.forEach(input => {
                input.addEventListener('change', (event) => {

                    if (input.matches('[data-fluent-cart-search-bar]')) {
                        if (this.searchClear) {
                            if (event.target.value.trim() !== '') {
                                this.searchClear.classList.remove('hide');
                            } else {
                                this.searchClear.classList.add('hide');
                            }
                        }
                    }

                    if (this.isReseting) {
                        event.preventDefault?.();
                        return;
                    }

                    if (this.liveFilter) {
                        this.applyFilter();
                    }
                    setTimeout(() => {
                        this.appendSearchParamToUrl();
                    }, 100);
                });
            });

            // Add event listener for parent checkboxes
            const parentCheckboxes = this.filterForm.querySelectorAll('input[data-parent-checkbox]');
            parentCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', (event) => {
                    if (this.isReseting) {
                        event.preventDefault?.();
                        return;
                    }

                    const isChecked = checkbox.checked;
                    const childGroup = checkbox.closest('[data-fluent-cart-shop-app-filter-checkbox-child-group]');
                    if (childGroup) {
                        const childCheckboxes = childGroup.querySelectorAll('input[type="checkbox"]');
                        childCheckboxes.forEach(child => child.checked = isChecked);
                    }
                });
            });
        }
    }

    applyFilter(){
        const filterState = this.getState();
        this.filterListener.setFilters(filterState);
        this.filterListener.onFilterApplied();
    }

    getState() {
        const state = this.getFormDataAsObject(this.filterForm);

        if (this.activeSortBy) {
            state.sort_by = this.activeSortBy;
        }

        return state;
    }

    appendSearchParamToUrl() {
        let data = this.getState();
        const url = new URL(window.location.href);
        const searchParams = url.searchParams;

        // Remove existing filter parameters
        const inputs = this.filterForm.querySelectorAll('input');
        inputs.forEach(input => {
            const name = input.getAttribute('name');
            if (typeof name !== "undefined") {
                searchParams.delete('filters[' + name + ']');
                searchParams.delete('filters[' + name + '][]');
            }
        });

        let newUrl = '';
        if (this.isReseting) {
            if ([...searchParams.entries()].length) {
                newUrl = `${url.origin}${url.pathname}?${searchParams.toString()}${url.hash}`;
            } else {
                newUrl = `${url.origin}${url.pathname}${url.hash}`;
            }
            history.pushState({path: newUrl}, '', newUrl);
            return;
        }

        // Remove filter parameters for keys in data (before adding new ones)
        for (const key of Object.keys(data)) {
            searchParams.delete('filters[' + key + ']');
            searchParams.delete('filters[' + key + '][]');
        }

        // Add new filter parameters
        for (const [key, value] of Object.entries(data)) {
            if (value !== null && value !== undefined && value !== '') {
                if (Array.isArray(value)) {
                    value.forEach(val => searchParams.append('filters[' + key + '][]', val));
                } else {
                    searchParams.append('filters[' + key + ']', value);
                }
            }
        }

        newUrl = `${url.origin}${url.pathname}?${searchParams.toString()}${url.hash}`;

        // Update the URL without reloading using history.pushState
        history.pushState({path: newUrl}, '', newUrl);
    }

    clearUrlFilters() {
        const url = new URL(window.location.href);
        const searchParams = url.searchParams;
        // Implementation would go here
    }

    listenForResize() {
        window.addEventListener('resize', () => {
            if (this.appContainer.offsetWidth >= 767) {
                this.appContainer.querySelector('[data-fluent-cart-shop-app-filter-wrapper]').classList.remove('is-collapsed');
                if (this.appContainer.querySelector('[data-fluent-cart-shop-app-filter-toggle-button]')) {
                    this.appContainer.querySelector('[data-fluent-cart-shop-app-filter-toggle-button]').classList.add('hide');
                }
                this.filterContainer.appendChild(this.filterForm);
                const responsiveWrappers = document.querySelectorAll('.fluent-cart-shop-app-responsive-filter-wrapper');
                responsiveWrappers.forEach(wrapper => wrapper.remove());
            } else {
                if (this.appContainer.querySelector('[data-fluent-cart-shop-app-filter-toggle-button]')) {
                    this.appContainer.querySelector('[data-fluent-cart-shop-app-filter-toggle-button]').classList.remove('hide');
                }
                this.appContainer.querySelector('[data-fluent-cart-shop-app-filter-wrapper]').classList.add('is-collapsed');
            }
        });
    }

    prepareFilterContainer() {
        if (!this.filterContainer) return;
        this.filterContainer.setAttribute('data-accessor-id', this.#accessorId);
    }

    handleCollapsibleFilter() {
        const toggleButtons = this.appContainer.querySelectorAll('[data-fluent-cart-shop-app-filter-toggle-button]');
        toggleButtons.forEach(button => {
            button.addEventListener('click', () => {
                this.handleFilterPosition(true);
            });
        });
    }

    handleFilterPosition(openFilter = false) {
        // Remove existing responsive filter wrappers
        const existingWrappers = document.querySelectorAll('.fluent-cart-shop-app-responsive-filter-wrapper');
        existingWrappers.forEach(wrapper => wrapper.remove());

        // const container = this.appContainer.querySelector('[data-fluent-cart-shop-app]');
        const containerWidth = this.appContainer.offsetWidth;

        if (containerWidth < window.fluentcart_shop_vars.responsive_filter_breakpoint) {
            // Create responsive filter container from HTML string
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = window.fluentcart_shop_vars.responsive_filter_wrapper;
            const responsiveFilterContainer = tempDiv.firstElementChild;

            responsiveFilterContainer.setAttribute('data-description-id', this.#accessorId);
            responsiveFilterContainer.classList.add('fluent-cart-default-product-page-style', 'fluent-cart-shop-app-wrapper');

            if (window.fluentcart_shop_vars.is_admin_bar_showing) {
                responsiveFilterContainer.classList.add('admin_bar_enabled');
            }

            document.body.appendChild(responsiveFilterContainer);

            const filterContainer = responsiveFilterContainer.querySelector('[data-fluent-cart-shop-app-responsive-filter-container]');
            const filterCloseButton = responsiveFilterContainer.querySelector('[data-fluent-cart-shop-app-responsive-filter-close-button]');

            if (filterCloseButton) {
                filterCloseButton.addEventListener('click', () => {
                    this.hideResponsiveFilter(responsiveFilterContainer);
                });
            }

            if (openFilter) {
                this.showResponsiveFilter(responsiveFilterContainer);
            }

            if (filterContainer) {
                filterContainer.appendChild(this.filterForm);
                responsiveFilterContainer.appendChild(filterContainer);
            }
        } else {
            this.filterContainer.appendChild(this.filterForm);
        }
    }

    handleSidebarItemCollapsible() {
        const collapseButtons = this.filterForm.querySelectorAll('[data-fluent-cart-shop-app-filter-form-item-collapse]');
        collapseButtons.forEach(button => {
            button.addEventListener('click', function () {
                const parent = this.parentElement;
                parent.classList.toggle('is-collapsed');

                // Get all siblings and toggle their visibility
                const siblings = Array.from(parent.children).filter(child => child !== this);
                siblings.forEach(sibling => {
                    if (sibling.classList.contains('hide')) {
                        sibling.classList.remove('hide');
                    } else {
                        sibling.classList.add('hide');
                    }
                });
            });
        });
    }

    showResponsiveFilter(responsiveFilterContainer) {
        responsiveFilterContainer.classList.add('show');
    }

    hideResponsiveFilter(responsiveFilterContainer) {
        responsiveFilterContainer.classList.remove('show');
    }

    resetSlider() {
        const rangeFilters = this.filterForm.querySelectorAll('[data-filter-type="range"]');
        rangeFilters.forEach(wrapper => {
            const sliderWrapper = wrapper.querySelector('[data-range-slider-wrapper]');
            const from = wrapper.querySelector('input[data-range-slider-from-value]');
            const to = wrapper.querySelector('input[data-range-slider-to-value]');

            if (from && to && sliderWrapper && sliderWrapper.noUiSlider) {
                from.value = from.dataset.value;
                to.value = to.dataset.value;
                sliderWrapper.noUiSlider.set([from.dataset.value, to.dataset.value]);
            }
        });
    }

    loadSlider() {
        const rangeFilters = this.filterForm.querySelectorAll('[data-filter-type="range"]');

        if (rangeFilters.length > 0) {
            rangeFilters.forEach(wrapper => {
                const rangeName = wrapper.dataset.filterName;
                let updateCount = 1;
                const from = wrapper.querySelector('input[data-range-slider-from-value]');
                const to = wrapper.querySelector('input[data-range-slider-to-value]');

                if (!from || !to) return;

                const minStart = parseFloat(from.value) || 0;
                const maxStart = parseFloat(to.value) || 0;
                const minValue = parseFloat(from.dataset.value) || 0;
                const maxValue = parseFloat(to.dataset.value) || 0;

                const sliderWrapper = wrapper.querySelector('[data-range-slider-wrapper]');

                if (typeof sliderWrapper !== 'undefined') {
                    // Remove existing slider base if it exists
                    const existingBase = sliderWrapper.querySelector('.noUi-base');
                    if (existingBase) {
                        existingBase.remove();
                    }

                    // Create new slider (assuming noUiSlider is available globally)
                    if (typeof noUiSlider !== 'undefined') {
                        noUiSlider.create(sliderWrapper, {
                            start: [minStart, maxStart],
                            connect: true,
                            step: 0.1,  // Add this line
                            range: {
                                'min': minValue,
                                'max': maxValue
                            },
                            format: {
                                from: function (numericValue) {
                                    return parseFloat(numericValue).toFixed(1);
                                },
                                to: function (numericValue) {
                                    return parseFloat(numericValue).toFixed(1);
                                }
                            }
                        });

                        sliderWrapper.noUiSlider.on('update', (values, handle) => {
                            if (updateCount === 3) {
                                from.value = values[0];
                                to.value = values[1];

                                if (this.isReseting) {
                                    return;
                                }
                                if (this.liveFilter) {
                                    this.debouncedFilterApply();
                                }
                                this.appendSearchParamToUrl();
                            } else {
                                updateCount += 1;
                            }
                        });

                        from.addEventListener('change', (e) => {
                            sliderWrapper.noUiSlider.set([e.target.value, null]);
                        });

                        to.addEventListener('change', (e) => {
                            sliderWrapper.noUiSlider.set([null, e.target.value]);
                        });
                    }
                }
            });
        }
    }

    getFormDataAsObject(form) {
        const formData = new FormData(form);
        const obj = {};

        for (const [key, value] of formData.entries()) {
            if (obj[key] === undefined) {
                obj[key] = value;
            } else {
                if (!(obj[key] instanceof Array)) {
                    obj[key] = [obj[key]];
                }
                obj[key].push(value);
            }
        }

        return obj;
    }

    debounce(func, wait) {
        let timeout;
        return function (...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), wait);
        };
    }
}
