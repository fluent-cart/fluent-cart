import translate from "@/utils/translator/Translator";
import AppConfig from "@/utils/Config/AppConfig";

const adminUrl = AppConfig.get('admin_url');

const Menu = [
    {
        "title": translate("Dashboard"),
        
        "data": {
            "type": 'link',
            "url": adminUrl,
            "description": translate("Go to dashboard"),
            "show_description": false,
        }
    },
    {
        "title": translate("Orders"),
        
        "data": {
            "type": 'link',
            "url": adminUrl + 'orders',
            "description": translate("Go to order page"),
            "show_description": false,
        }
    },
    {
        "title": translate("Customers"),
        
        "data": {
            "type": 'link',
            "url": adminUrl + 'customers',
            "description": translate("Go to customers page"),
            "show_description": false,
        }
    },
    {
        "title": translate("Products"),
        
        "data": {
            "type": 'link',
            "new_tab": true,
            "url": adminUrl + 'products',
            "description": translate("Go to products page"),
            "show_description": false,
        }
    },
    {
        "title": translate("Integrations"),
        
        "data": {
            "type": 'link',
            "url": adminUrl + 'integrations',
            "description": translate("Go to integrations page"),
            "show_description": false,
        }
    },
    {
        "title": translate("Coupons"),
        
        "data": {
            "type": 'link',
            "url": adminUrl + 'coupons',
            "description": translate("Go to coupons page"),
            "show_description": true,
        }
    },
    {
        /* translators: %s: payment gateway name */
        "title": translate('Manage %s', 'PayPal'),
        "data": {
            "type": 'link',
            "new_tab": false,
            "url": adminUrl + 'settings/payments/paypal',
            /* translators: %s: payment gateway name */
            "description": translate("Go to %s page", 'PayPal settings'),
            "show_description": false,
        }
    },
    {
        /* translators: %s: payment gateway name */
        "title": translate('Manage %s', 'Stripe'),
        "data": {
            "type": 'link',
            "new_tab": false,
            "url": adminUrl + 'settings/payments/stripe',
            /* translators: %s: payment gateway name */
            "description": translate("Go to %s page", 'stripe settings'),
            "show_description": false,
        }
    },

];

export default Menu;
