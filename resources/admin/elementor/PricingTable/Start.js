import {createApp} from 'vue'
import {useElementPlusComponents} from "@/mixin/useElementPlusComponents";
import App from "@/elementor/PricingTable/App.vue";
import translate from "@/utils/translator/Translator";


const mountApp = (container) => {
    const settings = container.dataset.settings;
    const app = createApp(App, {settings});
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
    elementor.hooks.addAction('panel/open_editor/widget/fluent_cart_pricing_table', function (panel, model, view) {
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
    //     if (widgetType === 'fluent_cart_pricing_table') {
    //     }
    // });

    elementorFrontend.hooks.addAction('frontend/element_ready/fluent_cart_pricing_table.default', function ($scope) {
        const container = jQuery($scope[0]).find('[data-fluent-cart-pricing-table-app]')[0];
        mountApp(container);
    });
});

