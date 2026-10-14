import translate from '@/utils/translator/Translator';
import AccountingExport from "@/Modules/Reports/AccountingExport.vue";

// Reports → Export (FluentCart Pro): the OSS / accounting export,
// dev-docs/multi-currency/plan.md §15.3. Loaded by Pro on fluent_cart/loading_app.
window.fluent_cart_admin.hooks.addFilter('fluent_cart_routes', 'fluent_cart_accounting_export_routes', function (routes) {
    if (routes.reports && Array.isArray(routes.reports.children)) {
        routes.reports.children.push({
            name: 'reports_export',
            path: 'export',
            component: AccountingExport,
            meta: {
                title: translate('Accounting export'),
                permission: 'reports/export'
            }
        });
    }

    return routes;
});
