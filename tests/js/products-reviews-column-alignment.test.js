import {describe, expect, it} from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

/**
 * The products table's Reviews cell lines up under its header.
 *
 * Every other column renders through RouteCell, whose .table-cell carries the
 * compact table's 8px inset. The Reviews cell was a bare router-link, so its
 * stars sat 7px left of the "Reviews" heading.
 *
 * Source guard, not a render; measured in the PR.
 */

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const source = fs.readFileSync(path.join(root, 'resources/admin/Modules/Products/parts/ProductsTable.vue'), 'utf8');
const reviewsColumn = (source.match(/isColumnVisible\('reviews'\)[\s\S]*?<\/el-table-column>/) || [''])[0];

describe('the products table Reviews column', () => {
    it('renders through RouteCell like its neighbours', () => {
        expect(reviewsColumn).toMatch(/<RouteCell\b/);
        expect(reviewsColumn).not.toMatch(/<router-link\b/);
    });

    it('still links to that product\'s reviews with an accessible name', () => {
        expect(reviewsColumn).toMatch(/:to="\{ name: 'reviews', query: \{ post_id: scope\.row\.ID \} \}"/);
        expect(reviewsColumn).toMatch(/:aria-label="translate\('View reviews for %s', scope\.row\.post_title\)"/);
    });
});
