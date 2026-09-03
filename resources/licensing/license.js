import translate from '@/utils/translator/Translator';
import LicenseSettings from './components/ProductLicenseSettings.vue';
import Licenses from './components/Licenses.vue';
import ViewLicense from './components/ViewLicense.vue';
import CustomerLicenses from './components/CustomerLicenses.vue';
import ActivatedSitesList from './components/ActivatedSitesList.vue';
import ViewSite from './components/ViewSite.vue';

window.fluent_cart_admin.hooks.addFilter('fluent_cart_routes', 'fluent_all_licenses', function (routes) {
    routes.product_route.children.push({
        name: 'license_settings',
        path: 'license_settings',
        props: true,
        component: LicenseSettings,
        meta: {
            active_menu: 'products',
            title: translate('License Settings'),
            permission: "store/sensitive"
        }
    });

    routes.license_route = {
        name: 'licenses',
        path: '/licenses',
        component: Licenses,
        meta: {
            active_menu: 'licenses',
            title: translate('Licenses'),
            permission: "store/sensitive"
        }
    };

    routes.view_license = {
        name: 'view_license',
        path: '/licenses/:license_id/view',
        component: ViewLicense,
        props: true,
        meta: {
            active_menu: 'licenses',
            title: translate('View License'),
            permission: "store/sensitive"
        }
    };

    routes.view_customer_licenses = {
        name: 'view_customer_licenses',
        path: '/licenses/customer/:customer_id',
        component: CustomerLicenses,
        props: true,
        meta: {
            active_menu: 'licenses',
            title: translate('View License'),
            permission: "store/sensitive"
        }
    };

    routes.activated_sites = {
        name: 'activated_sites',
        path: '/licenses/sites',
        component: ActivatedSitesList,
        meta: {
            active_menu: 'licenses',
            title: translate('Activated Sites'),
            permission: "store/sensitive"
        }
    };

    routes.view_site = {
        name: 'view_site',
        path: '/licenses/sites/:site_id/view',
        component: ViewSite,
        props: true,
        meta: {
            active_menu: 'licenses',
            title: translate('View Site'),
            permission: "store/sensitive"
        }
    };

    return routes;
});
