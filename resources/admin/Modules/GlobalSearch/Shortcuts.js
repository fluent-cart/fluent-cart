import translate from "@/utils/translator/Translator";

const Shortcuts = [
    {
        title: translate('Open Search'),
        data: {
            type: 'action',
            /* translators: %1$s: keyboard shortcut keys */
            description: translate('Shortcut: %1$s', '/'),
            show_description: true,
            action: () => {
                if (window.fluentCart && window.fluentCart.openSearch) {
                    window.fluentCart.openSearch();
                }
            },
        }
    },
    {
        title: translate('Go to Dashboard'),
        data: {
            type: 'action',
            /* translators: %1$s: keyboard shortcut keys */
            description: translate('Shortcut: %1$s', 'g d'),
            show_description: true,
            action: ({router}) => { router.push({path: '/'}); },
        }
    },
    {
        title: translate('Go to Orders'),
        data: {
            type: 'action',
            /* translators: %1$s: keyboard shortcut keys */
            description: translate('Shortcut: %1$s', 'g o'),
            show_description: true,
            action: ({router}) => { router.push({path: '/orders'}); },
        }
    },
    {
        title: translate('Go to Products'),
        data: {
            type: 'action',
            /* translators: %1$s: keyboard shortcut keys */
            description: translate('Shortcut: %1$s', 'g p'),
            show_description: true,
            action: ({router}) => { router.push({path: '/products'}); },
        }
    },
    {
        title: translate('Go to Customers'),
        data: {
            type: 'action',
            /* translators: %1$s: keyboard shortcut keys */
            description: translate('Shortcut: %1$s', 'g c'),
            show_description: true,
            action: ({router}) => { router.push({path: '/customers'}); },
        }
    },
    {
        title: translate('Go to Reports'),
        data: {
            type: 'action',
            /* translators: %1$s: keyboard shortcut keys */
            description: translate('Shortcut: %1$s', 'g r'),
            show_description: true,
            action: ({router}) => { router.push({path: '/reports/overview'}); },
        }
    },
    {
        title: translate('Go to Settings'),
        data: {
            type: 'action',
            /* translators: %1$s: keyboard shortcut keys */
            description: translate('Shortcut: %1$s', 'g s'),
            show_description: true,
            action: ({router}) => { router.push({path: '/settings/store-settings'}); },
        }
    },
    {
        title: translate('Go to Integrations'),
        data: {
            type: 'action',
            /* translators: %1$s: keyboard shortcut keys */
            description: translate('Shortcut: %1$s', 'g i'),
            show_description: true,
            action: ({router}) => { router.push({path: '/integrations'}); },
        }
    },
    {
        title: translate('Create New Order'),
        data: {
            type: 'action',
            /* translators: %1$s: keyboard shortcut keys */
            description: translate('Shortcut: %1$s', 'n o'),
            show_description: true,
            action: ({router}) => { router.push({path: '/orders/add'}); },
        }
    },
    {
        title: translate('Create New Coupon'),
        data: {
            type: 'action',
            /* translators: %1$s: keyboard shortcut keys */
            description: translate('Shortcut: %1$s', 'n c'),
            show_description: true,
            action: ({router}) => { router.push({path: '/coupons/add_or_edit_coupon'}); },
        }
    },
];

export default Shortcuts;
