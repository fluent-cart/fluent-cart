export default class ProductDetailsButton {
    static init() {
        document.addEventListener('click', function (event) {
            const modal = window.FluentCartSingleProductModal;
            if(!modal){
                return;
            }
            const target = event.target.closest('[data-fluent-cart-product-details-button]');
            if (target) {
                event.preventDefault();
                const productId = target.getAttribute('data-product-id');
                modal.openModal(productId, target);
            }
        });
    }
}
