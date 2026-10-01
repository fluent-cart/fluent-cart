import {describe, expect, it} from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

/**
 * The products screens stop showing review controls while the reviews
 * module is off: the Reviews column (and its toggle) in the products table,
 * and the per-product "Disable reviews" switch in the product editor.
 *
 * Source guard, not a render; the browser runs are in the PR.
 */

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');

describe('products screens while the reviews module is off', () => {
    it('do not offer the Reviews column toggle', () => {
        const source = read('resources/admin/utils/table-new/ProductTable.js');
        expect(source).toMatch(/const reviewsActive = AppConfig\.get\('modules_settings\.reviews\.active'\) === 'yes'/);
        expect(source).toMatch(/\.\.\.\(reviewsActive \? \[\{\s*label: translate\('Reviews'\),\s*value: 'reviews'\s*\}\] : \[\]\)/);
    });

    it('do not render the Reviews column even from a stored preference', () => {
        const source = read('resources/admin/Modules/Products/parts/ProductsTable.vue');
        expect(source).toMatch(/v-if="reviewsModuleActive && productTable\.isColumnVisible\('reviews'\)"/);
    });

    it('do not show the per-product disable switch', () => {
        const source = read('resources/admin/Modules/Products/parts/ProductStatus.vue');
        expect(source).toMatch(/<el-checkbox v-if="reviewsModuleActive" @change="value => \{productEditModel\.onChangeInputField\('reviews_enabled',value\)\}"/);
    });
});
