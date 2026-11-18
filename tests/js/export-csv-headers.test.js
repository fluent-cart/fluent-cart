import {afterEach, describe, expect, it, vi} from 'vitest';

/**
 * A column header with a comma (Pro's "Expired, still installed") must stay one CSV column,
 * or every column after it shifts against the data.
 */

import {exportCSVWithColumns} from '@/utils/Export';

const exported = async (data, columns) => {
    let blob = null;
    vi.stubGlobal('document', {createElement: () => ({click: () => {}})});
    vi.spyOn(URL, 'createObjectURL').mockImplementation((value) => {
        blob = value;
        return 'blob:test';
    });
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {});

    await exportCSVWithColumns(data, columns, 'test.csv');

    return (await blob.text()).replace(/^﻿/, '').split('\n');
};

describe('exportCSVWithColumns headers', () => {
    afterEach(() => vi.unstubAllGlobals());

    it('quotes a header that holds a comma', async () => {
        const lines = await exported([{a: 1, b: 2}], [
            {header: 'Expired, still installed', accessor: 'a'},
            {header: 'Sites "live"', accessor: 'b'},
        ]);

        expect(lines).toEqual(['"Expired, still installed","Sites ""live"""', '1,2']);
    });
});
