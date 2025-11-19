import {createApp} from 'vue'
import {useElementPlusComponents} from "@/mixin/useElementPlusComponents";
import App from "@/elementor/AddToCart/App.vue";
import translate from "@/utils/translator/Translator";


const mountApp = (container) => {
    const variantId = container.dataset.variantId;
    const variantTitle = container.dataset.variantTitle;
    const variantPrice = container.dataset.variantPrice;
    const app = createApp(App, {variantId, variantTitle, variantPrice});
    useElementPlusComponents(app);
    app
        .mixin({
            methods: {
                $t: translate
            }
        })
        .mount(container);
}


jQuery(document).ready(function () {
    elementor.hooks.addAction('panel/open_editor/widget/fluent_cart_add_to_cart', function (panel, model, view) {
        // When your widget panel opens
        // Access widget settings
        var settings = model.get('settings');
        //console.log(settings, 'settings');
        // Initialize custom panel behavior
        //initializePanelControls(panel, model, view);
    });
});

// Wait for Elementor frontend to be ready
jQuery(window).on('elementor/frontend/init', function () {
    // elementorFrontend.hooks.addAction('frontend/element_ready/global', function ($scope, $) {
    //     var widgetType = $scope.data('widget_type');
    //     if (widgetType === 'fluent_cart_add_to_cart') {
    //     }
    // });

    elementorFrontend.hooks.addAction('frontend/element_ready/fluent_cart_add_to_cart.default', function ($scope) {
        const container = jQuery($scope[0]).find('[data-fluent-cart-add-to-cart-app]')[0];
        mountApp(container);
    });
});

