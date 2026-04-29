import SubscriptionsRoute from "./SubscriptionsRoute.vue";
import AllSubscriptions from "./AllSubscriptions.vue";
import SingleSubscription from "./SingleSubscription.vue";
import translate from "@/utils/translator/Translator";

window.fluent_cart_admin.hooks.addFilter('fluent_cart_routes', 'fluent_all_subscriptions', function (routes) {

    routes.subscription_route = {
        name: 'subscriptions',
        path: '/subscriptions',
        component: SubscriptionsRoute,
        children: [
            {
                name: 'all_subscriptions',
                path: '',
                component: AllSubscriptions,
                meta: {
                    active_menu: 'subscriptions',
                    title: 'Subscriptions'
                },
            },
            {
                name: 'view_subscription',
                path: ':subscription_id/view',
                component: SingleSubscription,
                props: true,
                meta: {
                    active_menu: 'subscriptions',
                    title: translate('View Subscription')
                }
            },
        ]
    };
    return routes;
});
