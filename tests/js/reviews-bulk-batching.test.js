import {describe, expect, it} from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

/**
 * The admin reviews list runs a bulk action in batches, and reports and
 * recovers per review, not per batch.
 *
 * Source guard, not a render; the browser runs are in the PR.
 */

const FILE = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../resources/admin/Modules/Reviews/AllReviews.vue');
const SOURCE = fs.readFileSync(FILE, 'utf8');
const SCRIPT = SOURCE.slice(SOURCE.indexOf('<script setup>'));

describe('bulk actions on the reviews list', () => {
    it('send batches no larger than the server accepts', () => {
        const size = Number(SCRIPT.match(/const BULK_BATCH_SIZE = (\d+);/)[1]);
        expect(size).toBeGreaterThan(0);
        expect(size).toBeLessThanOrEqual(50);
    });

    it('count what the server processed, in reviews', () => {
        expect(SCRIPT).toMatch(/data\.affected \?\? data\.replied/);
        expect(SCRIPT).toMatch(/data\.skipped/);
    });

    it('carry on past a failed batch and remember its ids', () => {
        expect(SCRIPT).toMatch(/result\.failedIds\.push\(\.\.\.batches\[index\]\)/);
    });

    it('always clear the progress state, even if a batch throws', () => {
        expect(SCRIPT).toMatch(/finally \{\s*bulkProgress\.value = null;/);
    });

    it('select the unprocessed reviews again once the table reloads', () => {
        // The table is unmounted while loading, so the selection is put back
        // when its ref comes back, not when the data lands.
        expect(SCRIPT).toMatch(/watch\(reviewTableEl, \(table\) =>/);
        expect(SCRIPT).toMatch(/toggleRowSelection\(row, true\)/);
        expect(SCRIPT).toMatch(/reselectAfterFetch = result\.failedIds/);
        expect(SOURCE).toMatch(/<el-table\s+ref="reviewTableEl"/);
    });

    it('keep the reply dialog open when some replies did not go through', () => {
        const submit = SCRIPT.slice(SCRIPT.indexOf('const submitBulkReply'), SCRIPT.indexOf('const performBulkAction'));
        expect(submit).toMatch(/if \(!result\.failedIds\.length\) \{\s*showBulkReplyDialog\.value = false;/);
    });

    it('tell the admin in reviews, and that the failed ones are still selected', () => {
        expect(SCRIPT).toMatch(/were not processed and are still selected/);
        expect(SCRIPT).not.toMatch(/batches failed/);
    });
});
