import {describe, expect, it, vi} from 'vitest';
import {defineComponent, h, nextTick} from 'vue';

/**
 * The title-bar currency picker follows the route's `meta.report_filters` too: a report that
 * does not read currency (Pro's Licenses) must not offer it.
 */

const route = vi.hoisted(() => ({name: 'reports_licenses', meta: {}}));
const currencies = vi.hoisted(() => ({USD: 'USD', EUR: 'EUR'}));

vi.mock('vue-router', () => ({useRoute: () => route}));
vi.mock('@/utils/Storage', () => ({default: {get: (key, fallback = '') => fallback, set: () => {}, remove: () => {}}}));
vi.mock('@/utils/http/Rest', () => ({default: {get: vi.fn()}}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {get: () => '', onShopUpdate: () => {}}}));
vi.mock('@/utils/translator/Translator', () => ({default: (text) => text, translateNumber: (text) => text}));
vi.mock('@/Models/Reports/ReportFilterModel', () => ({default: {data: {currencies, baseCurrency: 'USD'}, supportsConvertedCurrency: () => false}}));
vi.mock('@/Modules/Reports/Utils/decorator', () => ({storeTimeNote: () => ''}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/FilterDropdown/FilterDropdown.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/FilterDropdown/parts/CurrencyFilter.vue', () => ({
    default: {render: () => h('div', {class: 'currency-picker'})},
}));

import {byClass, FakeNode, renderer} from './support/fake-dom-renderer.js';
import useFilterState from '@/Bits/Components/FilterDropdown/FilterState';
import ReportFilterBar from '@/Modules/Reports/Components/ReportFilterBar.vue';

const mountBar = async () => {
    const root = new FakeNode('div');
    const filterState = useFilterState();
    const Host = defineComponent({setup: () => () => h(ReportFilterBar, {filterState, showDate: false, showCompare: false})});
    const app = renderer.createApp(Host);
    ['el-button', 'el-date-picker', 'el-dropdown', 'el-dropdown-item', 'el-dropdown-menu', 'el-popover']
        .forEach(name => app.component(name, {render: () => null}));
    app.mount(root);
    await nextTick();
    return root;
};

describe('report filter bar currency picker', () => {
    it('shows with two currencies on a report that lists no filters', async () => {
        route.meta = {};

        expect(byClass(await mountBar(), 'currency-picker')).toHaveLength(1);
    });

    it('hides on a report whose filters leave currency out', async () => {
        route.meta = {report_filters: ['variation_ids']};

        expect(byClass(await mountBar(), 'currency-picker')).toHaveLength(0);
    });
});
