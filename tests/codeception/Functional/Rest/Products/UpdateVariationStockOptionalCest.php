<?php

namespace Tests\Functional\Rest\Products;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\ProductVariation;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/products.md → "Managing inventory and stock", items 232-233.
 *
 * fct_product_variations declares total_stock / available / committed / on_hold as
 * `INT(11) NULL DEFAULT 0` (ProductVariationMigrator), so NULL is a legitimate stored
 * state for a variation that never tracked stock. The pricing drawer posts the variation
 * back exactly as it loaded it, NULLs included, which the request layer must accept while
 * inventory tracking is off.
 */
class UpdateVariationStockOptionalCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    /**
     * Spec: Cases/Functional/products.md [232]
     *   "Admin can change the price of a variation whose stored stock counters are empty while
     *    inventory tracking is off, without being asked to supply a stock quantity."
     */
    public function adminCanUpdateVariationPriceWhenStockCountersAreNullAndInventoryIsOff(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $variant = $this->variantWithNullStockCounters();

        [$status, $body] = $I->post('/fluent-cart/v2/products/variants/' . $variant->id, [
            'variants' => $this->drawerPayload($variant, ['item_price' => Helper::toCent(12.5)]),
        ]);

        Assert::assertSame(
            200,
            $status,
            'a price edit with inventory tracking off must not demand stock counters, got: ' . wp_json_encode($body)
        );

        $refreshed = ProductVariation::query()->find($variant->id);
        Assert::assertSame(
            Helper::toCent(12.5),
            (int) $refreshed->item_price,
            'the edited price must persist'
        );
        Assert::assertSame(
            0,
            (int) $refreshed->manage_stock,
            'the update must leave inventory tracking off'
        );
    }

    /**
     * Spec: Cases/Functional/products.md [233]
     *   "Admin is still required to supply a stock quantity for a variation that has inventory
     *    tracking switched on."
     */
    public function adminIsStillRequiredToSupplyStockWhenInventoryTrackingIsOn(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $variant = $this->variantWithNullStockCounters();

        [$status, $body] = $I->post('/fluent-cart/v2/products/variants/' . $variant->id, [
            'variants' => $this->drawerPayload($variant, [
                'manage_stock'  => 1,
                'stock_status'  => 'in-stock',
                'total_stock'   => null,
                'available'     => null,
            ]),
        ]);

        Assert::assertSame(
            422,
            $status,
            'turning inventory tracking on without a stock quantity must still be rejected, got: ' . wp_json_encode($body)
        );
        Assert::assertArrayHasKey(
            'variants.total_stock',
            (array) $body,
            'the rejection must name the missing stock quantity. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * The numeric-when-provided guard added with spec 232-233 was inert: it sat beside a
     * `required_if`, and Validator::filterRequiredIf() discarded every rule on the
     * attribute — closures included — whenever inventory tracking was off. Carrying the
     * requirement as a RequiredWhenRule closure instead leaves the guard running.
     *
     * See dev-docs/framework-update-2.12.6-qa.md finding 5.
     */
    public function adminIsRejectedWhenSupplyingANonNumericStockQuantityWhileTrackingIsOff(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $variant = $this->variantWithNullStockCounters();

        [$status, $body] = $I->post('/fluent-cart/v2/products/variants/' . $variant->id, [
            'variants' => $this->drawerPayload($variant, ['total_stock' => 'not-a-number']),
        ]);

        Assert::assertSame(
            422,
            $status,
            'a non-numeric stock quantity must be rejected even with tracking off, got: ' . wp_json_encode($body)
        );
        Assert::assertArrayHasKey(
            'variants.total_stock',
            (array) $body,
            'the rejection must name the offending counter. Body: ' . wp_json_encode($body)
        );

        $refreshed = ProductVariation::query()->find($variant->id);
        Assert::assertNull(
            $refreshed->total_stock,
            'the rejected value must not reach the row — the stored NULL counter must survive'
        );
    }

    private function variantWithNullStockCounters(): ProductVariation
    {
        $product = (new ProductFactory())->createWithVariants(
            [['variation_title' => 'No Stock Tracking', 'item_price' => Helper::toCent(9.99)]],
            ['post_title' => 'Null Stock Counters']
        );

        $variant = $product->variants->first();

        // Reproduce the stored shape the drawer chokes on: counters never populated.
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}fct_product_variations
                 SET manage_stock = 0, total_stock = NULL, available = NULL, committed = NULL, on_hold = NULL
                 WHERE id = %d",
                $variant->id
            )
        );

        return ProductVariation::query()->find($variant->id);
    }

    /**
     * The payload ProductEditModel.createOrUpdatePricing() posts: the variation exactly as
     * the editor loaded it, NULL counters and all.
     */
    private function drawerPayload(ProductVariation $variant, array $overrides = []): array
    {
        return array_merge([
            'id'               => (int) $variant->id,
            'post_id'          => (int) $variant->post_id,
            'variation_title'  => (string) $variant->variation_title,
            'item_price'       => (int) $variant->item_price,
            'compare_price'    => 0,
            'manage_cost'      => 'false',
            'fulfillment_type' => (string) ($variant->fulfillment_type ?: 'digital'),
            'manage_stock'     => 0,
            'stock_status'     => 'in-stock',
            'total_stock'      => null,
            'available'        => null,
            'committed'        => null,
            'on_hold'          => null,
            'serial_index'     => (int) $variant->serial_index,
            'other_info'       => ['payment_type' => 'onetime'],
        ], $overrides);
    }
}
