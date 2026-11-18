import {describe, expect, it, vi} from 'vitest';

/**
 * The products screens stop showing review controls while the reviews
 * module is off: the Reviews column (and its toggle) in the products table,
 * and the per-product "Disable reviews" switch in the product editor.
 *
 * The column list is called directly.
 */

const config = vi.hoisted(() => ({reviews: 'yes'}));

// The real base table pulls in Rest, which reads window.jQuery at import.
vi.mock('@/utils/table-new/Table', () => ({default: class {
    static init() {
        return new this();
    }
}}));
vi.mock('@/utils/Config/AppConfig', () => ({
    default: {get: (key) => (key === 'modules_settings.reviews.active' ? config.reviews : null)},
}));
vi.mock('@/utils/translator/Translator', () => ({default: (text) => text}));

import useProductTable from '@/utils/table-new/ProductTable';

describe('products screens while the reviews module is off', () => {
    it('do not offer the Reviews column toggle', () => {
        const columns = () => useProductTable({}).getToggleableColumns().map(column => column.value);

        config.reviews = 'no';
        expect(columns()).not.toContain('reviews');

        config.reviews = 'yes';
        expect(columns()).toContain('reviews');
    });
});
