import {describe, expect, it} from 'vitest';
import {
    buildSimpleVariantUpdatePayload,
    buildVariantUpdatePayload,
} from '@/Models/Product/productUpdatePayload';

/**
 * The advanced-variation editor records only what the merchant touched:
 * ProductEditModel.onChangePricing() writes `{id, <changed field>}` into
 * data.product_changes.variants, and the product-level Update button posts that
 * to POST products/{postId}/pricing.
 *
 * ProductUpdateRequest validates each row as a whole variant — `variants.*.post_id`
 * is required, `variants.*.variation_title` is required, and
 * `variants.*.fulfillment_type` must be physical|digital — so a sparse row has to be
 * reunited with the variation it came from before it is sent. Changing only a price
 * previously produced:
 *
 *     Title is required.
 *     Invalid fulfillment type.
 *     The variants.0.post_id field is required.
 *
 * The merge must stay narrow, though: ProductVariation batchUpdate keeps a column's
 * existing value when a row omits it, so a price edit must not rewrite stock columns
 * the merchant never touched from a snapshot that may be minutes stale.
 */

const loadedVariants = () => ([
    {
        id: 11,
        post_id: 99,
        variation_title: 'Small / Red',
        fulfillment_type: 'physical',
        sku: 'SM-RED',
        item_price: 1500,
        compare_price: 2000,
        total_stock: 40,
        available: 40,
        committed: 0,
        stock_status: 'in-stock',
        manage_stock: 1,
        other_info: {
            payment_type: 'onetime',
            tax_class: 'standard',
            description: 'Original description',
        },
    },
    {
        id: 12,
        post_id: 99,
        variation_title: 'Large / Blue',
        fulfillment_type: 'digital',
        item_price: 2500,
        other_info: {payment_type: 'onetime'},
    },
]);

