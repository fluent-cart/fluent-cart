import {describe, expect, it, vi, beforeEach} from 'vitest';
import {renderToString} from 'vue/server-renderer';
import {createSSRApp, h} from 'vue';
import ElementPlus from 'element-plus';

/**
 * A Pro report section on a store without it: sample data the keyboard and screen readers skip, a "Sample data"
 * label, and a card that says why the report matters. With an older Pro the card asks for the Pro update instead.
 */

const config = vi.hoisted(() => ({values: {}}));
const permission = vi.hoisted(() => ({admin: true}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {get: (key, fallback) => (key in config.values ? config.values[key] : fallback), onShopUpdate: () => {}}}));
vi.mock('@/utils/permission/Permission', () => ({default: {isSuperAdmin: () => permission.admin}}));
vi.mock('@/utils/translator/Translator', async () => ({default: (await import('./support/reserved-words.js')).withReserved((text) => text)}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {props: ['name'], render: () => h('i')}}));

import ProReportPreview from '@/Modules/Reports/Components/ProReportPreview.vue';

const render = () => {
    const app = createSSRApp({
        render: () => h(ProReportPreview, {
            title: 'Find your best and at-risk customers',
            text: 'Groups customers by how recently, how often and how much they buy.',
            bullets: ['Who to win back', 'Who buys most'],
            placement: 'feature_lock_report_rfm',
        }, {default: () => h('div', {class: 'sample'}, 'Sample customer A')}),
    });
    app.use(ElementPlus);
    return renderToString(app);
};

describe('locked Pro report preview', () => {
    beforeEach(() => {
        config.values = {'app_config.upgrade_url': 'https://fluentcart.com/pricing/?utm_source=plugin&utm_content=x', wp_admin_url: '/wp-admin'};
        permission.admin = true;
    });

    it('shows sample data that is inert and hidden from screen readers, with a label', async () => {
        const html = await render();

        expect(html).toMatch(/<div[^>]*class="fct-pro-preview-sample"[^>]*>/);
        expect(html).toMatch(/class="fct-pro-preview-sample"[^>]*inert/);
        expect(html).toMatch(/class="fct-pro-preview-sample"[^>]*aria-hidden="true"/);
        expect(html).toContain('Sample customer A');
        expect(html).toContain('Sample data');
    });

    it('explains the report and links to the upgrade with its placement', async () => {
        const html = await render();

        expect(html).toContain('Find your best and at-risk customers');
        expect(html).toContain('<li>Who to win back</li>');
        expect(html).toContain('utm_content=feature_lock_report_rfm');
        expect(html).toContain('Upgrade to Pro');
    });

    it('asks a site admin to update an older Pro that lacks the report', async () => {
        config.values['app_config.isProActive'] = true;

        const html = await render();

        expect(html).toContain('Update FluentCart Pro');
        expect(html).toContain('plugins.php?s=fluent-cart');
        expect(html).not.toContain('Upgrade to Pro');
    });

    it('asks other roles to contact a site admin, with no button', async () => {
        config.values['app_config.isProActive'] = true;
        permission.admin = false;

        const html = await render();

        expect(html).toContain('Ask a site administrator to update FluentCart Pro');
        expect(html).not.toContain('Update FluentCart Pro</span>');
        expect(html).not.toContain('plugins.php');
    });
});
