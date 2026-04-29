const cartDrawerToggleClass = 'open';
const cartDrawerOverlayActiveClass = 'active';


export default class AddToCartButton {
    static init() {

        document.addEventListener('click', function (event) {
            const target = event.target.closest('[data-fluent-cart-add-to-cart-button]');

            if (!target) return;

            event.preventDefault();


            if (target.classList.contains('fct-loading')) {
                return;
            }

            target.classList.add('fct-loading');


            const detailsId = target.getAttribute('data-cart-id');
            if (!detailsId) return;

            let quantity = parseInt(target.getAttribute('data-quantity'), 10) || 1;
            const actionName = target.getAttribute('data-action-name');
            const errorActionName = target.getAttribute('data-error-action-name');

            target.setAttribute('data-quantity', '1');

            const isCustom = target.getAttribute('data-is-custom');

            window.fluentCartCart?.addProduct(detailsId, quantity, false, true, isCustom).then((response) => {
                setTimeout(() => {
                    target.classList.remove('fct-loading');
                }, 300);

                document.dispatchEvent(new Event(actionName));
            }).catch((error) => {
                console.log(error, 'error')
                target.classList.remove('fct-loading');
                document.dispatchEvent(new Event(errorActionName));
            });
        });
    }
}

