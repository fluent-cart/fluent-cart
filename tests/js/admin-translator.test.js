import {beforeEach, describe, expect, it, vi} from 'vitest';
import {createI18n} from '@wordpress/i18n';

const CONTEXT_DELIMITER = '\u0004';

const configState = vi.hoisted(() => ({
    trans: {},
    env: 'production',
    wpLocale: 'en_US',
}));

vi.mock('@/utils/Config/AppConfig', () => ({
    default: {
        get(key, defaultValue) {
            if (key === 'trans') {
                return configState.trans;
            }
            if (key === 'app_config.env') {
                return configState.env;
            }
            if (key === 'wp_locale') {
                return configState.wpLocale;
            }
            return defaultValue;
        },
    },
}));

// Stands in for the wp-i18n script WordPress enqueues. createI18n() is the same
// factory core uses to build window.wp.i18n, so these tests exercise the real
// Tannin lookup rather than a hand-written imitation of it.
function installWpI18n() {
    globalThis.window.wp = {i18n: createI18n({}, 'fluent-cart')};
}

// Fresh module per test: Translator caches which `trans` object it last handed
// to setLocaleData, and that cache is module-level state.
async function loadTranslator() {
    vi.resetModules();
    return import('@/utils/translator/Translator');
}

beforeEach(() => {
    globalThis.window = {console: {warn: () => {}}};
    configState.trans = {};
    configState.env = 'production';
});

describe('translate()', () => {
    it('resolves through wp.i18n once the map is seeded', async () => {
        configState.trans = {'Orders': 'Bestellungen'};
        installWpI18n();

        const {default: translate} = await loadTranslator();

        expect(translate('Orders')).toBe('Bestellungen');
        // Proves the seeding actually reached wp.i18n rather than the fallback
        // silently returning the same value.
        expect(window.wp.i18n.__('Orders', 'fluent-cart')).toBe('Bestellungen');
    });

    it('returns the original string when nothing is translated', async () => {
        configState.trans = {'Orders': 'Orders'};
        installWpI18n();

        const {default: translate} = await loadTranslator();

        expect(translate('Orders')).toBe('Orders');
    });

    it('falls back to the legacy map when wp.i18n is absent', async () => {
        // The admin translator also ships inside add-on bundles that do not
        // declare wp-i18n as a dependency.
        configState.trans = {'Orders': 'Bestellungen'};

        const {default: translate} = await loadTranslator();

        expect(window.wp).toBeUndefined();
        expect(translate('Orders')).toBe('Bestellungen');
    });

    it('returns an unknown string unchanged with no wp.i18n and no map', async () => {
        const {default: translate} = await loadTranslator();

        expect(translate('Never extracted')).toBe('Never extracted');
    });

    it('re-seeds when the config object is swapped wholesale', async () => {
        configState.trans = {'Orders': 'Bestellungen'};
        installWpI18n();

        const {default: translate} = await loadTranslator();
        expect(translate('Orders')).toBe('Bestellungen');

        // AppConfig.setConfig() replaces the whole object; the identity check
        // in ensureLocaleData() must notice and re-seed.
        configState.trans = {'Orders': 'Commandes'};
        expect(translate('Orders')).toBe('Commandes');
    });

    it('seeds wp.i18n once across repeated lookups', async () => {
        configState.trans = {'Orders': 'Bestellungen'};
        installWpI18n();
        const spy = vi.spyOn(window.wp.i18n, 'setLocaleData');

        const {default: translate} = await loadTranslator();
        translate('Orders');
        translate('Orders');
        translate('Orders');

        expect(spy).toHaveBeenCalledTimes(1);
    });
});

