<?php

namespace Tests\Integration\Contracts;

use FluentCart\Api\Resource\ProductDetailResource;
use FluentCart\Api\Resource\ProductResource;
use FluentCart\Api\Resource\ProductVariationResource;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * Cross-layer cents-NORMALIZATION invariant for the product Resource API.
 *
 * Since 01ca740265 (#2615) the product editor owns the dollars-to-cents
 * conversion and the resources receive cents already. What the resources still
 * own is normalization: Helper::roundCent() turns a JS float artifact into the
 * nearest whole cent (1998.9999999999998 becomes 1999) and rounds a half cent
 * up. Every amount arriving from the browser is a JSON number, so the artifact
 * is the normal case, not an exotic one.
 *
 * Whole-cent fixtures cannot see that contract. roundCent(1999) and (int) 1999
 * agree, so a suite that only ever submits whole cents stays green after the
 * normalization is replaced by a truncating cast, and every price off by one
 * cent ships silently. The REST suite cannot see it either: ProductUpdateRequest
 * validates 'numeric' and passes the float through, and the BIGINT column would
 * round a stored float to the same integer the normalization produces, so the
 * only observable difference is the value the resource itself computes.
 *
 * These tests therefore call the resources DIRECTLY with float-artifact inputs,
 * one per roundCent boundary that the repaired fixtures in
 * Integration/Resources/ProductResourceTest.php and
 * Integration/Http/ProductValidationTest.php exercise with integers. Replace any
 * Helper::roundCent() call in api/Resource/Product*.php with an (int) cast and
 * the matching test below fails while those integer round-trips stay green.
 */
class ProductResourceCentsContractTest extends WPTestCase
{
    /**
     * Spec: Cases/Integration/products.md [282]
     *   "Creating a variant through the resource rounds a float-artifact price to
     *    the nearest whole cent instead of truncating it — item price, compare
     *    price and item cost each land on the cent the browser meant."
     */
    public function test_variation_create_rounds_float_artifact_prices_to_the_nearest_cent(): void
    {
        $product = (new ProductFactory())->create();

        $result = ProductVariationResource::create([
            'variation_title'  => 'Float Artifact Variant',
            'fulfillment_type' => 'digital',
            // 1998.9999999999998 is what 19.99 * 100 yields in JS. A cast stores
            // 1998 and every price shows a cent short.
            'item_price'       => 1998.9999999999998,
            // Below the .5 boundary on purpose: a cast stores 2998, so removing
            // roundCent from compare_price ALONE fails this row. An artifact
            // just above a whole cent would truncate to the same 2999 and let
            // that mutation survive.
            'compare_price'    => 2998.9999999999995,
            'item_cost'        => 999.9999999999999,
            'manage_cost'      => 'yes',
            'manage_stock'     => 0,
            'stock_status'     => 'in-stock',
            'total_stock'      => 0,
            'available'        => 0,
            'committed'        => 0,
            'on_hold'          => 0,
            'other_info'       => ['payment_type' => 'onetime'],
            'post_id'          => $product->ID,
            'detail_id'        => $product->detail->id,
        ]);

        $variant = ProductVariation::query()->find($result['data']['id']);

        $this->assertSame(1999, (int) $variant->item_price,
            'A float artifact must round to 1999 cents; a truncating cast stores 1998');
        $this->assertSame(2999, (int) $variant->compare_price,
            'Compare price takes the same normalization as item price');
        $this->assertSame(1000, (int) $variant->item_cost,
            'Item cost takes the same normalization — a truncated cost corrupts COGS math');
    }

    /**
     * Spec: Cases/Integration/products.md [283]
     *   "Creating a variant rounds a half cent up rather than down, so a price
     *    that lands exactly between two cents never loses one."
     */
    public function test_variation_create_rounds_a_half_cent_up(): void
    {
        $product = (new ProductFactory())->create();

        $result = ProductVariationResource::create([
            'variation_title'  => 'Half Cent Variant',
            'fulfillment_type' => 'digital',
            'item_price'       => 1999.5,
            'compare_price'    => 0,
            'item_cost'        => 0,
            'manage_stock'     => 0,
            'stock_status'     => 'in-stock',
            'total_stock'      => 0,
            'available'        => 0,
            'committed'        => 0,
            'on_hold'          => 0,
            'other_info'       => ['payment_type' => 'onetime'],
            'post_id'          => $product->ID,
            'detail_id'        => $product->detail->id,
        ]);

        $variant = ProductVariation::query()->find($result['data']['id']);

        $this->assertSame(2000, (int) $variant->item_price,
            'Half a cent rounds up to 2000; truncation and round-half-down both store 1999');
    }

    /**
     * Spec: Cases/Integration/products.md [287]
     *   "Creating a variant rounds a price below the half cent down, so
     *    normalization is nearest-cent rounding and not a ceiling."
     */
    public function test_variation_create_rounds_below_the_half_cent_down(): void
    {
        $product = (new ProductFactory())->create();

        $result = ProductVariationResource::create([
            'variation_title'  => 'Rounds Down Variant',
            'fulfillment_type' => 'digital',
            // Every other artifact in this file rounds UP, so ceil() would
            // survive them all. 1999.4 is the case that separates round() from
            // ceil(): nearest gives 1999, a ceiling gives 2000.
            'item_price'       => 1999.4,
            'compare_price'    => 0,
            'item_cost'        => 0,
            'manage_stock'     => 0,
            'stock_status'     => 'in-stock',
            'total_stock'      => 0,
            'available'        => 0,
            'committed'        => 0,
            'on_hold'          => 0,
            'other_info'       => ['payment_type' => 'onetime'],
            'post_id'          => $product->ID,
            'detail_id'        => $product->detail->id,
        ]);

        $variant = ProductVariation::query()->find($result['data']['id']);

        $this->assertSame(1999, (int) $variant->item_price,
            'below the half cent rounds down to 1999; a ceiling stores 2000 and overcharges');
    }

    /**
     * Spec: Cases/Integration/products.md [284]
     *   "Creating a subscription variant with an enabled setup fee rounds a
     *    float-artifact fee to the nearest whole cent."
     */
    public function test_variation_create_rounds_a_float_artifact_signup_fee(): void
    {
        $product = (new ProductFactory())->create();

        $result = ProductVariationResource::create([
            'variation_title'  => 'Float Artifact Fee Variant',
            'fulfillment_type' => 'digital',
            'item_price'       => 2999,
            'compare_price'    => 0,
            'item_cost'        => 0,
            'manage_stock'     => 0,
            'stock_status'     => 'in-stock',
            'total_stock'      => 0,
            'available'        => 0,
            'committed'        => 0,
            'on_hold'          => 0,
            'other_info'       => [
                'payment_type'     => 'subscription',
                'repeat_interval'  => 'monthly',
                'manage_setup_fee' => 'yes',
                'signup_fee'       => 499.99999999999994,
                'signup_fee_name'  => 'Setup',
            ],
            'post_id'          => $product->ID,
            'detail_id'        => $product->detail->id,
        ]);

        $variant = ProductVariation::query()->find($result['data']['id']);
        $storedInfo = $variant->other_info;

        $this->assertSame(500, $storedInfo['signup_fee'] ?? null,
            'A float-artifact setup fee must round to 500 cents; a cast stores 499');
    }

    /**
     * Spec: Cases/Integration/products.md [285]
     *   "Updating a product through the resource rounds a float-artifact variant
     *    price to the nearest whole cent, on the update path as well as create."
     */
    public function test_product_update_rounds_float_artifact_variant_prices(): void
    {
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        ProductResource::update([
            'detail' => [
                'id'             => $product->detail->id,
                'post_id'        => $product->ID,
                'variation_type' => 'simple',
            ],
            'variants' => [
                [
                    'id'         => $variant->id,
                    'post_id'    => $product->ID,
                    'item_price' => 4998.999999999999,
                ],
            ],
        ], $product->ID);

        $refreshed = ProductVariation::query()->find($variant->id);

        $this->assertSame(4999, (int) $refreshed->item_price,
            'The update path normalizes exactly like create; a cast stores 4998');
    }

    /**
     * Spec: Cases/Integration/products.md [286]
     *   "Updating a product detail rounds a float-artifact subscription setup fee
     *    to the nearest whole cent."
     */
    public function test_detail_update_rounds_a_float_artifact_signup_fee(): void
    {
        $product = (new ProductFactory())->create();
        $detail = $product->detail;

        ProductDetailResource::update(
            [
                'id'         => $detail->id,
                'other_info' => [
                    'payment_type'     => 'subscription',
                    'manage_setup_fee' => 'yes',
                    'signup_fee'       => 998.9999999999999,
                ],
            ],
            $detail->id,
            ['action' => 'variant_modified']
        );

        $refreshed = ProductDetail::query()->find($detail->id);

        $this->assertSame(999, $refreshed->other_info['signup_fee'] ?? null,
            'A float-artifact setup fee must round to 999 cents on the detail path too');
    }
}
