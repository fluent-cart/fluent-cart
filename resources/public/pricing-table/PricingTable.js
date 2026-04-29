import Tab from './tab/Tab'

// Wait for DOM to be ready
document.addEventListener('DOMContentLoaded', () => {

    class FluentCartPricingTable {
        static #instance = null;
        #container;
        #addToCartButtons;
        #cartDrawerToggleClass = 'open';
        #cartDrawerOverlayActiveClass = 'active';
        #tab;
        #index;

        toTitleCase(str) {
            return str.replace(
                /\w\S*/g,
                function (txt) {
                    return txt.charAt(0).toUpperCase() + txt.substr(1).toLowerCase();
                }
            );
        }

        $t(str) {
            return window.fluentcart_pricing_table_vars.trans[str] || str;
        }

        init(container, index) {
            this.#index = index;
            this.#container = container;
            this.#addToCartButtons = this.#container.querySelectorAll('[data-fluent-cart-pricing-table-variant-add-to-cart]');

            this.#setupCartButtons();
            this.#setup();
            this.#initTabOnDemand();

            FluentCartPricingTable.#instance = this;

            return this;
        }

        #initTabOnDemand() {
            const tabContainer = this.#container.querySelector('[data-fluent-cart-pricing-table-tab]');

            if (tabContainer) {
                this.#tab = new Tab(this.#container);
                this.#tab.init();
            }
        }

        #setup() {

        }

        #setupCartButtons() {

        }
    }

    // Initialize all pricing table containers
    const pricingTableContainers = document.querySelectorAll('[data-fluent-cart-pricing-table]');
    pricingTableContainers.forEach(function (container, index) {
        new FluentCartPricingTable().init(container, index);
    });

    // Make class available globally
    window.FluentCartPricingTable = FluentCartPricingTable;

});