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

describe('extension component fallback', () => {
    it('renders the default slot when no add-on registered the name', async () => {
        window.fluent_cart_admin = {hooks: createHooks()};

        const html = await renderToString(createSSRApp({
            render: () => h(ExtensionComponent, {name: 'reports.customers.value'}, {default: () => h('p', 'preview')})
        }));

        expect(html).toContain('<p>preview</p>');
    });

    it('renders the registered component instead of the slot', async () => {
        const hooks = createHooks();
        hooks.addFilter('fluent_cart_admin_components', 'pro', (components) => ({
            ...components,
            'reports.customers.value': {render: () => h('table')}
        }));
        window.fluent_cart_admin = {hooks};

        const html = await renderToString(createSSRApp({
            render: () => h(ExtensionComponent, {name: 'reports.customers.value'}, {default: () => h('p', 'preview')})
        }));

        expect(html).toContain('<table>');
        expect(html).not.toContain('preview');
    });
});

describe('shared module entries', () => {
    const SHARED = ['vue', 'dayjs', 'echarts', 'ui'];

    it.each(SHARED)('module-%s.js is a Vite input and an import-map specifier', (name) => {
        expect(read('vite.config.mjs')).toContain(`"resources/admin/shared/module-${name}.js"`);
        expect(read('app/Hooks/Handlers/ModuleImportMap.php')).toMatch(
            new RegExp(`'@fluent-cart/${name}'\\s*=> self::sharedUrl\\('module-${name}\\.js'\\)`)
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

    it('keeps every name the Pro licensing bundle imports from @fluent-cart/ui', () => {
        const source = read('resources/admin/shared/module-ui.js');
        const exported = new Set();
        for (const [, list] of source.matchAll(/export\s*\{([^}]+)\}/g)) {
            list.split(',').map((part) => part.trim().split(/\s+as\s+/).pop()).filter(Boolean).forEach((name) => exported.add(name));
        }

        // Removing one of these breaks shipped Pro; that needs FLUENTCART_MODULE_API bumped.
        const helpersUsedByPro = [
            '$confirm', 'AppConfig', 'Arr', 'Asset', 'averageRate', 'axisDayLabel', 'completeRows', 'expectedLifetimeMonths', 'headlineRow', 'periodLabel', 'chartAxisPointer', 'chartTooltipAmount', 'chartTooltipPosition', 'ChartTypeFilter',
            'Clipboard', 'csvSafe', 'dateTimeI18', 'exportCSVWithColumns', 'getEmphasisColor', 'handleResponse', 'handleSuccess', 'getXAxisConfig', 'makeXAxisLabels', 'Model', 'Notify', 'storeTimeNote', 'tooltipSuffix', 'Permission', 'Rest', 'Storage', 'Str', 'Table', 'Theme',
            'dateShortcuts', 'fluentDayjsLocale', 'formatDate', 'formatNumber', 'handleError', 'isValidURL', 'monthNames',
            'paymentMethodAdminTitle', 'pluralizeTranslate', 'prepareLabel', 'resolveDateFormat', 'translate', 'translateNumber',
            'useExport',
        ];
        const componentsUsedByPro = [
            'Badge', 'Card', 'ChartTab', 'CopyToClipboard', 'DynamicIcon', 'Empty', 'ExportDialog', 'ExportReport', 'Fluctuation', 'Heading',
            'IconButton', 'Label', 'LabelHint', 'NotFound', 'OrderCustomerInformation', 'OrderUpDownIndicator',
            'PageHeading', 'Pagination', 'ProductInfo', 'ProductSalesChart', 'ReportSummary', 'RouteCell', 'Screenshot', 'SummaryLoader',
            'TableWrapper', 'UpgradePlan', 'UserCan',
            'WpEditor',
            // R2 storage settings (Pro resources/storage, FLUENTCART_MODULE_API 3).
            'Animation', 'StepIndicator',
        ];
        exported.add('Card'); // export const Card = {Container, Header, Body, Footer}

        expect(helpersUsedByPro.filter((name) => !exported.has(name))).toEqual([]);
        expect(componentsUsedByPro.filter((name) => !exported.has(name))).toEqual([]);
        expect(source).not.toMatch(/export const Fct\b/);

        // Compiled helpers Pro's R2 storage screen needs: long static markup and v-show.
        const vue = read('resources/admin/shared/module-vue.js');
        ['createStaticVNode', 'vShow'].forEach((name) => expect(vue).toMatch(new RegExp(`\\b${name},`)));
    });

    it('exports the keyboard-accessible Alert and FluidTab for add-ons', () => {
        const source = read('resources/admin/shared/module-ui.js');

        expect(source).toMatch(/export\s*\{[^}]*\bAlert\b/);
        expect(source).toMatch(/export const FluidTab = \{Tab: FluidTabContainer, Item: FluidTabItem\};/);
    });

    it('keeps the public layout components (dev-docs/admin-design-system/spec.md)', () => {
        const source = read('resources/admin/shared/module-ui.js');
        const exported = new Set();
        for (const [, list] of source.matchAll(/export\s*\{([^}]+)\}/g)) {
            list.split(',').map((part) => part.trim()).filter(Boolean).forEach((name) => exported.add(name));
        }

        // Third-party add-ons build on these; removing one needs FLUENTCART_MODULE_API bumped.
        const layout = ['DetailLayout', 'EmptyState', 'KeyValue', 'KeyValueList', 'List', 'ListItem', 'Page', 'Section', 'SettingRow', 'SortableList', 'StatGrid', 'StatTile', 'Title', 'CoreUpdateCard'];

        expect(layout.filter((name) => !exported.has(name))).toEqual([]);

        // Add-ons gate on API 3 before importing these names.
        const api = Number(read('fluent-cart.php').match(/define\('FLUENTCART_MODULE_API',\s*(\d+)\)/)[1]);
        expect(api).toBeGreaterThanOrEqual(3);
    });

    it('keeps every name the Customer Rights withdrawal bundle imports from @fluent-cart/ui', () => {
        const source = read('resources/admin/shared/module-ui.js');
        const exported = new Set();
        for (const [, list] of source.matchAll(/export\s*\{([^}]+)\}/g)) {
            list.split(',').map((part) => part.trim().split(/\s+as\s+/).pop()).filter(Boolean).forEach((name) => exported.add(name));
        }

        // fluent-cart-customer-rights resources/admin-modules/withdrawals (FLUENTCART_MODULE_API 3).
        const usedByCustomerRights = [
            'Badge', 'CurrencyFormatter', 'DynamicIcon', 'Empty', 'IconButton', 'Notify', 'PageHeading', 'Rest',
            'Table', 'TableWrapper', 'UserCan', 'translate',
        ];

        expect(usedByCustomerRights.filter((name) => !exported.has(name))).toEqual([]);

        // Its table's compiled v-loading directive.
        const vue = read('resources/admin/shared/module-vue.js');
        ['resolveDirective', 'withDirectives'].forEach((name) => expect(vue).toMatch(new RegExp(`\\b${name},`)));
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
