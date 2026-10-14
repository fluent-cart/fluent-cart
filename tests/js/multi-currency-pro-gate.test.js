import {describe, expect, it, vi} from 'vitest';
import {renderToString} from 'vue/server-renderer';
import {createSSRApp, h} from 'vue';

/**
 * Multi Currency settings is a Pro feature: without Pro the route shows the
 * Pro feature notice instead of the settings screen (which also holds charm
 * pricing), and never loads the settings.
 *
 * Rendered through vue/server-renderer, like fluent-player-video-tab-activation.test.js.
 */

const config = vi.hoisted(() => ({isProActive: false}));
const settingsSetup = vi.hoisted(() => vi.fn());

vi.mock('@/utils/Config/AppConfig', () => ({
    default: {get: (key) => (key === 'app_config.isProActive' ? config.isProActive : undefined)},
}));
vi.mock('@/utils/translator/Translator', () => ({default: (value) => value}));
vi.mock('@/Modules/Settings/Parts/SettingsHeader.vue', () => ({
    default: {props: ['heading'], render() { return h('h2', this.heading); }},
}));
vi.mock('@/Bits/Components/ProFeatureNotice.vue', () => ({
    default: {props: ['placement'], render() { return h('div', {'data-pro-notice': this.placement}); }},
}));
vi.mock('@/Modules/Settings/MultiCurrencySettings.vue', () => ({
    default: {
        setup() {
            settingsSetup();
            return () => h('div', {'data-mc-settings': ''});
        },
    },
}));

const Route = (await import('@/Modules/Settings/MultiCurrency/MultiCurrencyRoute.vue')).default;

const render = () => renderToString(createSSRApp({render: () => h(Route)}));

describe('Multi Currency settings route', () => {
    it('shows the Pro feature notice and skips the settings screen without Pro', async () => {
        config.isProActive = false;
        settingsSetup.mockClear();

        const html = await render();

        expect(html).toContain('data-pro-notice="feature_lock_multi_currency"');
        expect(html).toContain('Multi Currency');
        expect(html).not.toContain('data-mc-settings');
        expect(settingsSetup).not.toHaveBeenCalled();
    });

    it('shows the settings screen with Pro', async () => {
        config.isProActive = true;

        const html = await render();

        expect(html).toContain('data-mc-settings');
        expect(html).not.toContain('data-pro-notice');
    });
});
