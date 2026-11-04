import {describe, expect, it} from 'vitest';
import NiceSelect from '../../resources/public/components/select/NiceSelect.js';

// Runs the real search handler on a stub instance; vitest has no DOM env here.
const search = (value) => {
    const options = ['United States (US)', 'United Kingdom (UK)', 'Canada']
        .map((text) => ({data: {text}, element: {style: {display: ''}}}));
    const select = {
        options,
        dropdown: {classList: {contains: () => true}, querySelectorAll: () => []},
        _findNext: () => null,
    };
    NiceSelect.prototype._onSearchChanged.call(select, {target: {value}});
    return options.filter((item) => item.element.style.display === '').map((item) => item.data.text);
};

describe('NiceSelect search', () => {
    it('treats "(" as plain text', () => {
        expect(search('(')).toEqual(['United States (US)', 'United Kingdom (UK)']);
    });

    it('matches the full option label', () => {
        expect(search('United States (US)')).toEqual(['United States (US)']);
    });

    it('does not throw on other regex characters', () => {
        ['[', '*', '+', '?', '\\'].forEach((text) => {
            expect(search(text)).toEqual([]);
        });
    });
});
