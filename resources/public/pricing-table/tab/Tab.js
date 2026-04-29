export default class FluentCartPricingTableTab {
    #tabButtons;
    #container

    constructor(container) {
        this.#container = container;
        this.#tabButtons = this.#container.querySelectorAll('[data-tab]');
    }

    init() {
        this.#bindActions();
        this.#initializeActiveBar();
    }

    #bindActions() {
        const ref = this;
        this.#tabButtons.forEach(function(button) {
            button.addEventListener('click', function() {
                // Remove active class from all tabs and contents
                ref.#changeTab(this);
            });
        });
    }

    handleVariationChange(variationButton) {
        const parentTabContainer = variationButton.closest('[data-tab-content]');

        if (parentTabContainer) {
            console.log(parentTabContainer.id);
        }
    }

    #initializeActiveBar() {
        // Find the currently active tab button from the list of tab buttons
        const activeTab = Array.from(this.#tabButtons).find(tab => tab.classList.contains('active'));

        // Check if an active tab button exists
        if (activeTab) {
            // Update the position and size of the active border based on the active tab button
            this.#updateActiveBar(activeTab);
        }
    }

    #changeTab(button, quite = false) {
        // Exit if the button is already active
        if (button.classList.contains('active')) return;

        // Remove active class from all tab buttons
        this.#tabButtons.forEach(tab => tab.classList.remove('active'));

        // Hide all tab contents
        const allTabContents = document.querySelectorAll('[data-tab-content]');
        allTabContents.forEach(content => {
            content.classList.remove('active');
            this.#fadeOut(content);
        });

        // Add active class to the clicked tab
        button.classList.add('active');

        // Get the associated content and show it with animation
        const activeTabId = button.getAttribute('data-tab');
        const activeTab = document.getElementById(activeTabId);

        if (activeTab) {
            this.#fadeIn(activeTab, 400);
            activeTab.classList.add('active');
        }

        // Update active border position and size
        this.#updateActiveBar(button);

        if (!quite && activeTab) {
            const variationButton = activeTab.querySelector('[data-fluent-cart-pricing-table-variant]');
            if (variationButton) {
                variationButton.click();
            }
        }
    }

    #updateActiveBar(button) {
        // Select the active border element
        const activeBorder = document.querySelector('[data-tab-active-bar]');

        if (!activeBorder) return;

        // Get the dimensions and position of the clicked tab
        const tabRect = button.getBoundingClientRect();
        const parentRect = button.parentElement.getBoundingClientRect();

        // Calculate the position of the active border relative to the parent element
        // and set its left offset to align with the clicked tab
        activeBorder.style.left = `${tabRect.left - parentRect.left}px`;

        // Set the width, height of the active border to match the width, height of the clicked tab
        activeBorder.style.width = `${tabRect.width}px`;
        activeBorder.style.height = `${tabRect.height}px`;
    }

    // Utility function to replicate jQuery's fadeIn
    #fadeIn(element, duration = 400) {
        element.style.opacity = '0';
        element.style.display = 'block';

        const start = performance.now();

        const animate = (timestamp) => {
            const elapsed = timestamp - start;
            const progress = Math.min(elapsed / duration, 1);

            element.style.opacity = progress;

            if (progress < 1) {
                requestAnimationFrame(animate);
            }
        };

        requestAnimationFrame(animate);
    }

    // Utility function to replicate jQuery's fadeOut
    #fadeOut(element, duration = 400) {
        const start = performance.now();
        const startOpacity = parseFloat(getComputedStyle(element).opacity);

        const animate = (timestamp) => {
            const elapsed = timestamp - start;
            const progress = Math.min(elapsed / duration, 1);

            element.style.opacity = startOpacity * (1 - progress);

            if (progress >= 1) {
                element.style.display = 'none';
            } else {
                requestAnimationFrame(animate);
            }
        };

        requestAnimationFrame(animate);
    }
}