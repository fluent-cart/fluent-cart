import ShippingRoute from "./ShippingRoute.vue";
import AllShippingZones from "./AllShippingZones.vue";
import SingleShippingZone from "./SingleShippingZone.vue";
import AllShippingClasses from "./AllShippingClasses.vue";
import SingleShippingClass from "./SingleShippingClass.vue";
import translate from "@/utils/translator/Translator";


window.fluent_cart_admin.hooks.addFilter('fluent_cart_routes', 'fluent_shipping', function (routes) {
    // Add shipping routes as children to the settings route
    if (routes.settings && routes.settings.children) {
        routes.settings.children.push(
            {
                name: 'shipping',
                path: 'shipping',
                component: AllShippingZones,
                meta: {
                    active_menu: 'shipping',
                    title: translate('Shipping Zones'),
                    permission: ["store/settings", 'store/sensitive']
                },
            },
            {
                name: 'view_shipping_zone',
                path: 'shipping/zone/:zone_id/edit',
                component: SingleShippingZone,
                props: true,
                meta: {
                    active_menu: 'settings',
                    title: translate('Edit Shipping Zone'),
                    permission: ["store/settings", 'store/sensitive']
                }
            },
            {
                name: 'add_shipping_zone',
                path: 'shipping/zone/add',
                component: SingleShippingZone,
                meta: {
                    active_menu: 'settings',
                    title: translate('Add Shipping Zone'),
                    permission: ["store/settings", 'store/sensitive']
                }
            },
            {
                name: 'shipping_classes',
                path: 'shipping/shipping_classes',
                component: AllShippingClasses,
                meta: {
                    active_menu: 'settings',
                    title: translate('Shipping Classes'),
                    permission: ["store/settings", 'store/sensitive']
                }
            },
            {
                name: 'view_shipping_class',
                path: 'shipping/shipping_classes/:class_id/edit',
                component: SingleShippingClass,
                props: true,
                meta: {
                    active_menu: 'settings',
                    title: translate('Edit Shipping Class'),
                    permission: ["store/settings", 'store/sensitive']
                }
            },
            {
                name: 'add_shipping_class',
                path: 'shipping/shipping_classes/add',
                component: SingleShippingClass,
                meta: {
                    active_menu: 'settings',
                    title: translate('Add Shipping Class'),
                    permission: ["store/settings", 'store/sensitive']
                }
            }
        );
    }

    return routes;
});
