import {describe, expect, it} from 'vitest';
import {syncSelectedLine} from '../../resources/public/product-card/product-attribute-swatches.js';

// Stand-ins for the card markup: a swatch block with one pressed option per row.
const row = (pressed) => ({querySelector: () => (pressed ? {dataset: {label: pressed}} : null)});
const block = (picks, line = {textContent: 'Selected: old'}) => ({
    line,
    querySelector: (selector) => (selector === '[data-fct-card-swatch-selected-values]' ? line : null),
    querySelectorAll: () => picks.map(row),
});

describe('Selected line below the swatch rows', () => {
    it('lists each row\'s pick in order', () => {
        const rows = block(['Navy', 'M']);
        syncSelectedLine(rows);
        expect(rows.line.textContent).toBe('Navy · M');
    });

    it('leaves out a row with no pick, e.g. a value past How many to show', () => {
        const rows = block(['Navy', null, 'Cotton']);
        syncSelectedLine(rows);
        expect(rows.line.textContent).toBe('Navy · Cotton');
    });

    it('leaves the live region alone when the line would not change, so it is not announced twice', () => {
        let writes = 0;
        const line = {
            text: 'Navy · M',
            get textContent() { return this.text; },
            set textContent(value) { writes += 1; this.text = value; },
        };
        const rows = block(['Navy', 'M'], line);
        syncSelectedLine(rows);
        syncSelectedLine(rows);
        expect(writes).toBe(0);
        syncSelectedLine(block(['Navy', 'L'], line));
        expect([writes, line.text]).toEqual([1, 'Navy · L']);
    });

    it('does nothing on cards that show the choice after the name', () => {
        expect(() => syncSelectedLine({querySelector: () => null, querySelectorAll: () => []})).not.toThrow();
        expect(() => syncSelectedLine(null)).not.toThrow();
    });
});
