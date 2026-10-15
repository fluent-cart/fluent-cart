import {describe, expect, it, vi} from 'vitest';
import {renderToString} from 'vue/server-renderer';
import {createSSRApp, h} from 'vue';

/**
 * Licensing's screens moved to Pro. Pro 2.0.0 and older still expect core's
 * licensing bundle, which core no longer builds, so a site that updates core
 * before Pro would land on blank licensing routes. Core fills any licensing
 * route Pro did not register with a screen that says what is needed.
 */

const config = vi.hoisted(() => ({isProActive: true, proVersion: '2.0.0', wpAdminUrl: 'https://shop.test/wp-admin', superAdmin: true}));

vi.mock('@/utils/Config/AppConfig', () => ({
    default: {get: (key) => ({
        'app_config.isProActive': config.isProActive,
        'app_config.proVersion': config.proVersion,
        'wp_admin_url': config.wpAdminUrl,
    })[key]},
}));
vi.mock('@/utils/translator/Translator', () => ({default: (value) => value}));
vi.mock('@/utils/permission/Permission', () => ({default: {isSuperAdmin: () => config.superAdmin}}));
vi.mock('@/Bits/Components/ProFeatureNotice.vue', () => ({
    default: {
        props: {title: String, text: String, placement: String, buttonText: String, buttonUrl: String, showButton: {type: Boolean, default: true}},
        render() {
            return h('div', {'data-pro-notice': this.placement, 'data-button-url': this.buttonUrl || ''}, [this.title, ' | ', this.text, ' | ', this.showButton ? (this.buttonText || 'Upgrade to Pro') : '(no button)']);
        },
    },
}));

const {addLicensingFallbackRoutes, LICENSING_MIN_PRO_VERSION} = await import('@/Modules/Licensing/legacyLicensingRoutes.js');
const {default: LicensingUnavailable} = await import('@/Modules/Licensing/LicensingUnavailable.vue');

const baseRoutes = () => ({product_route: {children: [{name: 'product_edit', path: 'edit'}]}});

const render = (component) => renderToString(createSSRApp({render: () => h(component)}).component('el-alert', {
    props: ['title', 'description'],
    render() { return h('div', {'data-alert': this.title}, this.description); },
}));

describe('licensing fallback routes', () => {
    it('adds every licensing route Pro did not register', () => {
        const routes = addLicensingFallbackRoutes(baseRoutes());

        expect(['license_route', 'view_license', 'view_customer_licenses', 'activated_sites', 'view_site'].map((key) => routes[key].path)).toEqual([
            '/licenses', '/licenses/:license_id/view', '/licenses/customer/:customer_id', '/licenses/sites', '/licenses/sites/:site_id/view',
        ]);
        expect(routes.product_route.children.map((route) => route.name)).toEqual(['product_edit', 'license_settings']);
        expect(routes.license_route.component).toBe(LicensingUnavailable);
        // Same as the Licenses menu: `store/sensitive` locked out roles that can see it.
        ['license_route', 'view_license', 'view_customer_licenses', 'activated_sites', 'view_site'].forEach((key) => expect(routes[key].meta.permission).toBe('licenses/view'));
        expect(routes.product_route.children[1].meta.permission).toBe('licenses/view');
    });

    it('leaves the routes alone when Pro registered them', () => {
        const ProLicenses = {name: 'ProLicenses'};
        const ProSettings = {name: 'ProSettings'};
        const routes = baseRoutes();
        routes.license_route = {name: 'licenses', path: '/licenses', component: ProLicenses};
        routes.product_route.children.push({name: 'license_settings', path: 'license_settings', component: ProSettings});

        const result = addLicensingFallbackRoutes(routes);

        expect(result.license_route.component).toBe(ProLicenses);
        expect(result.view_license).toBeUndefined();
        expect(result.product_route.children.filter((route) => route.name === 'license_settings')).toHaveLength(1);
    });

    it('shows the Pro banner with an update button on a Pro that still expects core\'s bundle', async () => {
        config.isProActive = true;
        config.proVersion = '2.0.0';
        config.superAdmin = true;

        const html = await render(LicensingUnavailable);

        expect(LICENSING_MIN_PRO_VERSION.localeCompare('2.0.0', undefined, {numeric: true})).toBe(1);
        expect(html).toContain('data-pro-notice="feature_lock_licensing"');
        expect(html).toContain(`FluentCart Pro v${LICENSING_MIN_PRO_VERSION} or later`);
        expect(html).toContain('Update FluentCart Pro');
        expect(html).toMatch(/data-button-url="https:\/\/shop\.test\/wp-admin\/plugins\.php\?s=fluent-cart&amp;plugin_status=all&amp;fluent-cart-pro-check-update=\d+"/);
        expect(html).not.toContain('data-alert');
    });

    it('asks users who cannot update plugins to get an administrator, without a button', async () => {
        config.isProActive = true;
        config.proVersion = '2.0.0';
        config.superAdmin = false;

        const html = await render(LicensingUnavailable);

        expect(html).toContain('data-pro-notice="feature_lock_licensing"');
        expect(html).toContain('Ask a site administrator to update FluentCart Pro');
        expect(html).toContain('(no button)');
        expect(html).not.toContain('Update FluentCart Pro |');
    });

    it('shows the Pro notice without Pro', async () => {
        config.isProActive = false;
        config.proVersion = null;

        const html = await render(LicensingUnavailable);

        expect(html).toContain('data-pro-notice="feature_lock_licensing"');
    });

    it('points at the module toggle on a current Pro with licensing switched off', async () => {
        config.isProActive = true;
        config.proVersion = LICENSING_MIN_PRO_VERSION;

        const html = await render(LicensingUnavailable);

        expect(html).toContain('Licensing module is turned off');
        expect(html).not.toContain('data-alert="Update Required"');
    });
});
