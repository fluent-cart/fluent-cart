import translate from "@/utils/translator/Translator";
import permission from "@/utils/permission/Permission";

const menuItems = [
    { title: translate('Dashboard'),                    path: '/',                                        perm: null },
    { title: translate('Orders'),                       path: '/orders',                                  perm: 'orders/view' },
    { title: translate('Create Order'),                 path: '/orders/add',                              perm: 'orders/create' },
    { title: translate('Customers'),                    path: '/customers',                               perm: 'customers/view' },
    { title: translate('Products'),                     path: '/products',                                perm: 'products/view' },
    { title: translate('Product Inventory'),            path: '/products/inventory',                      perm: 'products/view' },
    { title: translate('Bulk Import Products'),         path: '/products/bulk-insert',                    perm: 'products/create' },
    { title: translate('Integrations'),                 path: '/integrations',                            perm: 'integrations/view' },
    { title: translate('Coupons'),                      path: '/coupons',                                 perm: 'coupons/view' },
    { title: translate('Logs'),                         path: '/logs',                                    perm: 'is_super_admin' },
    { title: translate('Taxes'),                        path: '/taxes',                                   perm: 'is_super_admin' },
    { title: translate('Reports'),                      path: '/reports/overview',                        perm: 'reports/view' },
    { title: translate('Sales Report'),                 path: '/reports/sales',                           perm: 'reports/view' },
    { title: translate('Orders Report'),                path: '/reports/orders',                          perm: 'reports/view' },
    { title: translate('Revenue Report'),               path: '/reports/revenue',                         perm: 'reports/view' },
    { title: translate('Refunds Report'),               path: '/reports/refunds',                         perm: 'reports/view' },
    { title: translate('Subscriptions Report'),         path: '/reports/subscriptions',                   perm: 'reports/view' },
    { title: translate('Future Renewals Report'),       path: '/reports/subscriptions/future-renewals',   perm: 'reports/view' },
    { title: translate('Product Report'),               path: '/reports/products',                        perm: 'reports/view' },
    { title: translate('Customer Report'),              path: '/reports/customer',                        perm: 'reports/view' },
    { title: translate('Traffic Sources Report'),       path: '/reports/sources',                         perm: 'reports/view' },
    { title: translate('Manage PayPal'),                path: '/settings/payments/paypal',                perm: 'is_super_admin' },
    { title: translate('Manage Stripe'),                path: '/settings/payments/stripe',                perm: 'is_super_admin' },
];

const Menu = menuItems
    .filter(({ perm }) => !perm || permission.has(perm))
    .map(({ title, path }) => ({
        title,
        data: {
            type: 'action',
            action: ({ router }) => {
                router.push({ path });
            },
            show_description: false,
        }
    }));

export default Menu;
