import AddToCartButton from "../buttons/add-to-cart/script";
import ProductDetailsButton from "../buttons/product-details/script";
import SingleProductModal from "../product-page/SingleProductModal";

class FluentCartProductCard {
    static #instance = null;

    static init() {
        if (FluentCartProductCard.#instance !== null) {
            return FluentCartProductCard.#instance;
        }
        AddToCartButton.init();
        ProductDetailsButton.init();
        FluentCartProductCard.#instance = this;
        return this;
    }
}

window.addEventListener("load", function () {
    FluentCartProductCard.init();
    window.FluentCartSingleProductModal = new SingleProductModal();
    console.log('called')
});
