import {describe, expect, it, vi} from 'vitest';
import {createRenderer, h, ssrContextKey} from 'vue';

/**
 * Leaving Installments disposes its charts and drops its filter listener. A stale counter in the unmount handler
 * once threw a ReferenceError there, which skipped the cleanup after it.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    globalThis.window.addEventListener = globalThis.window.addEventListener || (() => {});
    globalThis.window.removeEventListener = globalThis.window.removeEventListener || (() => {});
});
vi.mock('echarts', () => ({init: () => ({setOption: () => {}, resize: () => {}, dispose: () => {}})}));
vi.mock('@/Bits/Components/Permission/UserCan.vue', () => ({default: {render: () => null}}));
vi.mock('@/Bits/Components/Card/Card.js', () => ({Container: {}, Header: {}, Body: {}}));
vi.mock('@/Bits/Components/Layout/PageHeading.vue', () => ({default: {}}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {}}));
vi.mock('@/Modules/Reports/Components/SummaryLoader.vue', () => ({default: {}}));
vi.mock('@/Bits/Components/ReportSummary.vue', () => ({default: {}}));
vi.mock('@/Bits/Components/Pagination.vue', () => ({default: {}}));
vi.mock('@/Bits/Components/FluidTab/FluidTab.js', () => ({Tab: {}, Item: {}}));
vi.mock('@/Bits/Components/Extension/ExtensionComponent.vue', () => ({default: {}}));
vi.mock('@/Modules/Reports/Components/ProReportPreview.vue', () => ({default: {}}));
vi.mock('@/Modules/Reports/Components/ProReportSample.vue', () => ({default: {}}));
vi.mock('@/utils/extensionComponents', () => ({hasExtensionComponent: () => false}));
vi.mock('@/utils/Notify', () => ({default: {error: vi.fn()}}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {get: (key, fallback) => fallback, onShopUpdate: () => {}}}));
vi.mock('@/utils/Clipboard', () => ({default: {copy: vi.fn()}}));
vi.mock('@/utils/Theme', () => ({default: {colors: {report: {}, blue: {}}, isDark: () => false}}));
vi.mock('@/utils/translator/Translator', () => ({default: text => text, pluralizeTranslate: text => text}));
vi.mock('@/utils/http/Rest', () => ({default: {get: vi.fn(() => Promise.resolve({summary: {}, schedule: [], starts: [], due_soon: {}}))}}));

import InstallmentsReport from '@/Modules/Reports/Subscription/InstallmentsReport.vue';

const Report = {...InstallmentsReport, render: () => null};
const renderer = createRenderer({
    createElement: () => ({}), createText: () => ({}), createComment: () => ({}),
    insert: () => {}, remove: () => {}, setText: () => {}, setElementText: () => {},
    parentNode: () => null, nextSibling: () => null, patchProp: () => {},
});

describe('Installments report teardown', () => {
    it('unmounts without an error and drops its filter listener', async () => {
        const filter = {
            applicableFilters: {params: {currency: 'USD'}},
            data: {},
            listeners: {},
            addListener(key, callback) { this.listeners[key] = callback; },
            removeListener(key) { delete this.listeners[key]; },
            formatAmount: value => String(value),
        };
        const app = renderer.createApp({render: () => h(Report, {reportFilter: filter})});
        const errors = [];
        app.config.errorHandler = (error) => errors.push(error);
        app.provide(ssrContextKey, {});
        app.mount({});
        await new Promise(resolve => setTimeout(resolve, 0));

        app.unmount();

        expect(errors.map(error => String(error))).toEqual([]);
        expect(filter.listeners['installments-report']).toBeUndefined();
    });
});
