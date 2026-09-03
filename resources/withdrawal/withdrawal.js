import translate from '@/utils/translator/Translator';
import WithdrawalRequests from './components/WithdrawalRequests.vue';

window.fluent_cart_admin.hooks.addFilter('fluent_cart_routes', 'fctwd_requests', function (routes) {
    routes.withdrawal_requests = {
        name: 'withdrawal_requests',
        path: '/withdrawal-requests',
        component: WithdrawalRequests,
        meta: {
            active_menu: 'withdrawal_requests',
            title: translate('Withdrawal Requests'),
            permission: 'orders/view'
        }
    };

    return routes;
});
