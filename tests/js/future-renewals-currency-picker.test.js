import {beforeEach, describe, expect, it, vi} from 'vitest';
import {createSSRApp, h} from 'vue';
import {renderToString} from 'vue/server-renderer';

const route = vi.hoisted(() => ({name: 'future_renewals', meta: {title: 'Future Renewals'}}));
const stored = vi.hoisted(() => ({
    'report-filter': {currency: 'USD'},
    'report-filter_currencies': {USD: '$', JPY: '¥', __all__: '$'},
}));
const filterState = vi.hoisted(() => ({
    data: {selectedFilters: {}, currency: 'USD'},
    currencies: [
        {label: 'USD', value: 'USD'},
        {label: 'JPY', value: 'JPY'},
        {label: 'All currencies (in USD)', value: '__all__'},
    ],
    onFilterChanged: vi.fn(),
}));
vi.mock('vue-router', () => ({useRoute: () => route}));
vi.mock('@/utils/Storage', () => ({default: {
    get: (key, fallback = '') => stored[key] ?? fallback,
    set: (key, value) => { stored[key] = value; },
    remove: () => {},
}}));
vi.mock('@/utils/http/Rest', () => ({default: {get: vi.fn()}}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {get: () => 'USD'}}));
vi.mock('@/utils/translator/Translator', () => ({default: text => text}));
vi.mock('@/Bits/Components/FilterDropdown/FilterState', () => ({default: () => filterState}));
vi.mock('@/Bits/Components/Alert.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/AdminNotice.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/Reports/Components/ReportNavLinks.vue', () => ({default: {render: () => null}}));
vi.mock('@/Modules/Settings/Parts/SettingsHeader.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/FilterDropdown/parts/AppliedFilters.vue', () => ({default: {render: () => h('div', 'Date filters')}}));
vi.mock('@/Bits/Components/FilterDropdown/FilterDropdown.vue', () => ({default: {render: () => h('div', 'Advanced filters')}}));

import Rest from '@/utils/http/Rest';
import reportFilter from '@/Models/Reports/ReportFilterModel';
import ReportsView from '@/Modules/Reports/ReportsView.vue';

let selectCurrency;
const renderReports = () => {
    const app = createSSRApp({render: () => h(ReportsView)});
    app.config.globalProperties.$t = text => text;
    app.component('el-select', {
        props: ['modelValue'],
        emits: ['update:modelValue'],
        setup(props, {attrs, emit, slots}) {
            selectCurrency = value => {
                emit('update:modelValue', value);
                attrs.onChange(value);
            };
            return () => h('select', {'aria-label': attrs['aria-label'], value: props.modelValue}, slots.default());
        },
    });
    app.component('el-option', {
        props: ['label', 'value'],
        setup: props => () => h('option', {value: props.value}, props.label),
    });
    return renderToString(app);
};

beforeEach(() => {
    route.name = 'future_renewals';
    selectCurrency = null;
    filterState.currencies = [
        {label: 'USD', value: 'USD'},
        {label: 'JPY', value: 'JPY'},
        {label: 'All currencies (in USD)', value: '__all__'},
    ];
    reportFilter.data.currency = 'USD';
    reportFilter.data.baseCurrency = 'USD';
    reportFilter.data.currencies = {USD: '$', JPY: '¥', __all__: '$'};
    reportFilter.removeListener('picker-test');
    Rest.get.mockResolvedValue({
        currencies: {USD: '$', JPY: '¥', __all__: '$'},
        default_currency: 'USD', base_currency: 'USD', storeMode: 'test',
    });
});

describe('Future Renewals currency picker', () => {
    it.each([
        {name: 'no currencies', currencies: []},
        {name: 'one currency', currencies: [{label: 'USD', value: 'USD'}]},
        {name: 'one currency plus all', currencies: [{label: 'USD', value: 'USD'}, {label: 'All currencies (in USD)', value: '__all__'}]},
    ])('hides the picker and filter bar for $name', async ({currencies}) => {
        filterState.currencies = currencies;
        const html = await renderReports();

        expect(selectCurrency).toBeNull();
        expect(html).not.toContain('fct-report-filter-wrap');
    });

    it('shows currency options while keeping date and advanced filters hidden', async () => {
        const html = await renderReports();

        expect(html).toContain('aria-label="Currency"');
        expect(html).toContain('JPY');
        expect(html).toContain('All currencies (in USD)');
        expect(html).not.toContain('Date filters');
        expect(html).not.toContain('Advanced filters');
    });

    it.each(['JPY', '__all__'])('passes %s through the shared filters and persists the selection', async currency => {
        const reload = vi.fn();
        reportFilter.addListener('picker-test', reload);
        await renderReports();

        expect(selectCurrency).toBeTypeOf('function');
        selectCurrency(currency);
        await new Promise(resolve => setTimeout(resolve, 0));

        expect(reload).toHaveBeenCalledWith(expect.objectContaining({params: expect.objectContaining({currency})}));
        expect(reportFilter.applicableFilters.params.currency).toBe(currency);
        expect(stored['report-filter'].currency).toBe(currency);
    });

    it('preserves the existing controls on Orders', async () => {
        route.name = 'reports_orders';
        const html = await renderReports();

        expect(html).toContain('Date filters');
        expect(html).toContain('Advanced filters');
        expect(selectCurrency).toBeNull();
    });

    it('keeps the Export page filters hidden', async () => {
        route.name = 'reports_export';
        const html = await renderReports();

        expect(html).not.toContain('Date filters');
        expect(html).not.toContain('Advanced filters');
        expect(selectCurrency).toBeNull();
    });
});
