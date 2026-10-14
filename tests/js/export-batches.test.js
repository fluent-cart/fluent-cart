import {beforeEach, describe, expect, it, vi} from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

/**
 * The browser export (useExport + BrowserExportWriter) behind Orders,
 * Customers, Subscriptions and Reports → Export (the accounting export).
 *
 * The accounting export returns a batch with no rows while more remain:
 * a period with refunds but no sales, a sales phase that ends exactly on a
 * batch boundary, or a tax-scheme filter that matches nothing in one batch.
 * The loop must follow the cursor until has_more is false, and refund rows
 * are negative numbers that must stay numbers in the CSV.
 */

const rest = vi.hoisted(() => ({post: vi.fn(), get: vi.fn()}));
const notify = vi.hoisted(() => ({info: vi.fn()}));

vi.mock('@/utils/http/Rest', () => ({default: rest}));
vi.mock('@/utils/Notify', () => ({default: notify}));
vi.mock('@/utils/Config/AppConfig', () => ({default: {get: () => true}}));
vi.mock('@/utils/translator/Translator', () => ({default: (text) => text}));
vi.mock('vue', async (importOriginal) => ({...(await importOriginal()), onBeforeUnmount: () => {}}));

import {useExport} from '@/utils/export/useExport';
import BrowserExportWriter from '@/utils/export/BrowserExportWriter';

const written = [];

beforeEach(() => {
    globalThis.window = {};
});

const schema = {
    entity: 'accounting',
    schema_version: 1,
    csv_columns: [{key: 'row_type', label: 'Row type'}],
    json_modules: [],
    batching: {initial_records: 100},
};

const batch = (records, cursor, hasMore, total = 0) => ({
    export: {records, total, has_more: hasMore, cursor, batch: {next_max: 100}},
});

const runExport = async () => {
    rest.get.mockResolvedValue({export: schema});
    const state = useExport({entity: 'accounting', filenamePrefix: 'accounting-export', buildAllParams: () => ({}), buildParams: () => ({})});
    await state.loadSchema();
    await state.startExport({format: 'csv', scope: 'all', columns: ['row_type'], modules: []});

    return state;
};

describe('useExport batches', () => {
    beforeEach(() => {
        written.length = 0;
        rest.post.mockReset();
        rest.get.mockReset();
        notify.info.mockReset();
        vi.spyOn(BrowserExportWriter.prototype, 'close').mockResolvedValue(undefined);
        vi.spyOn(BrowserExportWriter.prototype, 'writeRecords').mockImplementation(function (records) {
            written.push(...records);
            return Promise.resolve();
        });
    });

    it('follows the cursor past a batch with no rows while the server has more', async () => {
        rest.post
            .mockResolvedValueOnce(batch([{row_type: 'sale'}], 'sales:100', true))
            // the sales phase ended exactly on the batch boundary
            .mockResolvedValueOnce(batch([], 'refunds:0', true))
            .mockResolvedValueOnce(batch([{row_type: 'refund'}], '', false));

        const state = await runExport();

        expect(rest.post.mock.calls.map((call) => call[1].cursor)).toEqual([0, 'sales:100', 'refunds:0']);
        expect(written.map((row) => row.row_type)).toEqual(['sale', 'refund']);
        expect(state.isComplete.value).toBe(true);
    });

    it('follows the cursor past an empty batch when the server also sent a total', async () => {
        rest.post
            .mockResolvedValueOnce(batch([{row_type: 'sale'}], 'sales:100', true, 2))
            .mockResolvedValueOnce(batch([], 'refunds:0', true, 2))
            .mockResolvedValueOnce(batch([{row_type: 'refund'}], '', false, 2));

        const state = await runExport();

        expect(rest.post.mock.calls.map((call) => call[1].cursor)).toEqual([0, 'sales:100', 'refunds:0']);
        expect(written.map((row) => row.row_type)).toEqual(['sale', 'refund']);
        expect(state.isComplete.value).toBe(true);
    });

    it('exports a period whose first batch is empty but whose refunds are not', async () => {
        rest.post
            .mockResolvedValueOnce(batch([], 'refunds:0', true))
            .mockResolvedValueOnce(batch([{row_type: 'refund'}], '', false));

        const state = await runExport();

        expect(written.map((row) => row.row_type)).toEqual(['refund']);
        expect(notify.info).not.toHaveBeenCalled();
        expect(state.isComplete.value).toBe(true);
    });

    it('still reports no data when the server finishes with nothing', async () => {
        rest.post
            .mockResolvedValueOnce(batch([], 'refunds:0', true))
            .mockResolvedValueOnce(batch([], '', false));

        const state = await runExport();

        expect(notify.info).toHaveBeenCalledWith('No data to export');
        expect(state.isComplete.value).toBe(false);
    });

    it('stops instead of looping when an empty batch does not move the cursor', async () => {
        rest.post.mockResolvedValue(batch([], 'sales:5', true));

        await runExport();

        expect(rest.post.mock.calls.length).toBeLessThanOrEqual(2);
    });
});

