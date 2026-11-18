import translate from '@/utils/translator/Translator';
import AllOrderBump from "@/Modules/OrderBump/AllOrderBump.vue";
import ViewOrderBump from "@/Modules/OrderBump/ViewOrderBump.vue";
import OrderBumpDesign from "@/Modules/OrderBump/OrderBumpDesign.vue";
import OrderBumpTemplateEdit from "@/Modules/OrderBump/OrderBumpTemplateEdit.vue";


window.fluent_cart_admin.hooks.addFilter('fluent_cart_routes', 'fluent_order_bump_routes', function (routes) {

    routes.order_bump_route = {
        name: 'order_bump',
        path: '/order_bump',
        component: AllOrderBump,
        meta: {
            active_menu: 'order_bump',
            title: translate('Order Bump'),
            permission: "store/sensitive"
        }
    };

    routes.view_order_bump = {
        name: 'view_order_bump',
        path: '/order_bump/:id/view',
        component: ViewOrderBump,
        props: true,
        meta: {
            active_menu: 'order_bump',
            title: translate('View Order Bump'),
            permission: "store/sensitive"
        }
    };

    // The card designs, reached from the Design button on the Order Bumps page.
    routes.order_bump_design = {
        name: 'order_bump_design',
        path: '/order_bump/design',
        component: OrderBumpDesign,
        meta: {
            active_menu: 'order_bump',
            title: translate('Order Bump Design'),
            permission: "store/sensitive"
        }
    };

    routes.order_bump_template_edit = {
        name: 'order_bump_template_edit',
        path: '/order_bump/design/:template/edit',
        component: OrderBumpTemplateEdit,
        props: true,
        meta: {
            active_menu: 'order_bump',
            title: translate('Edit Order Bump Card'),
            permission: "store/sensitive"
        }
    };

    return routes;
})
