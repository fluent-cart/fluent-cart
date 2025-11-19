const resetMessages = (form, $checkoutFormNotices) => {
    form?.removeClass('fluent_cart_checkout_has_errors');
    $checkoutFormNotices?.removeClass('fluent_cart_checkout_notice_error fluent_cart_checkout_notice_success fluent_cart_checkout_notice_info')
    $checkoutFormNotices?.html('').hide();
}

export default resetMessages;