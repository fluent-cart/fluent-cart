import Arr from "@/utils/support/Arr";

jQuery(document).ready(function ($) {

    // Use delegation to handle dynamic button rendering
    $(document).on('click', '#fct-clear-colors-btn', function () {

        const colorControlIds = Arr.get(window, 'fluent_cart_shop_app_elementor_vars.color_codes', []);
        const model = elementor.getPanelView()?.getCurrentPageView()?.model;

        if (!model) return;

        colorControlIds.forEach(controlId => {
            model.setSetting?.(controlId, '');
        });
    });
});
