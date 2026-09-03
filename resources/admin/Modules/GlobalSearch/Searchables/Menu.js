import translate from "@/utils/translator/Translator";
import AppConfig from "@/utils/Config/AppConfig";

const paymentRoutes = (AppConfig.get('payment_routes') || [])
    .filter(route => !route.upcoming)
    .map(route => {
        const title = route.meta.admin_title || route.meta.title || route.meta.label || route.name;
        return {
            title,
            /* translators: %1$s: payment gateway name */
            subtitle: translate('Manage %1$s', title),
            path: '/settings/payments/' + route.path,
        };
    });

const menuItems = [
    { title: translate('Dashboard'),        path: '/' },
    { title: translate('Orders'),           path: '/orders' },
    { title: translate('Create Order'),     path: '/orders/add' },
    { title: translate('Customers'),        path: '/customers' },
    { title: translate('Products'),         path: '/products' },
    { title: translate('Product Inventory'), path: '/products/inventory' },
    { title: translate('Bulk Import Products'), path: '/products/bulk-insert' },
    { title: translate('Integrations'),     path: '/integrations' },
    { title: translate('Coupons'),          path: '/coupons' },
    { title: translate('Logs'),             path: '/logs' },
    { title: translate('Taxes'),            path: '/taxes' },
    { title: translate('Reports'),          path: '/reports/overview' },
    { title: translate('Sales Report'),     path: '/reports/sales' },
    { title: translate('Orders Report'),    path: '/reports/orders' },
    { title: translate('Revenue Report'),   path: '/reports/revenue' },
    { title: translate('Refunds Report'),   path: '/reports/refunds' },
    { title: translate('Subscriptions Report'), path: '/reports/subscriptions' },
    { title: translate('Future Renewals Report'), path: '/reports/subscriptions/future-renewals' },
    { title: translate('Product Report'),   path: '/reports/products' },
    { title: translate('Customer Report'),  path: '/reports/customer' },
    { title: translate('Traffic Sources Report'), path: '/reports/sources' },
    ...paymentRoutes,
];

const Menu = menuItems.map(({title, subtitle, path}) => ({
    title,
    ...(subtitle ? {subtitle} : {}),
    data: {
        type: 'action',
        action: ({router}) => {
            router.push({path});
        },
        show_description: false,
    }
}));

export default Menu;
