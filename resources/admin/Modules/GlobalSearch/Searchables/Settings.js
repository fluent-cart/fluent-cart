import translate from "@/utils/translator/Translator";

const settingsRoutes = {
    '/settings/store-settings':                        translate('Store Setup'),
    '/settings/store-settings/pages_setup':            translate('Pages Setup'),
    '/settings/store-settings/single_product_setup':   translate('Single Product & Order Setup'),
    '/settings/store-settings/cart_and_checkout':      translate('Cart and Checkout'),
    '/settings/store-settings/subscriptions_setup':    translate('Subscriptions Setup'),
    '/settings/store-settings/checkout_fields':        translate('Checkout Fields'),
    '/settings/email_notifications':                   translate('Email Notifications'),
    '/settings/email_mailing_settings':                translate('Mailing Settings'),
    '/settings/email_mailing_settings/reminders':      translate('Reminders'),
    '/settings/licensing':                             translate('Licensing'),
    '/settings/payments':                              translate('Payment Settings'),
    '/settings/invoice-packing':                       translate('Invoice & Packing Slips'),
    '/settings/pdf-template':                          translate('PDF Templates'),
    '/settings/roles':                                 translate('Role Settings'),
    '/settings/addons':                                translate('Features & Addons'),
    '/settings/tax_settings':                          translate('Tax Settings'),
    '/settings/tax_settings/tax_rates':                translate('Tax Rates'),
    '/settings/tax_settings/eu':                       translate('EU VAT Settings'),
    '/settings/storage':                               translate('Storage Providers'),
    '/settings/coupons':                               translate('Coupon Settings'),
};

let Settings = [];

Object.keys(settingsRoutes).forEach((key) => {
    const title = settingsRoutes[key];
    Settings.push({
        title,
        data: {
            type: 'action',
            action: ({router}) => {
                router.push({path: key});
            },
            /* translators: %1$s: settings page name */
            description: translate('Go to %1$s', title),
            show_description: false,
        }
    });
});

export default Settings;
