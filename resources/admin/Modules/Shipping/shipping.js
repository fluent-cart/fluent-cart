import translate from "@/utils/translator/Translator";

const ShippingZonesMoved = () => import('./ShippingZonesMoved.vue');
const SingleShippingZone = () => import('./SingleShippingZone.vue');
const AllShippingClasses = () => import('./AllShippingClasses.vue');
const SingleShippingClass = () => import('./SingleShippingClass.vue');
const AllShippingPackages = () => import('./AllShippingPackages.vue');
const Carriers = () => import('./Carriers.vue');
const SingleCarrier = () => import('./SingleCarrier.vue');


window.fluent_cart_admin.hooks.addFilter('fluent_cart_routes', 'fluent_shipping', function (routes) {
    // Add shipping routes as children to the settings route
    if (routes.settings && routes.settings.children) {
        routes.settings.children.push(
            {
                // Kept for old links; zones now live on the Manual carrier page.
                name: 'shipping',
                path: 'shipping',
                component: ShippingZonesMoved,
                meta: {
                    active_menu: 'shipping',
                    title: translate('Shipping Zones'),
                    // Same gate as the Manual carrier page its button opens.
                    permission: ['store/sensitive']
                },
            },
            {
                name: 'view_shipping_zone',
                path: 'shipping/zone/:zone_id(\\d+)/edit',
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
                path: 'shipping/shipping_classes/:class_id(\\d+)/edit',
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
            },
            {
                name: 'shipping_packages',
                path: 'shipping/packages',
                component: AllShippingPackages,
                meta: {
                    active_menu: 'settings',
                    title: translate('Packages'),
                    permission: ["store/settings", 'store/sensitive']
                }
            },
            {
                name: 'shipping_carriers',
                path: 'shipping/carriers',
                component: Carriers,
                meta: {
                    active_menu: 'shipping',
                    title: translate('Carriers'),
                    // The carriers REST endpoints sit behind StoreSensitivePolicy,
                    // so the page gate matches — a store/settings-only user would
                    // only see 403s here.
                    permission: ['store/sensitive']
                }
            },
            {
                name: 'carrier_settings',
                path: 'shipping/carriers/:slug(\\.*[^./?#\\\\%&;][^/?#\\\\%&;]*)',
                component: SingleCarrier,
                meta: {
                    active_menu: 'shipping',
                    title: translate('Carrier Settings'),
                    permission: ['store/sensitive']
                }
            }
        );
    }

    return routes;
});
