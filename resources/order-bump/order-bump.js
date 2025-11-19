import AllOrderBump from "@/Modules/OrderBump/AllOrderBump.vue";
import ViewOrderBump from "@/Modules/OrderBump/ViewOrderBump.vue";


window.fluent_cart_admin.hooks.addFilter('fluent_cart_routes', 'fluent_order_bump_routes', function (routes) {

    routes.order_bump_route = {
        name: 'order_bump',
        path: '/order_bump',
        component: AllOrderBump,
        meta: {
            active_menu: 'order_bump',
            title: 'Order Bump',
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
            title: 'View Order Bump',
            permission: "store/sensitive"
        }
    };


    return routes;
})
