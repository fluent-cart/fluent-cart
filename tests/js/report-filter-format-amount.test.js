import {describe, expect, it, vi} from 'vitest';

const config = vi.hoisted(() => ({
    currency_signs: {JPY: '¥', GBP: '£', USD: '$'},
    currency_decimals: {JPY: 0, GBP: 2, USD: 2},
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

import reportFilter from '@/Models/Reports/ReportFilterModel';

describe('report amounts carry the report currency sign', () => {
    it('uses the selected currency sign', () => {
        reportFilter.data.currency = 'JPY';
        expect(reportFilter.formatAmount(28410)).toBe('¥28.41K');
    });

    it('uses the base currency sign for converted totals', () => {
        reportFilter.data.currency = '__all__';
        reportFilter.data.baseCurrency = 'GBP';
        expect(reportFilter.formatAmount(275.7)).toBe('£275.7');
    });
});
