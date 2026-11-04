<?php

namespace Tests\Functional\Rest\Products;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\ProductVariation;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/products.md → "Updating a product" section, items 50a-50b.
 *
 * The advanced-variation editor is change-tracked: editing one inline cell records
 * only `{id, <that field>}`. resources/admin/Models/Product/productUpdatePayload.js
 * turns each record into a row carrying the variation's identity plus just the
 * columns the merchant touched, which relies on two properties of this endpoint:
 * it accepts such a row, and omitting a column leaves the stored value alone
 * (ProductVariation batchUpdate emits `ELSE <column>` for every row missing it).
 * These cases pin both, so the narrow payload cannot silently start clobbering
 * columns nobody edited.
 */
class UpdateAdvancedVariationPartialCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    /**
     * Spec: Cases/Functional/products.md [50a]
     *   "Admin can change one advanced variation's price on its own, sending only that
     *    variation's identity and the changed column, and the new price is stored."
     */
    public function adminCanUpdateOneAdvancedVariationPriceWithIdentityAndChangedColumnOnly(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $product = $this->advancedVariationProduct();
        [$first, $second] = $this->variantsOf($product);

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/pricing', [
            'ID'          => (int) $product->ID,
            'post_title'  => (string) $product->post_title,
            'post_status' => 'publish',
            'detail'      => $this->detailPayload($product),
            'variants'    => [
                // Exactly what productUpdatePayload.js emits for a price-only edit.
                [
                    'id'               => (int) $first->id,
                    'post_id'          => (int) $product->ID,
                    'variation_title'  => (string) $first->variation_title,
                    'fulfillment_type' => (string) $first->fulfillment_type,
                    'item_price'       => Helper::toCent(24.50),
                ],
            ],
        ]);

        Assert::assertSame(200, $status, 'a variation row carrying identity plus the changed column must be accepted, got: ' . wp_json_encode($body));

        $refreshedFirst = ProductVariation::query()->find($first->id);
        Assert::assertSame(
            Helper::toCent(24.50),
            (int) $refreshedFirst->item_price,
            'the edited variation must persist its new price'
        );

        $refreshedSecond = ProductVariation::query()->find($second->id);
        Assert::assertSame(
            (int) $second->item_price,
            (int) $refreshedSecond->item_price,
            'a variation absent from the payload must keep its price'
        );
    }

    /**
     * Spec: Cases/Functional/products.md [50b]
     *   "Admin sees an advanced variation's stock, SKU and compare price left untouched
     *    when a price-only edit omits them, rather than reset from the editor's snapshot."
     */
    public function adminSeesColumnsOmittedFromAnAdvancedVariationUpdateLeftUntouched(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $product = $this->advancedVariationProduct();
        [$first] = $this->variantsOf($product);

        // Stand in for stock moving after the editor loaded — an order landing while
        // the merchant has the product page open.
        ProductVariation::query()->where('id', $first->id)->update([
            'sku'           => 'KEEP-ME',
            'compare_price' => Helper::toCent(40),
            'manage_stock'  => 1,
            'total_stock'   => 7,
            'available'     => 7,
            'stock_status'  => 'in-stock',
        ]);

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/pricing', [
            'ID'          => (int) $product->ID,
            'post_title'  => (string) $product->post_title,
            'post_status' => 'publish',
            'detail'      => $this->detailPayload($product),
            'variants'    => [
                [
                    'id'               => (int) $first->id,
                    'post_id'          => (int) $product->ID,
                    'variation_title'  => (string) $first->variation_title,
                    'fulfillment_type' => (string) $first->fulfillment_type,
                    'item_price'       => Helper::toCent(31.00),
                ],
            ],
        ]);

        Assert::assertSame(200, $status, 'the partial variation update must be accepted, got: ' . wp_json_encode($body));

        $refreshed = ProductVariation::query()->find($first->id);
        Assert::assertSame(Helper::toCent(31.00), (int) $refreshed->item_price, 'the edited column must change');
        Assert::assertSame('KEEP-ME', (string) $refreshed->sku, 'an omitted sku must keep its stored value');
        Assert::assertSame(Helper::toCent(40), (int) $refreshed->compare_price, 'an omitted compare_price must keep its stored value');
        Assert::assertSame(7, (int) $refreshed->total_stock, 'an omitted total_stock must keep its stored value');
        Assert::assertSame(7, (int) $refreshed->available, 'an omitted available must keep its stored value');
        Assert::assertSame('in-stock', (string) $refreshed->stock_status, 'an omitted stock_status must keep its stored value');
    }

    /**
     * Spec: Cases/Functional/products.md [50c]
     *   "Admin sees every variation keep its display position when only one of them is edited,
     *    rather than the edited variation being renumbered to the front."
     */
    public function adminSeesVariationDisplayOrderPreservedWhenOnlyOneVariationIsEdited(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $product = $this->advancedVariationProduct();
        [$first, $second] = $this->variantsOf($product);

        $firstSerial  = (int) $first->serial_index;
        $secondSerial = (int) $second->serial_index;
        Assert::assertNotSame(
            $firstSerial,
            $secondSerial,
            'fixture must seed distinct serial indexes so a renumbering regression is observable'
        );

        // Only the SECOND variation is submitted — the payload productUpdatePayload.js
        // produces when the merchant edits that row alone.
        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/pricing', [
            'ID'          => (int) $product->ID,
            'post_title'  => (string) $product->post_title,
            'post_status' => 'publish',
            'detail'      => $this->detailPayload($product),
            'variants'    => [
                [
                    'id'               => (int) $second->id,
                    'post_id'          => (int) $product->ID,
                    'variation_title'  => (string) $second->variation_title,
                    'fulfillment_type' => (string) $second->fulfillment_type,
                    'item_price'       => Helper::toCent(33.00),
                ],
            ],
        ]);

        Assert::assertSame(200, $status, 'the single-row update must be accepted, got: ' . wp_json_encode($body));

        $refreshedSecond = ProductVariation::query()->find($second->id);
        Assert::assertSame(
            Helper::toCent(33.00),
            (int) $refreshedSecond->item_price,
            'the edited variation must persist its new price'
        );
        Assert::assertSame(
            $secondSerial,
            (int) $refreshedSecond->serial_index,
            'the edited variation must keep its display position, not be renumbered to the front'
        );

        $refreshedFirst = ProductVariation::query()->find($first->id);
        Assert::assertSame(
            $firstSerial,
            (int) $refreshedFirst->serial_index,
            'a variation absent from the payload must keep its display position'
        );
        Assert::assertNotSame(
            (int) $refreshedFirst->serial_index,
            (int) $refreshedSecond->serial_index,
            'a partial save must not leave two variations sharing a serial index'
        );
    }

    /**
     * Spec: Cases/Functional/products.md [50d]
     *   "Admin sees display order preserved when a save carries a non-contiguous subset of the
     *    variations, such as editing the first and third but not the second."
     */
    public function adminSeesVariationDisplayOrderPreservedWhenANonContiguousSubsetIsEdited(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $product = (new ProductFactory())
            ->withDetailAttributes(['variation_type' => Helper::PRODUCT_TYPE_ADVANCE_VARIATION])
            ->createWithVariants([
                ['variation_title' => 'One',   'item_price' => Helper::toCent(10), 'fulfillment_type' => 'physical', 'serial_index' => 1],
                ['variation_title' => 'Two',   'item_price' => Helper::toCent(20), 'fulfillment_type' => 'physical', 'serial_index' => 2],
                ['variation_title' => 'Three', 'item_price' => Helper::toCent(30), 'fulfillment_type' => 'physical', 'serial_index' => 3],
            ], ['post_title' => 'Non Contiguous Partial Update']);

        [$one, $two, $three] = $this->variantsOf($product);

        // The merchant edits the FIRST and THIRD rows. productUpdatePayload.js densifies
        // that into a two-row payload, so payload position no longer tracks display order.
        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/pricing', [
            'ID'          => (int) $product->ID,
            'post_title'  => (string) $product->post_title,
            'post_status' => 'publish',
            'detail'      => $this->detailPayload($product),
            'variants'    => [
                [
                    'id'               => (int) $one->id,
                    'post_id'          => (int) $product->ID,
                    'variation_title'  => (string) $one->variation_title,
                    'fulfillment_type' => (string) $one->fulfillment_type,
                    'item_price'       => Helper::toCent(11),
                ],
                [
                    'id'               => (int) $three->id,
                    'post_id'          => (int) $product->ID,
                    'variation_title'  => (string) $three->variation_title,
                    'fulfillment_type' => (string) $three->fulfillment_type,
                    'item_price'       => Helper::toCent(33),
                ],
            ],
        ]);

        Assert::assertSame(200, $status, 'a non-contiguous subset update must be accepted, got: ' . wp_json_encode($body));

        $serials = [];
        foreach ([$one, $two, $three] as $variant) {
            $refreshed = ProductVariation::query()->find($variant->id);
            $serials[(string) $variant->variation_title] = (int) $refreshed->serial_index;
        }

        Assert::assertSame(
            ['One' => 1, 'Two' => 2, 'Three' => 3],
            $serials,
            'every variation must keep its stored display position; the third must not be pulled to position 2 by its payload index'
        );

        Assert::assertSame(
            Helper::toCent(11),
            (int) ProductVariation::query()->find($one->id)->item_price,
            'the first edited variation must persist its new price'
        );
        Assert::assertSame(
            Helper::toCent(33),
            (int) ProductVariation::query()->find($three->id)->item_price,
            'the third edited variation must persist its new price'
        );
        Assert::assertSame(
            Helper::toCent(20),
            (int) ProductVariation::query()->find($two->id)->item_price,
            'the untouched middle variation must keep its price'
        );
    }

    /**
     * Spec: Cases/Functional/products.md [50e]
     *   "Admin can still reorder variations, with the new order stored, when the save carries an
     *    explicit display position for every variation."
     */
    public function adminCanStillReorderVariationsWhenEveryRowCarriesAnExplicitPosition(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $product = $this->advancedVariationProduct();
        [$first, $second] = $this->variantsOf($product);

        // The payload ProductEditModel.updateVariantSerialIndexes() produces after a drag:
        // every row carries an explicit position. This is the counterpart to 50c/50d — those
        // prove an absent position is left alone, this proves a supplied one is still honoured.
        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/pricing', [
            'ID'          => (int) $product->ID,
            'post_title'  => (string) $product->post_title,
            'post_status' => 'publish',
            'detail'      => $this->detailPayload($product),
            'variants'    => [
                [
                    'id'               => (int) $second->id,
                    'post_id'          => (int) $product->ID,
                    'variation_title'  => (string) $second->variation_title,
                    'fulfillment_type' => (string) $second->fulfillment_type,
                    'serial_index'     => 1,
                ],
                [
                    'id'               => (int) $first->id,
                    'post_id'          => (int) $product->ID,
                    'variation_title'  => (string) $first->variation_title,
                    'fulfillment_type' => (string) $first->fulfillment_type,
                    'serial_index'     => 2,
                ],
            ],
        ]);

        Assert::assertSame(200, $status, 'a reorder must be accepted, got: ' . wp_json_encode($body));

        Assert::assertSame(
            1,
            (int) ProductVariation::query()->find($second->id)->serial_index,
            'the variation dragged to the front must be stored at position 1'
        );
        Assert::assertSame(
            2,
            (int) ProductVariation::query()->find($first->id)->serial_index,
            'the variation dragged down must be stored at position 2'
        );

        Assert::assertSame(
            [(int) $second->id, (int) $first->id],
            ProductVariation::query()
                ->where('post_id', $product->ID)
                ->orderBy('serial_index', 'ASC')
                ->pluck('id')
                ->map(function ($id) {
                    return (int) $id;
                })
                ->all(),
            'reading the product back in display order must reflect the new arrangement'
        );
    }

    private function advancedVariationProduct()
    {
        return (new ProductFactory())
            ->withDetailAttributes(['variation_type' => Helper::PRODUCT_TYPE_ADVANCE_VARIATION])
            ->createWithVariants([
                // Explicit 1-based positions, as the editor writes them: the factory's
                // 0-based default would make the second row's stored index coincide with
                // the value a renumbering bug produces, hiding the regression.
                ['variation_title' => 'Small / Red', 'item_price' => Helper::toCent(19.99), 'fulfillment_type' => 'physical', 'serial_index' => 1],
                ['variation_title' => 'Large / Blue', 'item_price' => Helper::toCent(29.99), 'fulfillment_type' => 'physical', 'serial_index' => 2],
            ], ['post_title' => 'Advanced Variation Partial Update']);
    }

    private function variantsOf($product): array
    {
        return ProductVariation::query()
            ->where('post_id', $product->ID)
            ->orderBy('serial_index', 'ASC')
            ->get()
            ->all();
    }

    private function detailPayload($product): array
    {
        $detail = $product->detail;

        return [
            'id'               => (int) $detail->id,
            'post_id'          => (int) $product->ID,
            'fulfillment_type' => (string) $detail->fulfillment_type,
            'variation_type'   => (string) $detail->variation_type,
            'min_price'        => (int) $detail->min_price,
            'max_price'        => (int) $detail->max_price,
            'manage_stock'     => (int) $detail->manage_stock,
            'other_info'       => is_array($detail->other_info ?? null) ? $detail->other_info : [],
        ];
    }
}
