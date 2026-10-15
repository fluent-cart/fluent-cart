import fs from 'node:fs';
import path from 'node:path';
import {afterEach, describe, expect, it} from 'vitest';
import {renderToString} from 'vue/server-renderer';
import {createSSRApp, h} from 'vue';
import {createHooks} from '@wordpress/hooks';
import {getExtensionComponent, hasExtensionComponent} from '@/utils/extensionComponents';
import ExtensionComponent from '@/Bits/Components/Extension/ExtensionComponent.vue';
import {sharedModuleGuardPlugin} from '../../build/vite/plugins/sharedModuleGuardPlugin.mjs';

/**
 * Licensing's admin screens moved to Pro, which builds them against core's
 * runtime through the @fluent-cart/* import map. Core keeps the seams: the
 * shared entries, the `fluent_cart_admin_components` filter that hands Pro's
 * license table to core screens, and a build guard that keeps the shared
 * entries reachable only through the map (one Vue instance).
 */

const ROOT = path.resolve(__dirname, '../..');
const read = (file) => fs.readFileSync(path.join(ROOT, file), 'utf8');

afterEach(() => {
    delete window.fluent_cart_admin;
});

globalThis.window = globalThis.window || globalThis;

describe('extension components', () => {
    it('returns nothing when no add-on registered the name', () => {
        window.fluent_cart_admin = {hooks: createHooks()};

        expect(getExtensionComponent('license_table')).toBeNull();
        expect(hasExtensionComponent('license_table')).toBe(false);
    });

    it('returns nothing when the hooks are not booted', () => {
        expect(getExtensionComponent('license_table')).toBeNull();
    });

    it('returns the component an add-on registered', () => {
        const LicenseTable = {render: () => h('table')};
        const hooks = createHooks();
        hooks.addFilter('fluent_cart_admin_components', 'pro', (components) => ({...components, license_table: LicenseTable}));
        window.fluent_cart_admin = {hooks};

        expect(getExtensionComponent('license_table')).toBe(LicenseTable);
        expect(hasExtensionComponent('license_table')).toBe(true);
    });

    it('renders the registered component with the passed props', async () => {
        const hooks = createHooks();
        hooks.addFilter('fluent_cart_admin_components', 'pro', (components) => ({
            ...components,
            license_table: {
                props: ['licenses', 'columns'],
                render() {
                    return h('div', {'data-count': this.licenses.length, 'data-columns': this.columns.join(',')});
                }
            }
        }));
        window.fluent_cart_admin = {hooks};

        const html = await renderToString(createSSRApp({
            render: () => h(ExtensionComponent, {name: 'license_table', licenses: [{id: 1}, {id: 2}], columns: ['product']})
        }));

        expect(html).toContain('data-count="2"');
        expect(html).toContain('data-columns="product"');
    });

    it('renders nothing without a registered component', async () => {
        window.fluent_cart_admin = {hooks: createHooks()};

        const html = await renderToString(createSSRApp({
            render: () => h('section', [h(ExtensionComponent, {name: 'license_table', licenses: []})])
        }));

        expect(html).toMatch(/^<section><!--[^>]*--><\/section>$/);
    });
});

