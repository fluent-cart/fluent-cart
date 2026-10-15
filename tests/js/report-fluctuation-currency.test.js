import {describe, expect, it, vi} from 'vitest';
import {createSSRApp, h} from 'vue';
import {renderToString} from 'vue/server-renderer';

const config = vi.hoisted(() => ({
    currency_signs: {JPY: '¥', USD: '$'},
    currency_decimals: {JPY: 0, USD: 2},
    'shop.currency': 'USD',
    'shop.currency_sign': '$',
    'shop.currency_position': 'before',
}));

vi.mock('@/utils/Storage', () => ({default: {get: (key, fallback = '') => fallback, set: () => {}, remove: () => {}}}));
vi.mock('@/utils/http/Rest', () => ({default: {get: vi.fn(() => new Promise(() => {}))}}));
vi.mock('@/utils/Config/AppConfig', () => ({
    default: {get: (key, fallback) => (key in config ? config[key] : fallback), onShopUpdate: () => {}},
}));
vi.mock('@/Bits/Components/FilterDropdown/FilterState', () => ({default: () => ({data: {selectedFilters: {}, currency: ''}})}));
vi.mock('@/utils/translator/Translator', () => ({default: (text) => text, translateNumber: (n) => n}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {render: () => null}}));

import reportFilter from '@/Models/Reports/ReportFilterModel';
import Fluctuation from '@/Bits/Components/Fluctuation.vue';

const render = (props) => {
    const app = createSSRApp({render: () => h(Fluctuation, props)});
    app.component('el-tooltip', {setup: (_, {slots}) => () => h('div', [slots.content(), slots.default()])});
    return renderToString(app);
};

describe('previous-period amount carries the report currency sign', () => {
    it('uses the selected report currency by default', async () => {
        reportFilter.data.compareType = 'previous_period';
        reportFilter.data.currency = 'JPY';

        expect(await render({fluctuation: 12.5, amount: 284.1})).toContain('Previously ¥284');
    });

    it('uses the currency the caller names', async () => {
        reportFilter.data.compareType = 'previous_period';
        reportFilter.data.currency = 'JPY';

        expect(await render({fluctuation: 12.5, amount: 284.1, currency: 'USD'})).toContain('Previously $284.1');
    });
});
