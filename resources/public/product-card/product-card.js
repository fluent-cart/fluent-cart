import ProductDetailsButton from "../buttons/product-details/script";
import SingleProductModal from "../product-page/SingleProductModal";
import initAttributeSwatch, {syncCardButtons} from "./product-attribute-swatches";
import {placeQuickViews} from "./product-quick-view";
import {layoutMasonry} from "./product-card-masonry";

class FluentCartProductCard {
    static #instance = null;

    static init() {
        if (FluentCartProductCard.#instance !== null) {
            return FluentCartProductCard.#instance;
        }

        ProductDetailsButton.init();
        FluentCartProductCard.#instance = this;
        return this;
    }
}

initAttributeSwatch();

function prepareCards(scope) {
    // Buttons first: quick view placement needs to know which cards kept View Options.
    syncCardButtons(scope);
    placeQuickViews(scope);
    layoutMasonry(scope);
}

function watchCards() {
    prepareCards(document);
    // Cards from pagination, filters and page builders arrive later.
    new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            if (mutation.type === 'attributes') {
                if (mutation.attributeName === 'class') {
                    const before = (mutation.oldValue || '').split(/\s+/);
                    if (['mode-list', 'fct-card-layout--masonry', 'fct-card-masonry'].some(name => before.includes(name) !== mutation.target.classList.contains(name))) {
                        layoutMasonry(mutation.target);
                    }
                    return;
                }
                if (mutation.attributeName === 'data-fct-masonry') {
                    layoutMasonry(mutation.target);
                    return;
                }
                const card = mutation.target.closest('[data-fct-product-card], .fct-product-card');
                if (card) placeQuickViews(card);
                return;
            }
            mutation.addedNodes.forEach((node) => {
                if (node.nodeType === 1) {
                    prepareCards(node.closest('[data-fct-product-card], .fct-product-card') || node);
                }
            });
        });
    }).observe(document.documentElement, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeOldValue: true,
        attributeFilter: ['data-fluent-cart-single-product-card-view-button', 'class', 'data-fct-masonry'],
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', watchCards);
} else {
    watchCards();
}

function initializeModal() {
    FluentCartProductCard.init();
    if (!window.FluentCartSingleProductModal) {
        window.FluentCartSingleProductModal = new SingleProductModal();
    }
}

if (document.readyState === 'complete') {
    initializeModal();
} else {
    window.addEventListener('load', initializeModal, {once: true});
}
