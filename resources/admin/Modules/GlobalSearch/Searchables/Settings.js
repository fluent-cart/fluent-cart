import translate from "@/utils/translator/Translator";
import AppConfig from "@/utils/Config/AppConfig";

const baseUrl = AppConfig.get('admin_url') + 'settings/';

const settingsRoutes = {
    '/settings/store-settings': translate('Store Setup'),
    '/settings/store-settings/pages_setup': translate('Pages Setup'),
    '/settings/store-settings/single_product_setup': translate('Single product & Order Setup'),
    '/settings/store-settings/cart_and_checkout': translate('Cart and Checkout'),
    '/settings/store-settings/checkout_fields': translate('Checkout Fields'),
};
let Settings = [];

Object.keys(settingsRoutes).forEach((key) => {
    const title = settingsRoutes[key];
    Settings.push({
        "title": title,

        "data": {
            "type": 'action',
            action: ({router}) => {
                //window.location.href = baseUrl+key;
                //window.location.reload();
                
                router.push({ path: key })
            },
            /* translators: %s: setting name */
            "description": translate("Go to %s", title),
            "show_description": false,
        }
    });
})

export default Settings;
