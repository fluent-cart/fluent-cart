import {describe, expect, it, vi} from 'vitest';

vi.mock('@/utils/translator/Translator', () => ({
    default: (text) => text,
    translateNumber: (number) => `#${number}`,
}));

import Pagination from '@/Bits/Components/Pagination.vue';

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
 */

const pageButton = (number, stampedFrom) => ({
    textContent: number,
    dataset: stampedFrom ? {translatedFrom: stampedFrom} : {},
    style: {setProperty: vi.fn()},
});

describe('the pagination footer', () => {
    it('starts its page label from the table', () => {
        expect(Pagination.data.call({pagination: {current_page: 3}}).currentPage).toBe(3);
    });

    it('follows the table when it moves the page without a click', () => {
        const footer = {currentPage: 2};

        Pagination.watch['pagination.current_page'].call(footer, 1);

        expect(footer.currentPage).toBe(1);
    });

    it('re-stamps a page button whose number changed, rather than once per element', () => {
        const reused = pageButton('4', '2');
        const unchanged = pageButton('3', '3');
        const footer = {$el: {querySelectorAll: () => [reused, unchanged]}};

        Pagination.methods.translatePickerElements.call(footer);

        expect(reused.style.setProperty).toHaveBeenCalledWith('--translated-number', '"#4"');
        expect(reused.dataset.translatedFrom).toBe('4');
        expect(unchanged.style.setProperty).not.toHaveBeenCalled();
    });

    it('re-stamps after the page count changes', () => {
        const footer = {$nextTick: (callback) => callback(), translatePickerElements: vi.fn()};
        const {handler} = Pagination.watch['pagination.last_page'];

        handler.call(footer, 4, 4);
        expect(footer.translatePickerElements).not.toHaveBeenCalled();

        handler.call(footer, 4, 2);
        expect(footer.translatePickerElements).toHaveBeenCalledTimes(1);
        expect(footer.paginationUpdating).toBe(false);
    });

    it('stamps only its own pager, not the first one on the page', () => {
        // No document global here: a document.querySelector() lookup would throw.
        const querySelectorAll = vi.fn(() => []);

        Pagination.methods.translatePickerElements.call({$el: {querySelectorAll}});

        expect(querySelectorAll).toHaveBeenCalledWith('.el-pager li.number');
    });
});
