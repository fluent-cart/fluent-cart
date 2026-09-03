import translate from "@/utils/translator/Translator";
import permission from "@/utils/permission/Permission";

const settingsRoutes = [
    { path: '/settings/store-settings',                      title: translate('Store Setup'),                    perm: 'store/settings' },
    { path: '/settings/store-settings/pages_setup',          title: translate('Pages Setup'),                    perm: 'store/settings' },
    { path: '/settings/store-settings/single_product_setup', title: translate('Single Product & Order Setup'),   perm: 'store/settings' },
    { path: '/settings/store-settings/cart_and_checkout',    title: translate('Cart and Checkout'),              perm: 'store/settings' },
    { path: '/settings/store-settings/subscriptions',        title: translate('Subscriptions Setup'),            perm: 'store/settings' },
    { path: '/settings/store-settings/checkout_fields',      title: translate('Checkout Fields'),                perm: 'store/settings' },
    { path: '/settings/email_notifications',                 title: translate('Email Notifications'),            perm: 'store/sensitive' },
    { path: '/settings/email_mailing_settings',              title: translate('Mailing Settings'),               perm: 'store/sensitive' },
    { path: '/settings/email_mailing_settings/reminders',    title: translate('Reminders'),                      perm: 'store/sensitive' },
    { path: '/settings/licensing',                           title: translate('Licensing'),                      perm: 'store/sensitive' },
    { path: '/settings/payments',                            title: translate('Payment Settings'),               perm: 'is_super_admin' },
    { path: '/settings/invoice-packing',                     title: translate('Invoice & Packing Slips'),        perm: 'is_super_admin' },
    { path: '/settings/pdf-template',                        title: translate('PDF Templates'),                  perm: 'is_super_admin' },
    { path: '/settings/roles',                               title: translate('Role Settings'),                  perm: 'is_super_admin' },
    { path: '/settings/addons',                              title: translate('Features & Addons'),              perm: 'is_super_admin' },
    { path: '/settings/tax_settings',                        title: translate('Tax Settings'),                   perm: 'store/settings' },
    { path: '/settings/tax_settings/tax_rates',              title: translate('Tax Rates'),                      perm: 'store/settings' },
    { path: '/settings/tax_settings/eu',                     title: translate('EU VAT Settings'),                perm: 'store/settings' },
    { path: '/settings/storage',                             title: translate('Storage Providers'),              perm: 'is_super_admin' },
    { path: '/settings/coupons',                             title: translate('Coupon Settings'),                perm: 'is_super_admin' },
];

const Settings = settingsRoutes
    .filter(({ perm }) => permission.has(perm))
    .map(({ path, title }) => ({
        title,
        data: {
            type: 'action',
            action: ({ router }) => {
                router.push({ path });
            },
            /* translators: %1$s: settings page name */
            description: translate('Go to %1$s', title),
            show_description: false,
        }
    }));

export default Settings;
