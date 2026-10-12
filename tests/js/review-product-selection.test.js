import {describe, expect, it} from 'vitest';
import {
    attributesForSelection,
    attributesForSource,
    MAX_SELECTED_PRODUCTS,
    modalSelectionFor,
    productSourceOf,
    selectedProductIdsOf,
} from '@/BlockEditor/ProductReviews/Components/reviewProductSelection';

/**
 * The review blocks' "Selected products" option saves one product as the
 * existing custom mode and several as multiple, so saved blocks keep working.
 */
describe('review block product selection', () => {
    it('shows custom and multiple as one Selected products option', () => {
        expect(productSourceOf('custom')).toBe('selected');
        expect(productSourceOf('multiple')).toBe('selected');
        expect(productSourceOf('all')).toBe('all');
        expect(productSourceOf('default')).toBe('default');
        expect(productSourceOf(undefined)).toBe('default');
    });

    it('saves one product as the existing single-product mode', () => {
        expect(attributesForSelection([42])).toEqual({query_type: 'custom', product_id: '42', product_ids: [42]});
        expect(attributesForSelection(['42', 42])).toEqual({query_type: 'custom', product_id: '42', product_ids: [42]});
    });

    it('saves several products as multiple', () => {
        expect(attributesForSelection([42, '7', 42, 0, 'abc'])).toEqual({query_type: 'multiple', product_ids: [42, 7]});
    });

    it('keeps an empty selection as multiple so it never widens to every review', () => {
        expect(attributesForSelection([])).toEqual({query_type: 'multiple', product_ids: []});
        expect(attributesForSelection([0, -3, ''])).toEqual({query_type: 'multiple', product_ids: []});
    });

    it('caps the selection', () => {
        const productIds = Array.from({length: MAX_SELECTED_PRODUCTS + 5}, (_, index) => index + 1);

        expect(attributesForSelection(productIds).product_ids).toHaveLength(MAX_SELECTED_PRODUCTS);
    });

    it('reads the selection from either saved mode', () => {
        expect(selectedProductIdsOf({query_type: 'custom', product_id: '15', product_ids: [3, 4]})).toEqual([15]);
        expect(selectedProductIdsOf({query_type: 'custom', product_id: ''})).toEqual([]);
        expect(selectedProductIdsOf({query_type: 'multiple', product_ids: ['3', 4]})).toEqual([3, 4]);
    });

    it('restores the saved selection when switching back to Selected products', () => {
        expect(attributesForSource('selected', {product_id: '', product_ids: [3, 4]}))
            .toEqual({query_type: 'multiple', product_ids: [3, 4]});
        expect(attributesForSource('selected', {product_id: '15', product_ids: []}))
            .toEqual({query_type: 'custom', product_id: '15', product_ids: [15]});
        expect(attributesForSource('selected', {product_id: '', product_ids: []}))
            .toEqual({query_type: 'multiple', product_ids: []});
        expect(attributesForSource('all', {product_id: '15', product_ids: [3]})).toEqual({query_type: 'all'});
    });

    it('opens the picker with every saved product, loaded or not', () => {
        const loaded = [{ID: 12, post_title: 'Sun Hat'}];

        // Titles still loading, or the load failed: the saved ids are still selected.
        expect(modalSelectionFor([12, 40], [])).toEqual([
            {ID: 12, isPlaceholder: true},
            {ID: 40, isPlaceholder: true},
        ]);
        expect(modalSelectionFor([12, 40], loaded)).toEqual([
            {ID: 12, post_title: 'Sun Hat'},
            {ID: 40, isPlaceholder: true},
        ]);
        expect(modalSelectionFor([], loaded)).toEqual([]);
    });
});
