import {describe, expect, it, vi} from 'vitest';
import {createSSRApp, h} from 'vue';
import {createMemoryHistory, createRouter} from 'vue-router';
import {renderToString} from 'vue/server-renderer';

vi.mock('@/utils/translator/Translator', () => ({default: text => text}));

import CarrierSetupNotices from '@/Modules/Shipping/Components/CarrierSetupNotices.vue';
import {
    carrierAttentionLevel,
    carrierNotices,
    noticeIcon,
    noticeRoutePath,
} from '@/Modules/Shipping/Components/carrierNotices';

const Blank = {render: () => null};

// Mirrors the admin router: real routes plus the NotFound catch-all.
const makeRouter = () => createRouter({
    history: createMemoryHistory(),
    routes: [
        {path: '/settings/store-settings', name: 'store_setup', component: Blank},
        {path: '/:path(.*)*', name: 'NotFound', component: Blank},
    ],
});

const warning = {type: 'warning', message: 'Add your store address.', action: {label: 'Store settings', route: 'settings/store-settings'}};
const info = {type: 'info', message: 'Test mode is on.', action: null};
const error = {type: 'error', message: 'Token rejected.', action: null};

describe('carrier notice helpers', () => {
    it('reads the notices off a carrier payload', () => {
        expect(carrierNotices(null)).toEqual([]);
        expect(carrierNotices({})).toEqual([]);
        expect(carrierNotices({notices: 'x'})).toEqual([]);
        expect(carrierNotices({notices: [warning, {type: 'danger', message: 'x'}, {type: 'info'}, null]})).toEqual([warning]);
    });

    it('flags warning and error notices, error first, never info', () => {
        expect(carrierAttentionLevel({notices: [info]})).toBe('');
        expect(carrierAttentionLevel({notices: [info, warning]})).toBe('warning');
        expect(carrierAttentionLevel({notices: [warning, error]})).toBe('error');
        expect(carrierAttentionLevel({})).toBe('');
    });

    it('maps each type to an icon that exists', () => {
        const icons = import.meta.glob('/resources/admin/Bits/Components/Icons/*.vue');

        ['warning', 'error', 'info', 'unknown'].forEach((type) => {
            expect(icons['/resources/admin/Bits/Components/Icons/' + noticeIcon(type) + '.vue'], type).toBeTruthy();
        });
    });

    it('resolves an admin hash route and rejects one the router does not know', () => {
        const router = makeRouter();

        expect(noticeRoutePath(router, 'settings/store-settings')).toBe('/settings/store-settings');
        expect(noticeRoutePath(router, '#/settings/store-settings')).toBe('/settings/store-settings');
        expect(noticeRoutePath(router, 'settings/store')).toBeNull();
        expect(noticeRoutePath(router, '')).toBeNull();
        expect(noticeRoutePath(router, null)).toBeNull();
        expect(noticeRoutePath(null, 'settings/store-settings')).toBeNull();
    });
});

const render = async (carrier) => {
    const router = makeRouter();
    router.push('/');
    await router.isReady();

    const app = createSSRApp({render: () => h(CarrierSetupNotices, {carrier})});
    app.use(router);
    return renderToString(app);
};

describe('CarrierSetupNotices', () => {
    it('renders nothing without notices', async () => {
        expect(await render({notices: []})).not.toContain('fct-alert');
        expect(await render(null)).not.toContain('fct-alert');
    });

    it('renders each notice as a typed alert with its action as a link', async () => {
        const html = await render({notices: [warning, info]});

        expect(html.match(/class="fct-alert /g)).toHaveLength(2);
        expect(html).toContain('fct-alert-warning');
        expect(html).toContain('fct-alert-info');
        expect(html).toContain('Add your store address.');
        expect(html).toMatch(/<a[^>]*href="\/settings\/store-settings"[^>]*>\s*Store settings\s*<\/a>/);
    });

    it('renders the message as text, never as HTML', async () => {
        const html = await render({notices: [{type: 'error', message: '<img src=x onerror=alert(1)>', action: null}]});

        expect(html).not.toContain('<img');
        expect(html).toContain('&lt;img');
    });

    it('drops an action whose route does not resolve but keeps the notice', async () => {
        const html = await render({notices: [{...warning, action: {label: 'Store settings', route: 'settings/store'}}]});

        expect(html).toContain('Add your store address.');
        expect(html).not.toContain('<a');
    });
});
