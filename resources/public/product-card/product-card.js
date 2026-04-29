import ProductDetailsButton from "../buttons/product-details/script";
import SingleProductModal from "../product-page/SingleProductModal";

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

window.addEventListener("load", function () {
    FluentCartProductCard.init();
    window.FluentCartSingleProductModal = new SingleProductModal();
});
