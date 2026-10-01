import {describe, expect, it} from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

/**
 * The empty state spans the laid-out views.
 *
 * "No reviews yet" is one paragraph inside the same container the cards
 * use. Masonry's column-count flowed it across the columns - the sentence
 * broke in two - and grid and slider gave it a single cell of the row.
 * Each view now lets it span the whole container.
 *
 * Source guard, not a render; measured in the PR.
 */

const FILE = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../resources/public/single-product/reviews.scss');
const SCSS = fs.readFileSync(FILE, 'utf8');

describe('the review list empty state', () => {
    it('spans every masonry column', () => {
        expect(SCSS).toMatch(/\.fct-reviews-list--masonry \.fct-reviews-empty\s*\{[^}]*column-span:\s*all/);
    });

    it('spans the whole grid row, and the slider laid out as a grid', () => {
        expect(SCSS).toMatch(/\.fct-reviews-list--grid \.fct-reviews-empty,\s*\.fct-reviews-list--slider:not\(\.swiper\) \.fct-reviews-empty\s*\{[^}]*grid-column:\s*1 \/ -1/);
    });
});
