import AddonAssetsSettings from './AddonAssetsSettings.vue';

window.fluent_cart_admin.hooks.addFilter('fluent_cart_routes', 'fluent_cart_addon_assets', function (routes) {
    routes.product_route.children.push({
        name: 'addon_assets',
        path: 'addon_assets',
        props: true,
        component: AddonAssetsSettings,
        meta: {
            active_menu: 'products',
            title: 'Addon Assets',
            permission: 'store/sensitive',
        },
    });
    return routes;
});
