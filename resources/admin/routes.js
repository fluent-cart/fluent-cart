import AllCustomers from './Modules/Customers/AllCustomers.vue';
import AllOrders from './Modules/Orders/AllOrders.vue';
import InvoicePacking from "./Modules/Settings/InvoicePacking.vue";
import CustomersRoute from './Modules/Customers/CustomersRoute.vue';
import Dashboard from './Pages/Dashboard/Dashboard.vue';
import FeedEditor from "./Modules/Integrations/FeedEditor.vue";
import Integrations from "./Modules/Integrations/Integrations.vue";
import GlobalPaymentComponents from './Modules/Settings/GlobalPaymentComponents.vue'
import IntegrationEditor from "./Modules/Integrations/IntegrationEditor.vue";
import Onboarding from "./Modules/Onboarding/Onboarding.vue";
import OrdersRoute from './Modules/Orders/OrdersRoute.vue';
import PaymentSettings from "./Modules/Settings/PaymentSettings.vue";
import RoleSettings from "./Modules/Settings/Roles/RoleSettings.vue";
import SettingsView from "./Modules/Settings/SettingsView.vue";
import SingleCustomer from './Modules/Customers/SingleCustomer.vue';
import SingleOrder from './Modules/Orders/SingleOrder.vue';
import CreateOrder from './Modules/Orders/CreateOrder.vue';
import StoreSettings from "./Modules/Settings/StoreSettings.vue";
import StoreSettingsRoute from "./Modules/Settings/StoreSettingsRoute.vue";
import Licensing from "@/Modules/Settings/Licensing.vue";

import ProductRoute from './Modules/Products/ProductRoute.vue';
import AllProducts from './Modules/Products/AllProducts.vue';
import EditProduct from "./Modules/Products/EditProduct.vue";
import UpgradePaths from "@/Modules/Products/UpgradePaths/UpgradePaths.vue";
import ProductIntegrations from '@/Modules/Products/Integrations/Integrations.vue';
import SingleIntegration from '@/Modules/Products/Integrations/SingleIntegration.vue';


import EmailNotificationSettings from "@/Modules/Settings/EmailNotification/EmailNotificationSettings.vue";
import EditEmailNotification from "@/Modules/Settings/EmailNotification/EditEmailNotification.vue";
import StorageSettings from "./Modules/Settings/StorageSettings.vue";
import GlobalStorageComponents from './Modules/Settings/GlobalStorageComponents.vue'
import AddEmailNotification from "@/Modules/Settings/EmailNotification/AddEmailNotification.vue";
import Logs from "@/Modules/Logs/Logs.vue";
import ReportsView from "./Modules/Reports/ReportsView.vue";
import DefaultReport from "./Modules/Reports//Default/DefaultReport.vue";
import RevenueReport from "./Modules/Reports/Revenue/RevenueReport.vue";
import OrderReport from "./Modules/Reports/Order/OrderReport.vue";
import RefundReport from "@/Modules/Reports/Refund/RefundReport.vue";
import SubscriptionReport from "@/Modules/Reports/Subscription/SubscriptionReport.vue";
import FutureRenewalsReport from "@/Modules/Reports/Subscription/FutureRenewalsReport.vue";
import CustomerReport from "@/Modules/Reports/Customer/CustomerReport.vue";
import SourcesReport from "@/Modules/Reports/Sources/SourcesReport.vue";

import ProductReport from './Modules/Reports/Product/ProductReport.vue';
import OverviewReport from './Modules/Reports/Overview/Overview.vue';
import AddonSettings from "./Modules/Settings/AddonSettings.vue";
import MailingSettings from "@/Modules/Settings/EmailNotification/MailingSettings.vue";
import TaxSettingsRoute from "@/Modules/Settings/TaxSettingsRoute.vue";
import TaxConfigurations from "@/Modules/Tax/TaxConfigurations.vue";
import TaxRates from "@/Modules/Tax/TaxRates.vue";
import TaxRatesCountrySingle from "@/Modules/Tax/TaxRatesCountrySingle.vue";
import Taxes from '@/Modules/Tax/Taxes.vue';
import EUVatSettings from "@/Modules/Tax/EUVatSettings.vue";
import EUVatSettingsOld from "@/Modules/Tax/EUVatSettingsOld.vue";

import AllCoupons from "@/Modules/Coupons/AllCoupons.vue";
import AddOrEditCoupon from "@/Modules/Coupons/AddOrEditCoupon.vue";

import CheckoutFields from "@/Modules/Settings/CheckoutFields.vue";
import AppConfig from "@/utils/Config/AppConfig";


const PaymentChildRoutes = AppConfig.get('payment_routes');

for (const route of PaymentChildRoutes) {
    route.props = true;
}

const StorageDriverChildRoutes = AppConfig.get('storage_driver_routes');