describe('placeholders', () => {
    it('substitutes numbered placeholders after translation', async () => {
        configState.trans = {'%1$s of %2$s': '%1$s von %2$s'};
        installWpI18n();

        const {default: translate} = await loadTranslator();

        expect(translate('%1$s of %2$s', 3, 9)).toBe('3 von 9');
    });

    it('substitutes %s and %d positionally', async () => {
        installWpI18n();

        const {default: translate} = await loadTranslator();

        expect(translate('%s has %d items', 'Cart', 4)).toBe('Cart has 4 items');
    });

    it('keeps a placeholder when its argument is missing', async () => {
        installWpI18n();

        const {default: translate} = await loadTranslator();

        // Deliberately not an error — see applyPlaceholders().
        expect(translate('%1$s of %2$s', 3)).toBe('3 of %2$s');
    });
});

describe('_x()', () => {
    it('distinguishes the same msgid across two contexts', async () => {
        configState.trans = {
            ['Order status' + CONTEXT_DELIMITER + 'Draft']: 'Entwurf',
            ['Email' + CONTEXT_DELIMITER + 'Draft']: 'Vorlage',
        };
        installWpI18n();

        const {_x} = await loadTranslator();

        expect(_x('Draft', 'Order status')).toBe('Entwurf');
        expect(_x('Draft', 'Email')).toBe('Vorlage');
    });

    it('does not let a contextual entry leak into the plain lookup', async () => {
        configState.trans = {
            ['Order status' + CONTEXT_DELIMITER + 'Draft']: 'Entwurf',
        };
        installWpI18n();

        const {default: translate} = await loadTranslator();

        expect(translate('Draft')).toBe('Draft');
    });

    it('falls back to the plain entry when no contextual one exists', async () => {
        // A string may have been extracted without context before a context
        // was added at the call site.
        configState.trans = {'Draft': 'Entwurf'};
        installWpI18n();

        const {_x} = await loadTranslator();

        expect(_x('Draft', 'Order status')).toBe('Entwurf');
    });

    it('falls back to the legacy map when wp.i18n is absent', async () => {
        configState.trans = {
            ['Order status' + CONTEXT_DELIMITER + 'Draft']: 'Entwurf',
        };

        const {_x} = await loadTranslator();

        expect(window.wp).toBeUndefined();
        expect(_x('Draft', 'Order status')).toBe('Entwurf');
    });

    it('substitutes placeholders after the context argument', async () => {
        configState.trans = {
            ['Pagination' + CONTEXT_DELIMITER + '%1$s of %2$s']: '%1$s von %2$s',
        };
        installWpI18n();

        const {_x} = await loadTranslator();

        expect(_x('%1$s of %2$s', 'Pagination', 3, 9)).toBe('3 von 9');
    });

    it('returns the original string when the context is unknown', async () => {
        installWpI18n();

        const {_x} = await loadTranslator();

        expect(_x('Draft', 'Nowhere')).toBe('Draft');
    });
});

describe('dev-mode missing-string warning', () => {
    it('warns for a string the extractor never registered', async () => {
        configState.env = 'dev';
        const warn = vi.fn();
        globalThis.window.console = {warn};
        installWpI18n();

        const {default: translate} = await loadTranslator();
        translate('Never extracted');

        expect(warn).toHaveBeenCalledWith('Missing translation:', 'Never extracted');
    });

    it('stays quiet for a registered but untranslated string', async () => {
        configState.env = 'dev';
        configState.trans = {'Orders': 'Orders'};
        const warn = vi.fn();
        globalThis.window.console = {warn};
        installWpI18n();

        const {default: translate} = await loadTranslator();
        translate('Orders');

        expect(warn).not.toHaveBeenCalled();
    });

    it('warns using the contextual key for _x()', async () => {
        configState.env = 'dev';
        const warn = vi.fn();
        globalThis.window.console = {warn};
        installWpI18n();

        const {_x} = await loadTranslator();
        _x('Draft', 'Order status');

        expect(warn).toHaveBeenCalledWith(
            'Missing translation:',
            'Order status' + CONTEXT_DELIMITER + 'Draft'
        );
    });

    it('says nothing in production', async () => {
        const warn = vi.fn();
        globalThis.window.console = {warn};
        installWpI18n();

        const {default: translate} = await loadTranslator();
        translate('Never extracted');

        expect(warn).not.toHaveBeenCalled();
    });
});