describe('buildVariantUpdatePayload', () => {
    it('carries the identity and required fields a price-only edit never records', () => {
        const changed = [];
        changed[0] = {id: 11, item_price: 1800};

        const [row] = buildVariantUpdatePayload(changed, loadedVariants(), 99);

        expect(row.id).toBe(11);
        expect(row.post_id).toBe(99);
        expect(row.variation_title).toBe('Small / Red');
        expect(row.fulfillment_type).toBe('physical');
        expect(row.item_price).toBe(1800);
    });

    it('leaves untouched columns out so the batch update keeps their stored values', () => {
        const changed = [];
        changed[0] = {id: 11, item_price: 1800};

        const [row] = buildVariantUpdatePayload(changed, loadedVariants(), 99);

        expect(row).not.toHaveProperty('total_stock');
        expect(row).not.toHaveProperty('available');
        expect(row).not.toHaveProperty('committed');
        expect(row).not.toHaveProperty('stock_status');
        expect(row).not.toHaveProperty('compare_price');
        expect(row).not.toHaveProperty('sku');
    });

    it('sends stock columns when the merchant did change them', () => {
        const changed = [];
        changed[0] = {id: 11, available: 12, manage_stock: 1};

        const [row] = buildVariantUpdatePayload(changed, loadedVariants(), 99);

        expect(row.available).toBe(12);
        expect(row.manage_stock).toBe(1);
    });

    it('merges other_info onto the stored copy instead of replacing it', () => {
        // updatePricingOtherValue's description branch records other_info sparsely;
        // the column is rewritten wholesale server-side, so a shallow replace would
        // drop payment_type and tax_class.
        const changed = [];
        changed[0] = {id: 11, other_info: {description: 'Edited description'}};

        const [row] = buildVariantUpdatePayload(changed, loadedVariants(), 99);

        expect(row.other_info).toEqual({
            payment_type: 'onetime',
            tax_class: 'standard',
            description: 'Edited description',
        });
    });

    it('matches the stored variation by id rather than array position', () => {
        // product_changes.variants is a sparse array indexed by row position, so a
        // change to the second variation arrives with a hole at index 0.
        const changed = [];
        changed[1] = {id: 12, item_price: 2900};

        const rows = buildVariantUpdatePayload(changed, loadedVariants(), 99);

        expect(rows).toHaveLength(1);
        expect(rows[0].id).toBe(12);
        expect(rows[0].variation_title).toBe('Large / Blue');
        expect(rows[0].fulfillment_type).toBe('digital');
        expect(rows[0].item_price).toBe(2900);
    });

    it('keeps an explicitly cleared value rather than falling back to the stored one', () => {
        const changed = [];
        changed[0] = {id: 11, sku: ''};

        const [row] = buildVariantUpdatePayload(changed, loadedVariants(), 99);

        expect(row.sku).toBe('');
    });

    it('falls back to the product id when the stored variation has no post_id', () => {
        const changed = [];
        changed[0] = {id: 11, item_price: 1800};
        const loaded = loadedVariants();
        delete loaded[0].post_id;

        const [row] = buildVariantUpdatePayload(changed, loaded, 99);

        expect(row.post_id).toBe(99);
    });

    it('omits serial_index when the merchant did not reorder anything', () => {
        // The endpoint treats an absent serial_index as "unchanged" and keeps the stored
        // position. Sending one derived from the editor's snapshot — or letting the server
        // derive it from the payload index — renumbers a row that was never moved.
        const changed = [];
        changed[1] = {id: 12, item_price: 2900};

        const [row] = buildVariantUpdatePayload(changed, loadedVariants(), 99);

        expect(row).not.toHaveProperty('serial_index');
    });

    it('sends serial_index for every row when the merchant did reorder', () => {
        // The shape ProductEditModel.updateVariantSerialIndexes() records: an explicit
        // position for each variation, which is what makes reordering possible at all.
        const changed = [
            {id: 12, serial_index: 1},
            {id: 11, serial_index: 2},
        ];

        const rows = buildVariantUpdatePayload(changed, loadedVariants(), 99);

        expect(rows.map(row => [row.id, row.serial_index])).toEqual([[12, 1], [11, 2]]);
    });

    it('keeps a reorder and a field edit on the same row in one payload', () => {
        const changed = [];
        changed[0] = {id: 11, serial_index: 2, item_price: 1800};

        const [row] = buildVariantUpdatePayload(changed, loadedVariants(), 99);

        expect(row.serial_index).toBe(2);
        expect(row.item_price).toBe(1800);
    });

    it('drops a record for a variation the editor no longer holds', () => {
        // Regenerating the option config replaces product.variants wholesale without
        // clearing product_changes, so an edit staged beforehand can outlive the
        // combination it belonged to. Emitting it produced {id, post_id} with no
        // title or fulfilment type, and the save was rejected naming a row the
        // merchant could no longer see.
        const changed = [];
        changed[0] = {id: 11, item_price: 1800};
        changed[1] = {id: 999, item_price: 4200};   // regenerated out of existence

        const rows = buildVariantUpdatePayload(changed, loadedVariants(), 99);

        expect(rows.map(row => row.id)).toEqual([11]);
        expect(rows.every(row => row.variation_title !== undefined)).toBe(true);
    });

    it('drops every record when the whole variant set was replaced', () => {
        const changed = [];
        changed[0] = {id: 11, item_price: 1800};
        changed[1] = {id: 12, item_price: 4200};

        expect(buildVariantUpdatePayload(changed, [], 99)).toEqual([]);
    });

    it('keeps a record that carries no id at all', () => {
        // The simple form stages one for a product with no variation yet; that row
        // is built from scratch rather than matched to a stored variation.
        const changed = [];
        changed[0] = {item_price: 1800};

        const rows = buildVariantUpdatePayload(changed, [], 99);

        expect(rows).toHaveLength(1);
        expect(rows[0].post_id).toBe(99);
    });

    it('returns an empty list when nothing was changed', () => {
        expect(buildVariantUpdatePayload([], loadedVariants(), 99)).toEqual([]);
        expect(buildVariantUpdatePayload(undefined, loadedVariants(), 99)).toEqual([]);
    });
});

/**
 * A SIMPLE product posts its single variant row on every save, because the
 * product-level fields share the request. It used to skip the merge above and
 * hand-roll a row that only appeared when nothing on the variation had changed
 * and never carried other_info, so editing a simple product's title, price or
 * fulfilment was rejected with:
 *
 *     Invalid fulfillment type.
 *     The variants.0.post_id field is required.
 *     The variants.0.other_info field is required.
 *     Payment Type is required.
 *
 * See dev-docs/framework-update-2.12.6-qa.md finding 11.
 */
