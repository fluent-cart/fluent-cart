import {describe, expect, it} from 'vitest';
import * as sass from 'sass';
import {fileURLToPath} from 'node:url';

// Layout cards break below about 200px (QA re-test, 5–6 per row). A grid of them keeps the columns the block
// asks for while each card gets 220px, and drops a column before a card gets narrower; a browser measured it.
const css = sass.compile(fileURLToPath(new URL('../../resources/public/product-card/style/card-layouts.scss', import.meta.url)), {style: 'expanded'}).css;
const flat = css.replace(/\s+/g, ' ');

describe('card layout grid columns', () => {
    it('limits every grid of layout cards, on the page and in the editors, but not list view', () => {
        const rule = flat.match(/:is\(([^)]*)\):has\(\.fct-card-layout\):not\(\.mode-list \*\) \{([^}]*)\}/);
        expect(rule, 'the layout grid rule').not.toBeNull();
        for (const grid of ['.fct-products-container', '.shop-app-preview-products', '.fct-product-list', '.fct-related-products-grid']) {
            expect(rule[1]).toContain(grid);
        }
        expect(rule[2]).toContain('--fct-layout-card-min: 220px');
        // auto-fill with a minimum of a column's share: never more columns than asked, fewer when a card would get narrower.
        // Sass drops the calc() inside max().
        expect(rule[2]).toMatch(/grid-template-columns: repeat\(auto-fill, minmax\(max\(min\(var\(--fct-layout-card-min\), 100%\), (calc)?\(\(?100% - \(var\(--fct-layout-columns\) - 1\) \* var\(--fct-layout-gap\)\) \/ var\(--fct-layout-columns\) - 1px\)?, 1fr\)\) !important/);
    });

    it('keeps the columns the block asks for, with no cap, so a wide section shows them all', () => {
        for (const n of [1, 2, 4, 5, 6]) {
            expect(flat).toMatch(new RegExp(`\\.grid-columns-${n} \\{ --fct-layout-columns: ${n};`));
        }
        expect(flat).toContain('--fct-layout-columns: var(--fct-product-list-columns, 4)');
        expect(flat).toContain('--fct-layout-columns: var(--fct-related-product-columns, 4)');
        expect(flat).not.toMatch(/--fct-layout-columns: min\(/);
    });

    it('uses the grids\' own gap from theme spacing, not rem', () => {
        expect(flat).toMatch(/--fct-layout-gap: var\(--fct-grid-gap, theme\(["']spacing\.6["']\)\)/);
        expect(flat).not.toMatch(/--fct-layout-gap:[^;]*rem/);
    });

    it('lets two options wrap onto their own lines instead of breaking a word', () => {
        expect(flat).toMatch(/\.fct-card-swatch-list:is\(:has\(> \.fct-card-swatch-more:nth-child\(3\):last-child\), :has\(> :is\(button\.fct-card-swatch\[type=button\]\[data-fct-card-swatch\], span\.fct-card-swatch\):nth-child\(2\):last-child\)\) \{ @apply flex flex-wrap;/);
    });

    it('lets the footer reach the bottom on classic themes, which wrap a group\'s blocks', () => {
        // A Bricks carousel lined its buttons up only once the details panel's wrapper stopped breaking its column.
        for (const group of ['footer', 'meta', 'details']) {
            expect(flat).toMatch(new RegExp(`\\.fct-card-layout-${group} > \\.wp-block-group__inner-container[^{]*\\{[^}]*display: contents`));
        }
    });

    it('reads Bricks Products\' columns, which it sets as --grid-columns, and lets the element fill its container', () => {
        // Bricks Products has no grid-columns-N class; without this its layout grid kept the default three.
        expect(flat).toMatch(/:where\(\.fct-brick-products-wrapper \.fct-products-container\) \{ --fct-layout-columns: var\(--grid-columns, 4\); \}/);
        const shopApp = sass.compile(fileURLToPath(new URL('../../resources/public/product-page/style/shop-app.scss', import.meta.url)), {style: 'expanded'}).css.replace(/\s+/g, ' ');
        expect(shopApp).toMatch(/:where\(\.brxe-fct-products\) \{ width: 100%; \}/);
    });
});