describe('useExport filter snapshot', () => {
    beforeEach(() => {
        written.length = 0;
        rest.post.mockReset();
        rest.get.mockReset();
        vi.spyOn(BrowserExportWriter.prototype, 'close').mockResolvedValue(undefined);
        vi.spyOn(BrowserExportWriter.prototype, 'writeRecords').mockImplementation(function (records) {
            written.push(...records);
            return Promise.resolve();
        });
    });

    // Every batch of one file must use the filters the export started with:
    // a filter changed mid-export kept the cursor and the rows already
    // written, so the file silently mixed two periods or tax schemes.
    it('sends every batch with the filters the export started with', async () => {
        const filters = {date_from: '2026-04-01', date_to: '2026-06-30', scheme: 'oss', include_test: 'no', nested: {status: ['paid']}};
        const build = () => filters;

        rest.get.mockResolvedValue({export: schema});
        rest.post
            .mockImplementationOnce(() => {
                // the merchant edits the form while batch 1 is in flight
                filters.date_from = '2026-07-01';
                filters.date_to = '2026-09-30';
                filters.scheme = 'all';
                filters.include_test = 'yes';
                filters.nested.status.push('refunded');
                return Promise.resolve(batch([{row_type: 'sale'}], 'sales:100', true));
            })
            .mockResolvedValueOnce(batch([{row_type: 'refund'}], '', false));

        const state = useExport({entity: 'accounting', filenamePrefix: 'accounting-export', buildAllParams: build, buildParams: build});
        await state.loadSchema();
        await state.startExport({format: 'csv', scope: 'all', columns: ['row_type'], modules: []});

        const sent = rest.post.mock.calls.map((call) => call[1].filters);
        const original = {date_from: '2026-04-01', date_to: '2026-06-30', scheme: 'oss', include_test: 'no', nested: {status: ['paid']}};
        expect(sent).toEqual([original, original]);
        expect(state.isComplete.value).toBe(true);
    });
});

describe('AccountingExport form while exporting', () => {
    // @vue/test-utils is not a dependency and the environment is `node`, so
    // this is a source-contract check (as in license-renew-gating.test.js):
    // every filter control and quarter shortcut is disabled during an export.
    const source = fs.readFileSync(
        path.join(path.dirname(fileURLToPath(import.meta.url)), '../../resources/admin/Modules/Reports/AccountingExport.vue'),
        'utf8'
    );
    const tagsOf = (tag) => source.match(new RegExp(`<${tag}[\\s\\S]*?>`, 'g')) || [];

    it('disables the period, quarter shortcuts, scheme, format and test-mode controls', () => {
        const controls = [
            ...tagsOf('el-date-picker'),
            ...tagsOf('el-button size="small"'),
            ...tagsOf('el-select'),
            ...tagsOf('el-radio-group'),
            ...tagsOf('el-checkbox'),
        ];

        expect(controls.length).toBe(6);
        controls.forEach((tag) => expect(tag).toMatch(/:disabled="exportState\.isExporting\.value"/));
    });
});

describe('BrowserExportWriter CSV cells', () => {
    const csvOf = async (record) => {
        const writer = new BrowserExportWriter({
            filename: 'x.csv',
            format: 'csv',
            columns: Object.keys(record).map((key) => ({key, label: key})),
        });
        await writer.writeRecords([record]);

        return new TextDecoder().decode(writer.parts[0]).trim();
    };

    it('keeps a negative amount a number', async () => {
        expect(await csvOf({tax: '-10.00', order_gross: '-60', rate: '-0.5'})).toBe('-10.00,-60,-0.5');
    });

    it('still neutralises text a spreadsheet would run', async () => {
        expect(await csvOf({a: '=HYPERLINK("x")', b: '-2+3', c: '+1', d: '@SUM(A1)', e: ' =1+1'}))
            .toBe(`"'=HYPERLINK(""x"")",'-2+3,'+1,'@SUM(A1),' =1+1`);
    });
});
