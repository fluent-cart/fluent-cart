import {describe, expect, it} from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

/**
 * The rating summary follows the width of its own column.
 *
 * Its wide arrangement (score, bars and button in one row) was an unnamed
 * container query, which measures the nearest size container. On a product
 * page that is .fct-single-product-page, so the 320px side column got the
 * wide row: the bars shrank to nothing and the button spilled over the list.
 *
 * Source guard, not a render; measured in the PR.
 */

const FILE = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../resources/public/single-product/reviews.scss');
const SCSS = fs.readFileSync(FILE, 'utf8');

describe('the rating summary container', () => {
    it('is named on the Rating Summary block and on the section summary column', () => {
        expect(SCSS).toMatch(/\.fct-review-summary-block,\s*\.fct-reviews-layout-left\s*\{[^}]*container:\s*fct-review-summary\s*\/\s*inline-size/);
    });

    it('sizes the section button to its text in the wide row', () => {
        expect(SCSS).toMatch(/@container fct-review-summary \(min-width: 600px\)[\s\S]*?\.fct-reviews-layout \.fct-reviews-summary \.fct-reviews-summary-write-btn\s*\{\s*width:\s*auto;/);
    });

    it('lays the summary out wide only from that container', () => {
        expect(SCSS).toMatch(/@container fct-review-summary \(min-width: 600px\)\s*\{\s*\.fct-reviews-summary\s*\{/);
        expect(SCSS).not.toMatch(/@container \(min-width: 600px\)\s*\{\s*\.fct-reviews-summary\s*\{/);
    });
});
