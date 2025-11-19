window.addEventListener("fluent_cart_load_payments_offline_payment", function (e) {
    const submitButton = window.fluentcart_checkout_vars?.submit_button;
    // const codContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_offline_payment');
    const translations = window.fct_cod_data?.translations || {};

    function $t(string) {
        return translations[string] || string;
    }
    // if (codContainer) {
    //     codContainer.innerHTML = `<p>${$t('Cash upon delivery, bank transfer or other manual process.')}</p>`;
    // }

    e.detail.paymentLoader.enableCheckoutButton(submitButton.text);
});