describe('core screens take the license table from the seam, or explain why it is missing', () => {
    it.each([
        'resources/admin/Modules/Orders/SingleOrder.vue',
        'resources/admin/Modules/Subscriptions/SingleSubscription.vue',
        'resources/admin/Modules/Customers/SingleCustomer.vue',
    ])('%s', (file) => {
        const source = read(file);

        expect(source).not.toMatch(/licensing\/components/);
        expect(source).toMatch(/<ExtensionComponent\s+v-if="hasLicenseTable"\s+name="license_table"/);
        // Old Pro has license data but no table: keep the card and say what's needed.
        expect(source).not.toMatch(/licenses && hasLicenseTable/);
        // License rows are licenses/view data, even when the record itself is viewable.
        expect(source).toMatch(/licenses && canViewLicenses"/);
        expect(source).toMatch(/Permission\.has\('licenses\/view'\)/);
        expect(source).toMatch(/<LicensingUnavailable\s+v-else\s*\/>/);
        expect(source).toMatch(/import LicensingUnavailable from "@\/Modules\/Licensing\/LicensingUnavailable\.vue"/);
    });

    it('no core bundle imports licensing sources any more', () => {
        expect(fs.existsSync(path.join(ROOT, 'resources/licensing'))).toBe(false);
        expect(read('vite.config.mjs')).not.toContain('resources/licensing');
    });
});

describe('shared module entries', () => {
    const SHARED = ['vue', 'dayjs', 'echarts', 'ui'];

    it.each(SHARED)('module-%s.js is a Vite input and an import-map specifier', (name) => {
        expect(read('vite.config.mjs')).toContain(`"resources/admin/shared/module-${name}.js"`);
        expect(read('app/Hooks/Handlers/ModuleImportMap.php')).toMatch(
            new RegExp(`'@fluent-cart/${name}'\\s*=> static::sharedUrl\\('module-${name}\\.js'\\)`)
        );
    });

    it('nothing in core imports a shared entry', () => {
        const offenders = [];
        const walk = (dir) => {
            for (const entry of fs.readdirSync(path.join(ROOT, dir), {withFileTypes: true})) {
                const rel = path.join(dir, entry.name);
                if (entry.isDirectory()) {
                    walk(rel);
                } else if (/\.(js|jsx|vue)$/.test(entry.name) && /shared\/module-/.test(read(rel))) {
                    offenders.push(rel);
                }
            }
        };
        walk('resources');

        expect(offenders).toEqual([]);
    });

    it.each(SHARED)('module-%s.js names its exports instead of re-exporting a whole package', (name) => {
        // `export *` would make the build keep every export of the package.
        expect(read(`resources/admin/shared/module-${name}.js`)).not.toMatch(/export\s*\*/);
    });

    it('drops the specifiers add-ons do not need', () => {
        const map = read('app/Hooks/Handlers/ModuleImportMap.php');

        // Element Plus and the router are registered on core's app; icons are bundled by the add-on.
        expect(map).not.toMatch(/@fluent-cart\/(element-plus|vue-router)/);
        ['vue-router', 'element-plus', 'element-plus-icons'].forEach((name) => {
            expect(fs.existsSync(path.join(ROOT, `resources/admin/shared/module-${name}.js`))).toBe(false);
        });
    });

    it('keeps every name the Pro licensing bundle imports from @fluent-cart/ui', () => {
        const source = read('resources/admin/shared/module-ui.js');
        const exported = new Set();
        for (const [, list] of source.matchAll(/export\s*\{([^}]+)\}/g)) {
            list.split(',').map((part) => part.trim().split(/\s+as\s+/).pop()).filter(Boolean).forEach((name) => exported.add(name));
        }

        // Removing one of these breaks shipped Pro; that needs FLUENTCART_MODULE_API bumped.
        const helpersUsedByPro = [
            'AppConfig', 'Arr', 'Asset', 'Clipboard', 'Model', 'Permission', 'Rest', 'Storage', 'Str', 'Table', 'Theme',
            'dateShortcuts', 'fluentDayjsLocale', 'formatDate', 'handleError', 'isValidURL', 'monthNames',
            'paymentMethodAdminTitle', 'resolveDateFormat', 'translate', 'translateNumber', 'useExport',
        ];
        const componentsUsedByPro = [
            'Badge', 'Card', 'ChartTab', 'CopyToClipboard', 'DynamicIcon', 'Empty', 'ExportDialog', 'Heading',
            'IconButton', 'Label', 'LabelHint', 'NotFound', 'OrderCustomerInformation', 'OrderUpDownIndicator',
            'PageHeading', 'Pagination', 'ProductInfo', 'RouteCell', 'TableWrapper', 'UpgradePlan', 'UserCan', 'WpEditor',
        ];
        exported.add('Card'); // export const Card = {Container, Header, Body, Footer}

        expect(helpersUsedByPro.filter((name) => !exported.has(name))).toEqual([]);
        expect(componentsUsedByPro.filter((name) => !exported.has(name))).toEqual([]);
        expect(source).not.toMatch(/export const Fct\b/);
    });
});

describe('sharedModuleGuardPlugin', () => {
    const run = (bundle) => {
        const errors = [];
        sharedModuleGuardPlugin().generateBundle.call({error: (message) => errors.push(message)}, {}, bundle);
        return errors;
    };
    const chunk = (fileName, imports = [], isEntry = false) => ({type: 'chunk', fileName, imports, dynamicImports: [], isEntry});

    it('passes when shared entries are thin facades over vendor chunks', () => {
        expect(run({
            'module-vue.js': chunk('module-vue.js', ['chunks/vendor-vue.js'], true),
            'app.js': chunk('app.js', ['chunks/vendor-vue.js'], true),
            'chunks/vendor-vue.js': chunk('chunks/vendor-vue.js'),
        })).toEqual([]);
    });

    it('fails when core code imports a shared entry', () => {
        const errors = run({
            'module-vue.js': chunk('module-vue.js', [], true),
            'app.js': chunk('app.js', ['module-vue.js'], true),
        });

        expect(errors).toHaveLength(1);
        expect(errors[0]).toContain('app.js -> module-vue.js');
    });
});
