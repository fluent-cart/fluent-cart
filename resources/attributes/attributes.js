import translate from '@/utils/translator/Translator';
import AttributesView from "@/Modules/Attributes/AttributesView.vue";
import AttrGroups from "@/Modules/Attributes/AttrGroups.vue";

window.fluent_cart_admin.hooks.addFilter('fluent_cart_routes', 'fluent_attributes_routes', function (routes) {

    routes.attributes = {
        path: '/attributes',
        component: AttributesView,
        meta: {
            active_menu: 'products',
            title: translate('Attributes'),
            permission: "products/edit"
        },
        children: [
            {
                name: 'attributes',
                path: '',
                component: AttrGroups,
                meta: {
                    active_menu: 'products',
                    title: translate('Attribute Groups'),
                    permission: "products/edit"
                }
            },
            {
                // Redirect legacy /attributes/:id URLs back to the group list.
                path: ':group_id(\\d+)',
                redirect: { name: 'attributes' },
            }
        ]
    };

    return routes;
});
