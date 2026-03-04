export default class FluentCartSingleProductPriceTab {
    #tabButtons;
    #container;
    #itemPrice;
    #paymentType;
    #quantity;
    #addToCartBtn;

    constructor(container) {
        this.#container = container;
        this.#tabButtons = this.#container.querySelectorAll('[data-tab]');
        this.#itemPrice = this.#container.querySelector('[data-fluent-cart-product-item-price]');
        this.#paymentType = this.#container.querySelector('[data-fluent-cart-product-payment-type]');
        this.#quantity = this.#container.querySelector('[data-fluent-cart-product-quantity-container]');
        this.#addToCartBtn = this.#container.querySelector('[data-fluent-cart-add-to-cart-button]');
    }

    init() {
        this.#bindActions();
        this.#initializeActiveBar();
    }

    #bindActions() {
        this.#tabButtons.forEach(button => {
            button.addEventListener('click', () => {
                this.#changeTab(button);
            });

            button.addEventListener('keydown', (event) => {
                const tabs = Array.from(this.#tabButtons);
                const currentIndex = tabs.indexOf(button);
                let targetIndex = -1;

                switch (event.key) {
                    case 'Enter':
                    case ' ':
                        event.preventDefault();
                        this.#changeTab(button);
                        return;
                    case 'ArrowRight':
                        event.preventDefault();
                        targetIndex = (currentIndex + 1) % tabs.length;
                        break;
                    case 'ArrowLeft':
                        event.preventDefault();
                        targetIndex = (currentIndex - 1 + tabs.length) % tabs.length;
                        break;
                    case 'Home':
                        event.preventDefault();
                        targetIndex = 0;
                        break;
                    case 'End':
                        event.preventDefault();
                        targetIndex = tabs.length - 1;
                        break;
                    default:
                        return;
                }

                if (targetIndex >= 0) {
                    tabs[targetIndex].focus();
                    this.#changeTab(tabs[targetIndex]);
                }
            });
        });
    }

    handleVariationChange(variationButton) {
        const parentTabContainer = variationButton.closest('[data-tab-content]');

        if (parentTabContainer) {
            // console.log(parentTabContainer.id)
        }
    }

    #initializeActiveBar() {
        // Find the currently active tab button from the list of tab buttons
        const activeTab = Array.from(this.#tabButtons).find(button =>
            button.classList.contains('active')
        );

        // Check if an active tab button exists
        if (activeTab) {
            // Update the position and size of the active border based on the active tab button
            this.#updateActiveBar(activeTab);
        }
    }

    #changeTab(button, quite = false) {
        if (this.#itemPrice) {
            this.#itemPrice.classList.add('is-hidden');
        }

        if (this.#paymentType) {
            this.#paymentType.classList.add('is-hidden');
        }

        if (this.#quantity) {
            this.#quantity.classList.add('is-hidden');
        }
        if (this.#addToCartBtn) {
            this.#addToCartBtn.classList.add('is-hidden');
        }

        // Remove active class and update ARIA states for all tabs
        this.#tabButtons.forEach(tab => {
            tab.classList.remove('active');
            tab.setAttribute('aria-selected', 'false');
            tab.setAttribute('tabindex', '-1');
        });

        // Hide all tab contents
        const tabContents = this.#container.querySelectorAll('[data-tab-content]');
        tabContents.forEach(content => {
            content.classList.remove('active');
            content.style.display = 'none';
        });

        // Add active class to the clicked tab and update ARIA
        button.classList.add('active');
        button.setAttribute('aria-selected', 'true');
        button.setAttribute('tabindex', '0');

        // Get the associated content and show it with animation
        const activeTabId = button.dataset.tab;
        const activeTab = this.#container.querySelector(`#${activeTabId}`);

        if (activeTab) {
            activeTab.classList.add('active');
            this.#fadeIn(activeTab, 400);
        }

        // Update active border position and size
        this.#updateActiveBar(button);
        let variationButton = activeTab?.querySelector(
            '[data-fluent-cart-product-variant][data-item-stock="in-stock"]'
        );

        if (!variationButton) {
            variationButton = activeTab?.querySelector(
                '[data-fluent-cart-product-variant]'
            );
        }

        if (variationButton) {
            variationButton.click();
        }
    }

    #fadeIn(element, duration) {
        element.style.opacity = '0';
        element.style.display = 'block';

        const start = performance.now();

        const animate = (currentTime) => {
            const elapsed = currentTime - start;
            const progress = Math.min(elapsed / duration, 1);

            element.style.opacity = progress;

            if (progress < 1) {
                requestAnimationFrame(animate);
            }
        };

        requestAnimationFrame(animate);
    }

    #updateActiveBar(button) {
        // Select the active border element
        const activeBorder = this.#container.querySelector('[data-tab-active-bar]');

        if (!activeBorder) return;

        // Get the dimensions and position of the clicked tab
        const tabRect = button.getBoundingClientRect();
        const parentRect = button.parentElement.getBoundingClientRect();

        // Calculate the position of the active border relative to the parent element
        // and set its left offset to align with the clicked tab
        activeBorder.style.left = `${tabRect.left - parentRect.left}px`;

        // Set the width of the active border to match the width of the clicked tab
        activeBorder.style.width = `${tabRect.width}px`;
    }
}