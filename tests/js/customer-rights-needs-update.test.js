import {beforeEach, describe, expect, it, vi} from 'vitest';
import {renderToString} from 'vue/server-renderer';
import {createSSRApp, h} from 'vue';

/**
 * The withdrawal requests screen ships with Customer Rights 1.0.0. On this core
 * an older release has no screen, so its Withdrawals menu item would land on a
 * 404; core shows an "update Customer Rights" screen on that route instead.
 * MenuHandler sets app_config.customerRightsUpdate only while such a release is active.
 */

const config = vi.hoisted(() => ({superAdmin: true}));

vi.mock('@/utils/Config/AppConfig', () => ({
    default: {get: (key) => ({'app_config.customerRightsUpdate': {installed: '0.2.0', required: '1.0.0'}, 'wp_admin_url': 'https://shop.test/wp-admin'})[key]},
}));
vi.mock('@/utils/translator/Translator', () => ({default: (value) => value}));
vi.mock('@/utils/permission/Permission', () => ({default: {isSuperAdmin: () => config.superAdmin}}));
vi.mock('@/Bits/Components/Layout/PageHeading.vue', () => ({default: {props: ['title'], render() { return h('h1', this.title); }}}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {props: ['name'], render() { return h('i', {'data-icon': this.name}); }}}));

const {default: CustomerRightsNeedsUpdate} = await import('@/Modules/Withdrawals/CustomerRightsNeedsUpdate.vue');

const render = () => renderToString(createSSRApp({render: () => h(CustomerRightsNeedsUpdate)})
    .component('fct-button', {props: ['href'], render() { return h('a', {'data-button': this.href}, this.$slots.default()); }}));

describe('Customer Rights needs an update', () => {
    beforeEach(() => {
        config.superAdmin = true;
    });

    it('shows the page heading, a card with both versions, and an update button for site admins', async () => {
        const html = await render();

        expect(html).toContain('<h1>Withdrawal Requests</h1>');
        expect(html).toContain('fct-card');
        expect(html).toContain('Update Customer Rights to manage withdrawals');
        expect(html).toContain('Installed: v0.2.0');
        expect(html).toContain('Required: v1.0.0 or later');
        expect(html).toContain('Update it to see and process them again.');
        expect(html).toContain('data-button="https://shop.test/wp-admin/plugins.php?s=fluent-cart-customer-rights&amp;plugin_status=all"');
    });

    it('asks anyone else to get an administrator, without a button', async () => {
        config.superAdmin = false;

        const html = await render();

        expect(html).toContain('Ask a site administrator to update it.');
        expect(html).not.toContain('data-button');
    });

    it('is registered only while MenuHandler flags an old release, and never over a registered route', () => {
        const fs = require('node:fs');
        const app = fs.readFileSync('resources/admin/bootstrap/app.js', 'utf8');
        const menu = fs.readFileSync('app/Hooks/Handlers/MenuHandler.php', 'utf8');

        expect(app).toMatch(/if \(AppConfig\.get\('app_config\.customerRightsUpdate'\)\)/);
        expect(app).toMatch(/routes\.withdrawal_requests = routes\.withdrawal_requests \|\|/);
        expect(app).toMatch(/permission: 'orders\/view'/);
        expect(menu).toMatch(/version_compare\(FLUENT_CART_CUSTOMER_RIGHTS_VERSION, '1\.0\.0', '<'\)\s*\? \['installed' => FLUENT_CART_CUSTOMER_RIGHTS_VERSION, 'required' => '1\.0\.0'\]\s*: null/);
    });
});