describe('buildSimpleVariantUpdatePayload', () => {
    const storedSimple = () => ([
        {
            id: 10610,
            post_id: 12267,
            variation_title: 'New Cent',
            fulfillment_type: 'digital',
            item_price: 10000,
            total_stock: 4,
            other_info: {payment_type: 'subscription', repeat_interval: 'yearly', signup_fee: 500},
        },
    ]);

    it('sends a complete row when only the product title changed', () => {
        const changed = [];
        changed[0] = {id: 10610, variation_title: 'Renamed'};

        const [row] = buildSimpleVariantUpdatePayload(changed, storedSimple(), 12267);

        expect(row.id).toBe(10610);
        expect(row.post_id).toBe(12267);
        expect(row.variation_title).toBe('Renamed');
        expect(row.fulfillment_type).toBe('digital');
        expect(row.other_info).toEqual({payment_type: 'subscription', repeat_interval: 'yearly', signup_fee: 500});
    });

    it('sends a complete row when nothing on the variation changed at all', () => {
        const [row] = buildSimpleVariantUpdatePayload([], storedSimple(), 12267);

        expect(row.id).toBe(10610);
        expect(row.post_id).toBe(12267);
        expect(row.variation_title).toBe('New Cent');
        expect(row.fulfillment_type).toBe('digital');
        expect(row.other_info.payment_type).toBe('subscription');
    });

    it('sends a row when the change array was never created', () => {
        const [row] = buildSimpleVariantUpdatePayload(undefined, storedSimple(), 12267);

        expect(row.id).toBe(10610);
        expect(row.other_info.payment_type).toBe('subscription');
    });

    it('merges an other_info edit over the stored settings instead of replacing them', () => {
        const changed = [];
        changed[0] = {id: 10610, other_info: {signup_fee: 750}};

        const [row] = buildSimpleVariantUpdatePayload(changed, storedSimple(), 12267);

        expect(row.other_info).toEqual({
            payment_type: 'subscription',
            repeat_interval: 'yearly',
            signup_fee: 750,
        });
    });

    it('does not resend columns the merchant never touched', () => {
        const changed = [];
        changed[0] = {id: 10610, item_price: 12000};

        const [row] = buildSimpleVariantUpdatePayload(changed, storedSimple(), 12267);

        expect(row.item_price).toBe(12000);
        expect(row).not.toHaveProperty('total_stock');
    });

    it('seeds other_info from the variation the change record names, not from row 0', () => {
        // `variation_type = simple` does not guarantee one row: products exist with
        // the simple type and several variations. Seeding from row 0 would move one
        // variation's billing settings onto another.
        const loaded = [
            {id: 1, post_id: 7, variation_title: 'First', fulfillment_type: 'digital',
             other_info: {payment_type: 'onetime'}},
            {id: 2, post_id: 7, variation_title: 'Second', fulfillment_type: 'digital',
             other_info: {payment_type: 'subscription', repeat_interval: 'monthly'}},
        ];
        const changed = [];
        changed[1] = {id: 2, item_price: 4200};

        const [row] = buildSimpleVariantUpdatePayload(changed, loaded, 7);

        expect(row.id).toBe(2);
        expect(row.variation_title).toBe('Second');
        expect(row.other_info).toEqual({payment_type: 'subscription', repeat_interval: 'monthly'});
    });

    it('never posts stock counters, so a purchase during the edit is not reverted', () => {
        // updatePricingOtherValue() stages the live variation object wholesale for a
        // simple product, so an other_info edit drags the editor's stock snapshot
        // along. Posting it reverted real stock: observed on wp.test, server 42 ->
        // saved back as the editor's stale 99.
        const loaded = [{
            id: 10610, post_id: 12267, variation_title: 'New Cent', fulfillment_type: 'digital',
            other_info: {payment_type: 'subscription'},
            total_stock: 99, available: 99, committed: 0, on_hold: 0, manage_stock: '0',
        }];
        const changed = [];
        changed[0] = {
            id: 10610,
            other_info: {payment_type: 'subscription'},
            total_stock: 99, available: 99, committed: 0, on_hold: 0, manage_stock: '0',
        };

        const [row] = buildSimpleVariantUpdatePayload(changed, loaded, 12267);

        expect(row).not.toHaveProperty('total_stock');
        expect(row).not.toHaveProperty('available');
        expect(row).not.toHaveProperty('committed');
        expect(row).not.toHaveProperty('on_hold');
        expect(row).not.toHaveProperty('manage_stock');
        // and the edit itself still goes out
        expect(row.other_info).toEqual({payment_type: 'subscription'});
        expect(row.variation_title).toBe('New Cent');
    });

    it('still produces a row when the editor holds no stored variation yet', () => {
        const [row] = buildSimpleVariantUpdatePayload([], [], 12267);

        expect(row.post_id).toBe(12267);
        expect(row).not.toHaveProperty('variation_title');
    });
});