for (const route of StorageDriverChildRoutes) {
    route.props = true;
}

export var routes = {
    dashboard: {
        name: 'dashboard',
        path: '/',
        component: Dashboard,
        meta: {
            active_menu: 'dashboard',
            title: 'Dashboard',
        }
    },
    orders: {
        path: '/orders',
        component: OrdersRoute,
        meta: {
            active_menu: 'orders',
            title: 'Orders'
        },
        children: [
            {
                name: 'orders',
                path: '',
                component: AllOrders,
                meta: {
                    active_menu: 'orders',
                    title: 'Orders',
                    permission: "orders/view"
                },
            },
            {
                name: 'view_order',
                path: ':order_id/view',
                component: SingleOrder,
                props: true,
                meta: {
                    active_menu: 'orders',
                    title: 'View Order',
                    permission: ["orders/view", "orders/manage"],
                }
            },
            {
                name: 'add_order',
                path: 'add',
                component: CreateOrder,
                props: true,
                meta: {
                    active_menu: 'orders',
                    title: 'Create Order',
                    permission: "orders/create"
                }
            }
        ]
    },
    products: {
        name: 'products',
        path: '/products',
        component: AllProducts,
        meta: {
            active_menu: 'products',
            title: 'Products',
            permission: "products/view"
        },
    },

    product_route: {
        path: '/products/:product_id',
        component: ProductRoute,
        name: 'product_route',
        meta: {
            active_menu: 'products',
            title: 'Products',
            permission: ["products/view", "products/edit", "integrations/manage"]
        },
        props: true,
        children: [
            {
                name: 'product_edit',
                path: '',
                props: true,
                component: EditProduct,
                meta: {
                    active_menu: 'products',
                    title: 'Product Edit',
                    permission: "products/edit"
                }
            },
            {
                name: 'product_integrations',
                path: 'integrations',
                props: true,
                component: ProductIntegrations,
                meta: {
                    active_menu: 'products',
                    title: 'Integrations',
                    permission: "integrations/manage"
                }
            },
            {
                name: 'product_integration_feed_editor',
                path: 'integrations/:integration_id/:integration_name',  // Fixed: removed the leading '/'
                component: FeedEditor,
                meta: {
                    active_menu: 'products',
                    title: 'Edit Integration',
                    permission: "integrations/manage"
                },
            },
            {
                name: 'SingleIntegration',
                path: 'integrations/:integration_id',
                props: true,
                component: SingleIntegration,
                meta: {
                    active_menu: 'products',
                    title: 'Integrations',
                    permission: "integrations/manage"
                }
            },
            {
                name: 'product_upgrade_paths',
                path: 'upgrade-paths',
                props: true,
                component: UpgradePaths,
                meta: {
                    active_menu: 'products',
                    title: 'Upgrade Paths',
                    permission: "integrations/manage"
                }
            },
            {
                name: 'Integrations',
                path: 'integrations',
                props: true,
                component: ProductIntegrations,
                meta: {
                    active_menu: 'integrations',
                    title: 'Product Integrations',
                    permission: "integrations/manage"
                }
            }
        ]
    },

    // product_bulk_insert: {
    //     name: 'product_bulk_insert',
    //     path: '/products/bulk-insert',
    //     component: BulkInsert,
    //     meta: {
    //         active_menu: 'products',
    //         title: 'Product Bulk Insert',
    //         permission: "products/create"
    //     },
    // },

    customers: {
        path: '/customers',
        component: CustomersRoute,
        meta: {
            active_menu: 'customers',
            permission: ["customers/view", "customers/manage"]
        },
        children: [
            {
                name: 'customers',
                path: '/customers',
                component: AllCustomers,
                meta: {
                    active_menu: 'customers',
                    title: 'Customers',
                    permission: "customers/view"
                },
            },
            {
                name: 'view_customer',
                path: ':customer_id/view',
                component: SingleCustomer,
                props: true,
                meta: {
                    active_menu: 'customers',
                    title: 'View Customer',
                    permission: "customers/view"
                }
            }
        ]
    },

    reports: {
        name: "reports",
        path: "/reports",
        component: ReportsView,
        meta: {
            active_menu: "reports",
            permission: "reports/view"
        },
        children: [
            {
                name: "reports_overview",
                path: "overview", // Empty path makes this the default child route
                component: OverviewReport,
                meta: {
                    title: "Overview Reports",
                    permission: "reports/view"
                },
            },
            {
                name: "reports_sales",
                path: "sales",
                component: DefaultReport,
                meta: {
                    title: "Sales Reports",
                    permission: "reports/view"
                },
            },
            {
                name: "reports_orders",
                path: "orders",
                component: OrderReport,
                meta: {
                    title: "Order Reports",
                    permission: "reports/view"
                },
            },
            {
                name: "reports_revenue",
                path: "revenue",
                component: RevenueReport,
                meta: {
                    title: "Revenue Report",
                    permission: "reports/view"
                },
            },
            {
                name: "reports_refunds",
                path: "refunds",
                component: RefundReport,
                meta: {
                    title: "Refund Report",
                    permission: "reports/view"
                },
            },
            {
                name: "reports_subscriptions",
                path: "subscriptions",
                component: SubscriptionReport,
                meta: {
                    title: "Subscription Report",
                    permission: "reports/view"
                }
            },
            {
                name: "future_renewals",
                path: "subscriptions/future-renewals",
                component: FutureRenewalsReport,
                meta: {
                    title: "Future Renewals",
                    permission: "reports/view"
                }
            },
            {
                name: "reports_products",
                path: "products",
                component: ProductReport,
                meta: {
                    title: "Product Report",
                    permission: "reports/view"
                },
            },
            {
                name: "reports_customer",
                path: "customer",
                component: CustomerReport,
                meta: {
                    title: "Customer Report",
                    permission: "reports/view"
                },
            },
            {
                name: "reports_sources",
                path: "sources",
                component: SourcesReport,
                meta: {
                    title: "Sources Report",
                    permission: "reports/view"
                },
            },
            // {
            //     name: "reports_subscriptions_new",
            //     path: "subscriptions_new",
            //     component: SubscriptionNew,
            //     meta: {
            //         title: "Subscription Reports",
            //         permission: "reports/view"
            //     },
            // }
        ]
    },
    // Remove the old standalone routes


    settings: {
        path: '/settings',
        component: SettingsView,
        meta: {
            active_menu: 'settings',
            title: 'Settings',
            permission: ["store/settings", 'store/sensitive']
        },
        children: [
            {
                name: 'store_settings',
                path: 'store-settings',
                component: StoreSettingsRoute,
                meta: {
                    active_menu: 'settings',
                    title: 'Settings',
                    permission: "store/settings"
                },
                children: [
                    {
                        name: 'store_setup',
                        path: '', // Full path: /settings/:settingName/store
                        component: StoreSettings,
                        meta: {}
                    },
                    {
                        name: 'pages_setup',
                        path: 'pages_setup', // Full path: /settings/:settingName/pages
                        component: StoreSettings,
                        meta: {}
                    },
                    {
                        name: 'single_product_setup',
                        path: 'single_product_setup', // Full path: /settings/:settingName/pages
                        component: StoreSettings,
                        meta: {}
                    },
                    {
                        name: 'cart_and_checkout',
                        path: 'cart_and_checkout', // Full path: /settings/:settingName/pages
                        component: StoreSettings,
                        meta: {}
                    },
                    {
                        name: 'checkout_fields',
                        path: 'checkout_fields', // Full path: /settings/:settingName/pages
                        component: CheckoutFields,
                        meta: {}
                    }
                ]
            },
            {
                name: 'email_notifications',
                path: 'email_notifications',  // Fixed: removed the leading '/'
                component: EmailNotificationSettings,
                meta: {
                    active_menu: 'settings',
                    title: 'Notifications',
                    permission: "store/sensitive"
                },
            },
            {
                name: 'email_mailing_settings',
                path: 'email_mailing_settings',  // Fixed: removed the leading '/'
                component: MailingSettings,
                meta: {
                    active_menu: 'settings',
                    title: 'Mailing Settings',
                    permission: "store/sensitive"
                },
            },
            {
                path: 'email_notifications/:name/',  // Fixed: removed the leading '/'
                name: 'email_notifications/edit',
                component: EditEmailNotification,
                meta: {
                    active_menu: 'settings',
                    title: 'Edit Notification',
                    permission: "store/sensitive"
                },
                props: true,
            },
            {
                path: 'email_notifications/add',  // Fixed: removed the leading '/'
                name: 'email_notifications/add',
                component: AddEmailNotification,
                meta: {
                    active_menu: 'settings',
                    title: 'Add Notification',
                    permission: "store/sensitive"
                },
            },
            {
                name: 'licensing',
                path: 'licensing',
                component: Licensing,
                meta: {
                    active_menu: 'settings',
                    title: 'Licensing',
                    permission: "store/sensitive"
                },
            },
            {
                name: 'payments',
                path: 'payments',  // Fixed: removed the leading '/'
                component: PaymentSettings,
                meta: {
                    active_menu: 'settings',
                    title: 'Payment Settings',
                    permission: "is_super_admin"
                },
            },
            {
                name: 'gateway',
                component: GlobalPaymentComponents,
                path: 'payments',
                children: PaymentChildRoutes,
                props: true,
                meta: {
                    active_menu: 'settings',
                    title: 'Payment Settings',
                    permission: "is_super_admin"
                },
            },
            {
                name: 'invoice-packing',
                path: 'invoice-packing',
                component: InvoicePacking,
                meta: {
                    active_menu: 'settings',
                    title: 'Invoice & Packing',
                    permission: "is_super_admin"
                },
            },

            {
                name: 'roles',
                path: 'roles',  // Fixed: removed the leading '/'
                component: RoleSettings,
                meta: {
                    active_menu: 'settings',
                    title: 'Role Settings',
                    permission: "is_super_admin"
                },
            },
            {
                name: 'addons',
                path: 'addons',  // Fixed: removed the leading '/'
                component: AddonSettings,
                meta: {
                    active_menu: 'settings',
                    title: 'Features & addon',
                    permission: "is_super_admin"
                },
            },
            {
                name: 'coupons',
                path: 'coupons',  // Fixed: removed the leading '/'
                component: AllCoupons,
                meta: {
                    active_menu: 'coupons',
                    title: 'Coupons Settings',
                    permission: "is_super_admin"
                },
            },
            {
                name: 'storage',
                path: 'storage',  // Fixed: removed the leading '/'
                component: StorageSettings,
                meta: {
                    active_menu: 'settings',
                    title: 'Storage Settings',
                    permission: "is_super_admin"
                },
            },
            {
                name: 'drivers',
                component: GlobalStorageComponents,
                path: 'storage',  // Fixed: removed the leading '/'
                children: StorageDriverChildRoutes,
                props: true,
                meta: {
                    active_menu: 'settings',
                    title: 'Storage Settings',
                    permission: "is_super_admin"
                },
            },
            {
                name: 'tax_settings',
                path: 'tax_settings',
                component: TaxSettingsRoute,
                meta: {
                    active_menu: 'settings',
                    title: 'Tax Settings',
                    permission: "store/settings"
                },
                children: [
                    {
                        name: 'tax_configurations',
                        path: '',
                        component: TaxConfigurations,
                        meta: {}
                    },
                    // {
                    //     name: 'tax_classes',
                    //     path: 'tax_classes',
                    //     component: TaxClasses,
                    //     meta: {}
                    // },
                    {
                        name: 'tax_rates',
                        path: 'tax_rates',
                        component: TaxRates,
                        meta: {}
                    },
                    {
                        name: 'eu',
                        path: 'eu',
                        component: EUVatSettings,
                        meta: {}
                    },
                    {
                        name: 'euo',
                        path: 'euo',
                        component: EUVatSettingsOld,
                        meta: {}
                    },
                    {
                        name: 'eu_country_tax_rates',
                        path: 'eu/:country/:group',
                        component: TaxRatesCountrySingle,
                        props: true,
                        meta: {}
                    },
                    {
                        name: 'tax-rates-country-single',
                        path: 'tax_rates_country_single/:country/:group', // Fixed: removed the leading '/'
                        component: TaxRatesCountrySingle,
                        props: true,
                        meta: {}
                    }
                ]
            },
        ],
    },

    integrations: {
        name: 'integrations',
        path: '/integrations',
        component: Integrations,
        meta: {
            active_menu: 'integrations',
            title: 'Integrations',
            permission: 'integrations/view'
        }
    },

    global_feed_editor: {
        name: 'feed_editor',
        path: '/global_integrations/:integration_id/:integration_name',
        component: FeedEditor,
        meta: {
            active_menu: 'integrations',
            title: 'Global Integrations',
            permission: "integrations/view"
        }
    },

    integration_editor: {
        name: 'integration_editor',
        path: '/integrations/:integration_name',
        component: IntegrationEditor,
        meta: {
            active_menu: 'integrations',
            title: 'Integrations',
            permission: 'integrations/manage'
        }
    },
    coupons: {
        name: 'coupons',
        path: '/coupons',
        component: AllCoupons,
        meta: {
            active_menu: 'coupons',
            title: 'Coupons',
            permission: "coupons/view"
        }
    },

    logs: {
        name: 'logs',
        path: '/logs',
        component: Logs,
        meta: {
            active_menu: 'logs',
            title: 'Logs',
            permission: "is_super_admin"
        }
    },
    taxes: {
        name: 'taxes',
        path: '/taxes',
        component: Taxes,
        meta: {
            active_menu: 'taxes',
            title: 'taxes',
            permission: "is_super_admin"
        }
    },

    add_or_edit_coupon_route: {
        name: "add_or_edit_coupon",
        path: "/coupons/add_or_edit_coupon/:coupon_id?",
        component: AddOrEditCoupon,
        props: true,
        meta: {
            active_menu: 'coupons',
            title: 'Coupons',
            permission: 'coupons/manage'
        }
    },
    onboarding: {
        path: "/onboarding",
        component: Onboarding,
        name: "onboarding",
        meta: {
            active_menu: "dashboard",
            title: "Onboarding",
            permission: "is_super_admin"
        },
    },

};
