import {describe, expect, it} from 'vitest';
import {normalizeDefaultVariationIdForSave} from '@/Models/Product/productUpdatePayload';

/**
 * Clearing the Default Variant select (ProductStatus.vue) used to stage
 * `undefined` into product_changes.detail.default_variation_id.
 * Rest.js posts that object through JSON.stringify, which drops any key
 * whose value is `undefined` — so the request left `default_variation_id`
 * out of `detail` entirely. ProductDetailResource::update() treats an absent
 * key as "not supplied" and preserves the existing default, so the clear
 * showed in the UI, reported success on save, and reverted on reload.
 *
 * normalizeDefaultVariationIdForSave is the fix: every clear signal maps to
 * `null`, which survives JSON.stringify, so the backend actually sees the key
 * and — per its own already-correct contract — clears it to NULL.
 */
describe('normalizeDefaultVariationIdForSave', () => {
    it('maps undefined (what a cleared el-select emits) to null', () => {
        expect(normalizeDefaultVariationIdForSave(undefined)).toBeNull();
    });

    it('maps an explicit null to null', () => {
        expect(normalizeDefaultVariationIdForSave(null)).toBeNull();
    });

    it('maps an empty string to null', () => {
        expect(normalizeDefaultVariationIdForSave('')).toBeNull();
    });

    it('passes a selected variant id through unchanged', () => {
        expect(normalizeDefaultVariationIdForSave('482')).toBe('482');
        expect(normalizeDefaultVariationIdForSave(482)).toBe(482);
    });

    it('passes numeric/string zero through unchanged (not treated as a clear signal itself)', () => {
        // Zero is not one of the three clear signals (undefined/null/''), so the
        // normalizer leaves it as-is. It still ends up clearing the default:
        // the backend's own contract treats an empty()-valued default_variation_id
        // (0 included) as a clear, same as it does for ''. See
        // ProductDetailResource::update() — that distinction is a backend
        // concern this fix does not change.
        expect(normalizeDefaultVariationIdForSave(0)).toBe(0);
        expect(normalizeDefaultVariationIdForSave('0')).toBe('0');
    });
});

/**
 * Rest.js posts product_changes through `JSON.stringify(data)` (see
 * utils/http/Rest.js). These tests pin that exact serialization behavior —
 * null survives, undefined is dropped — since it's the mechanism the fix
 * above relies on, not something these tests exercise Rest.js itself to
 * prove.
 */
describe('default_variation_id survives JSON.stringify (the Rest.js request boundary)', () => {
    const buildDetailPayload = (defaultVariationId) => {
        const detail = {id: 55, fulfillment_type: 'digital', variation_type: 'simple_variations'};
        detail['default_variation_id'] = normalizeDefaultVariationIdForSave(defaultVariationId);
        return JSON.parse(JSON.stringify(detail));
    };

    it('keeps the key present with an explicit null after a clear', () => {
        const parsed = buildDetailPayload(undefined);

        expect(parsed).toHaveProperty('default_variation_id');
        expect(parsed.default_variation_id).toBeNull();
    });

    it('drops the key when default_variation_id is left as undefined (the pre-fix bug)', () => {
        // Demonstrates the failure this fix prevents: staging the raw clear
        // value straight from el-select, with no normalization.
        const detail = {id: 55, default_variation_id: undefined};
        const parsed = JSON.parse(JSON.stringify(detail));

        expect(parsed).not.toHaveProperty('default_variation_id');
    });

    it('keeps a selected id present and correct', () => {
        const parsed = buildDetailPayload('482');

        expect(parsed.default_variation_id).toBe('482');
    });
});
