import {describe, expect, it} from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

/**
 * The footer follows the table when the table moves the page on its own.
 *
 * Its "Page X of Y" label keeps a copy of the current page; the copy used to
 * change only on a click, so a tab change (which resets the table to 1) left
 * it behind - page 2 of All, then an empty Draft, read "Page 2 of 0", and
 * back on All "Page 2 of 4" over page-1 rows. The copy now follows
 * pagination.current_page, and starts from it.
 *
 * The page buttons paint their number through a stamped CSS variable. The
 * stamp used to be one-shot per element, and the pager reuses elements when
 * the page count changes - so a button stamped "2" that came to hold "4"
 * kept painting "2": a pager reading 1 2 3 2. The stamp now follows the
 * number the button holds.
 *
 * Source guards, not renders; the browser reproduction is in the PR.
 */

const FILE = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../resources/admin/Bits/Components/Pagination.vue');
const SOURCE = fs.readFileSync(FILE, 'utf8');
const SCRIPT = SOURCE.slice(SOURCE.indexOf('<script>'));

describe('the pagination footer', () => {
    it('starts its page label from the table', () => {
        expect(SCRIPT).toMatch(/currentPage:\s*this\.pagination\.current_page/);
    });

    it('follows the table when it moves the page without a click', () => {
        expect(SCRIPT).toMatch(/'pagination\.current_page'\s*\(page\)\s*\{\s*this\.currentPage = page;/);
    });

    it('re-stamps a page button whose number changed, rather than once per element', () => {
        expect(SCRIPT).toMatch(/elem\.dataset\.translatedFrom !== original/);
        expect(SCRIPT).not.toMatch(/elem\.dataset\.translated\b/);
    });

    it('re-stamps after the page count changes', () => {
        const lastPageWatcher = SCRIPT.slice(SCRIPT.indexOf("'pagination.last_page'"), SCRIPT.indexOf("'pagination.current_page'"));
        expect(lastPageWatcher).toMatch(/translatePickerElements\(\)/);
    });

    it('stamps only its own pager, not the first one on the page', () => {
        expect(SCRIPT).toMatch(/this\.\$el\.querySelectorAll\('\.el-pager li\.number'\)/);
        expect(SCRIPT).not.toMatch(/document\.querySelector\('\.el-pagination/);
    });
});
