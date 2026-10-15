import translate from "@/utils/translator/Translator";
import LicensingUnavailable from "./LicensingUnavailable.vue";

// First Pro that ships its own licensing screens (vite.modules.config.mjs).
// Pro 2.0.0 and older load licensing from core, which no longer builds it.
// Keep this fallback for two releases after the move, then delete the module.
export const LICENSING_MIN_PRO_VERSION = '2.0.1';

const route = (name, path, title) => ({
    name,
    path,
    props: true,
    component: LicensingUnavailable,
    meta: {active_menu: 'licenses', title: translate(title), permission: 'licenses/view'},
});

// Runs after Pro's filter, so only routes Pro did not register are filled.
export function addLicensingFallbackRoutes(routes) {
    const fallbacks = {
        license_route: route('licenses', '/licenses', 'Licenses'),
        view_license: route('view_license', '/licenses/:license_id/view', 'View License'),
        view_customer_licenses: route('view_customer_licenses', '/licenses/customer/:customer_id', 'View License'),
        activated_sites: route('activated_sites', '/licenses/sites', 'Activated Sites'),
        view_site: route('view_site', '/licenses/sites/:site_id/view', 'View Site'),
    };

    if (routes.license_route) {
        return routes;
    }

    Object.assign(routes, fallbacks);

    const productChildren = routes.product_route && routes.product_route.children;
    if (productChildren && !productChildren.some((child) => child.name === 'license_settings')) {
        productChildren.push({
            ...route('license_settings', 'license_settings', 'License Settings'),
            meta: {active_menu: 'products', title: translate('License Settings'), permission: 'licenses/view'},
        });
    }

    return routes;
}
