<?php

namespace Tests\Integration\Resources;

use FluentCart\Api\Resource\ProductDetailResource;
use FluentCart\Api\Resource\ProductResource;
use FluentCart\Api\Resource\ProductVariationResource;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductDownload;
use FluentCart\App\Models\ProductVariation;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

class ProductResourceTest extends WPTestCase
{
    private ProductFactory $productFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->productFactory = new ProductFactory();
        // Set admin user for resource operations
        $userId = static::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($userId);
    }

    // =========================================================================
    // ProductResource::create() — WP post + ProductDetail only
    //
    // The initial variant is created by ProductController::create(), not by the
    // resource. Specs 89/91 in products.md originally claimed the resource
    // creates a variant and defaults variation_type; those specs describe the
    // controller path, not the resource path. The controller-level contract is
    // tested in ProductControllerTest::test_create_product_creates_post_detail_and_variant.
    // =========================================================================

    public function test_create_inserts_post_and_detail_record(): void
    {
        $result = ProductResource::create([
            'post_title' => 'Resource Product',
            'detail'     => [
                'fulfillment_type' => 'digital',
                'manage_stock'     => 0,
            ],
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $productId = $result['data']['ID'];
        $this->assertGreaterThan(0, $productId);

        // Post exists with correct type
        $post = get_post($productId);
        $this->assertSame('fluent-products', $post->post_type);
        $this->assertSame('Resource Product', $post->post_title);

        // Detail record exists, is linked, and is the ONLY row for this post —
        // Product::detail() is one-to-one; a duplicate would make relationship
        // resolution nondeterministic (PR #2336 review)
        $this->assertSame(1, ProductDetail::query()->where('post_id', $productId)->count(),
            'Exactly one fct_product_details row must exist for the created post');
        $detailId = $result['data']['product_details']['id'];
        $detail = ProductDetail::query()->find($detailId);
        $this->assertNotNull($detail, 'ProductDetail should be created');
        $this->assertSame($productId, $detail->post_id);
        $this->assertSame('digital', $detail->fulfillment_type);
    }

    public function test_create_defaults_to_draft_when_no_status_provided(): void
    {
        $result = ProductResource::create([
            'post_title' => 'Draft Product',
            'detail'     => ['fulfillment_type' => 'digital'],
        ]);

        $productId = $result['data']['ID'];
        $post = get_post($productId);
        $this->assertSame('draft', $post->post_status);
    }

    public function test_create_stores_fulfillment_type_from_input(): void
    {
        $result = ProductResource::create([
            'post_title' => 'Physical Product',
            'detail'     => ['fulfillment_type' => 'physical'],
        ]);

        $this->assertSame(1, ProductDetail::query()->where('post_id', $result['data']['ID'])->count(),
            'Exactly one fct_product_details row must exist for the created post (one-to-one invariant)');
        $detail = ProductDetail::query()->find($result['data']['product_details']['id']);
        $this->assertSame($result['data']['ID'], $detail->post_id);
        $this->assertSame('physical', $detail->fulfillment_type);
    }

    // =========================================================================
    // Spec 93-94: ProductResource::find()
    // =========================================================================

    public function test_find_returns_product_with_detail_and_variants(): void
    {
        $product = $this->productFactory->create(['post_title' => 'Findable Product']);
        $result = ProductResource::find($product->ID);

        $this->assertIsArray($result);
        $this->assertSame($product->ID, $result['ID']);
        $this->assertSame('Findable Product', $result['post_title']);
        $this->assertArrayHasKey('detail', $result, 'find() should eager-load detail');
        $this->assertArrayHasKey('variants', $result, 'find() should eager-load variants');
        $this->assertNotEmpty($result['variants'], 'Product should have at least one variant');
    }

    public function test_find_returns_null_for_nonexistent_id(): void
    {
        $result = ProductResource::find(999999);
        $this->assertNull($result);
    }

    // =========================================================================
    // Spec 95-97: ProductResource::update()
    // =========================================================================

    public function test_update_persists_all_post_fields(): void
    {
        $product = $this->productFactory->create([
            'post_title'   => 'Old Title',
            'post_status'  => 'draft',
            'post_content' => 'Old content',
            'post_excerpt' => 'Old excerpt',
        ]);
        $variant = $product->variants->first();

        ProductResource::update([
            'post_title'   => 'New Title',
            'post_status'  => 'publish',
            'post_content' => 'New content',
            'post_excerpt' => 'New excerpt',
            'detail'       => [
                'id'             => $product->detail->id,
                'post_id'        => $product->ID,
                'variation_type' => 'simple',
            ],
            'variants' => [
                [
                    'id'         => $variant->id,
                    'post_id'    => $product->ID,
                    'item_price' => 1999,
                ],
            ],
        ], $product->ID);

        $post = get_post($product->ID);
        $this->assertSame('New Title', $post->post_title);
        $this->assertSame('publish', $post->post_status);
        $this->assertSame('New content', $post->post_content);
        $this->assertSame('New excerpt', $post->post_excerpt);
    }

    public function test_update_stores_submitted_cents_unchanged(): void
    {
        $product = $this->productFactory->create();
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
                    'item_price' => 4999,
                ],
            ],
        ], $product->ID);

        $refreshedVariant = ProductVariation::query()->find($variant->id);
        $this->assertEquals(4999, $refreshedVariant->item_price,
            'Submitted 4999 cents must persist unchanged (#2615: the editor sends cents, the resource only normalizes)');
    }

    public function test_update_recalculates_and_stores_min_max_prices(): void
    {
        $product = $this->productFactory->createWithVariants([
            ['variation_title' => 'Cheap',     'item_price' => 1000],
            ['variation_title' => 'Expensive', 'item_price' => 5000],
        ]);

        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();

        // Sentinel before-state: the factory already stored the correct
        // min/max, so a dead recalculation would otherwise pass. Force wrong
        // values first — the update must overwrite them.
        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}fct_product_details",
            ['min_price' => 1, 'max_price' => 999999],
            ['id' => $detail->id]
        );

        // Call update to trigger price recalculation
        ProductResource::update([
            'detail' => [
                'id'             => $detail->id,
                'post_id'        => $product->ID,
                'variation_type' => 'simple_variations',
            ],
            'variants' => [],
        ], $product->ID);

        // Read stored columns directly (by detail id — immune to stale
        // post_id collisions) since the ORM accessors override them
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT min_price, max_price FROM {$wpdb->prefix}fct_product_details WHERE id = %d",
            $detail->id
        ));
        $this->assertEquals(1000, (int)$row->min_price,
            'Recalculation must overwrite the sentinel min_price with 1000 (cheapest variant)');
        $this->assertEquals(5000, (int)$row->max_price,
            'Recalculation must overwrite the sentinel max_price with 5000 (most expensive variant)');
    }

    // =========================================================================
    // Spec 101-103: ProductResource::delete()
    // =========================================================================

    public function test_delete_cascades_detail_variants_downloads_licenses_and_taxonomy(): void
    {
        if (!taxonomy_exists('product-categories')) {
            register_taxonomy('product-categories', 'fluent-products');
        }

        $product = $this->productFactory->create();
        $productId = $product->ID;
        $detailId = $product->detail->id;
        $variantId = $product->variants->first()->id;

        // Seed related records
        $download = ProductDownload::query()->create([
            'post_id'              => $productId,
            'download_identifier'  => 'del-test-' . uniqid(),
            'title'                => 'Delete Me',
            'type'                 => 'local',
            'file_name'            => 'test.zip',
            'file_url'             => 'http://example.com/test.zip',
            'product_variation_id' => [],
        ]);
        $licenseMeta = \FluentCart\App\Models\ProductMeta::query()->create([
            'object_id'   => $productId,
            'object_type' => null,
            'meta_key'    => 'license_settings',
            'meta_value'  => ['max' => 5],
        ]);
        wp_set_object_terms($productId, ['del-test-cat'], 'product-categories');

        ProductResource::delete($productId);

        $this->assertNull(Product::query()->find($productId), 'Product should be deleted');
        $this->assertNull(ProductDetail::query()->find($detailId), 'Detail should be deleted');
        $this->assertNull(ProductVariation::query()->find($variantId), 'Variant should be deleted');
        $this->assertNull(ProductDownload::query()->find($download->id), 'Download should be deleted');
        $this->assertNull(\FluentCart\App\Models\ProductMeta::query()->find($licenseMeta->id),
            'License meta should be deleted');
        $terms = wp_get_object_terms($productId, 'product-categories', ['fields' => 'ids']);
        $this->assertEmpty($terms, 'Taxonomy terms should be detached');
    }

    // =========================================================================
    // Spec 107: countTotalProducts()
    // =========================================================================

    public function test_count_total_products(): void
    {
        $initialCount = ProductResource::countTotalProducts();

        $this->productFactory->create();
        $this->productFactory->create();

        $newCount = ProductResource::countTotalProducts();
        $this->assertSame($initialCount + 2, $newCount);
    }

    // =========================================================================
    // Spec 108-111: ProductVariationResource::create()
    // =========================================================================

    public function test_variation_resource_create_stores_onetime_other_info(): void
    {
        $product = $this->productFactory->create();

        $result = ProductVariationResource::create([
            'variation_title'  => 'Onetime Variant',
            'fulfillment_type' => 'digital',
            'item_price'       => 1999,
            'compare_price'    => 0,
            'item_cost'        => 0,
            'manage_stock'     => 0,
            'stock_status'     => 'in-stock',
            'total_stock'      => 0,
            'available'        => 0,
            'committed'        => 0,
            'on_hold'          => 0,
            'other_info'       => [
                'payment_type'    => 'onetime',
                // Subscription-only keys — the onetime branch must strip these
                'repeat_interval' => 'monthly',
                'signup_fee'      => 5,
            ],
            'post_id'          => $product->ID,
            'detail_id'        => $product->detail->id,
        ]);

        $this->assertIsArray($result);
        $variantId = $result['data']['id'];

        // Reload and verify stored values
        $variant = ProductVariation::query()->find($variantId);
        $this->assertSame('onetime', $variant->payment_type);
        $this->assertEquals(1999, $variant->item_price,
            'Submitted 1999 cents must persist unchanged (literal oracle; #2615 moved the cents boundary to the editor)');
        $this->assertSame('digital', $variant->fulfillment_type);
        $this->assertSame('Onetime Variant', $variant->variation_title,
            'Submitted variation title must persist');

        global $wpdb;
        $storedInfo = json_decode($wpdb->get_var($wpdb->prepare(
            "SELECT other_info FROM {$wpdb->prefix}fct_product_variations WHERE id = %d",
            $variantId
        )), true);
        $this->assertSame('onetime', $storedInfo['payment_type'] ?? null,
            'Nested other_info.payment_type must survive the onetime whitelist — CartResource reads it for quantity updates');
        $this->assertArrayNotHasKey('repeat_interval', $storedInfo,
            'Onetime branch must strip subscription-only repeat_interval from other_info');
        $this->assertArrayNotHasKey('signup_fee', $storedInfo,
            'Onetime branch must strip subscription-only signup_fee from other_info');
        $this->assertSame('no', $storedInfo['tax_exempt'] ?? null,
            'Onetime branch keeps the defaulted tax_exempt flag');
    }

    public function test_variation_resource_create_stores_subscription_other_info(): void
    {
        $product = $this->productFactory->create();

        $result = ProductVariationResource::create([
            'variation_title'  => 'Sub Variant',
            'fulfillment_type' => 'digital',
            'item_price'       => 9.99,
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
                'times'            => 12,
                'trial_days'       => 7,
                'manage_setup_fee' => 'yes',
                'signup_fee'       => 500,
                'signup_fee_name'  => 'Setup',
            ],
            'post_id'          => $product->ID,
            'detail_id'        => $product->detail->id,
        ]);

        $variantId = $result['data']['id'];

        // Verify subscription fields were stored
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT payment_type, other_info FROM {$wpdb->prefix}fct_product_variations WHERE id = %d",
            $variantId
        ));
        $this->assertSame('subscription', $row->payment_type);

        $storedInfo = json_decode($row->other_info, true);
        $this->assertSame('subscription', $storedInfo['payment_type']);
        $this->assertSame('monthly', $storedInfo['repeat_interval']);
        $this->assertEquals(12, $storedInfo['times'] ?? null,
            'Submitted billing count (times) must persist — dropping it defaults to 0 = infinite billing');
        $this->assertEquals(7, $storedInfo['trial_days'] ?? null,
            'Submitted trial_days must persist — dropping it defaults to 0 = immediate billing');
        $this->assertSame(500, $storedInfo['signup_fee'] ?? null,
            'Enabled setup fee of 500 cents must persist unchanged (literal oracle; #2615 moved the cents boundary to the editor)');
        $this->assertSame('yes', $storedInfo['manage_setup_fee'] ?? null,
            'manage_setup_fee flag must persist — checkout applies the fee only when it equals yes');
        $this->assertSame('Setup', $storedInfo['signup_fee_name'] ?? null,
            'Submitted setup fee name must persist');
    }

    public function test_variation_resource_create_subscription_without_setup_fee_strips_fee_keys(): void
    {
        $product = $this->productFactory->create();

        $result = ProductVariationResource::create([
            'variation_title'  => 'No-Fee Sub Variant',
            'fulfillment_type' => 'digital',
            'item_price'       => 9.99,
            'compare_price'    => 0,
            'item_cost'        => 0,
            'manage_stock'     => 0,
            'stock_status'     => 'in-stock',
            'total_stock'      => 0,
            'available'        => 0,
            'committed'        => 0,
            'on_hold'          => 0,
            'other_info'       => [
                'payment_type'      => 'subscription',
                'repeat_interval'   => 'monthly',
                'manage_setup_fee'  => 'no',
                'signup_fee'        => 5.00,
                'signup_fee_name'   => 'Setup',
                'setup_fee_per_item'=> 'yes',
            ],
            'post_id'          => $product->ID,
            'detail_id'        => $product->detail->id,
        ]);

        global $wpdb;
        $storedInfo = json_decode($wpdb->get_var($wpdb->prepare(
            "SELECT other_info FROM {$wpdb->prefix}fct_product_variations WHERE id = %d",
            $result['data']['id']
        )), true);
        $this->assertArrayNotHasKey('signup_fee', $storedInfo,
            'Disabled setup fee must strip signup_fee from stored other_info');
        $this->assertArrayNotHasKey('signup_fee_name', $storedInfo,
            'Disabled setup fee must strip signup_fee_name from stored other_info');
        $this->assertArrayNotHasKey('setup_fee_per_item', $storedInfo,
            'Disabled setup fee must strip setup_fee_per_item from stored other_info');
        $this->assertSame('subscription', $storedInfo['payment_type'] ?? null,
            'Stripping fee keys must not disturb payment_type');
        $this->assertSame('monthly', $storedInfo['repeat_interval'] ?? null,
            'Stripping fee keys must not disturb repeat_interval');
        $this->assertSame('no', $storedInfo['manage_setup_fee'] ?? null,
            'The disabled manage_setup_fee flag itself must persist as no');
    }

    public function test_variation_resource_create_stores_submitted_cents_unchanged(): void
    {
        $product = $this->productFactory->create();

        $result = ProductVariationResource::create([
            'variation_title'  => 'Priced Variant',
            'fulfillment_type' => 'digital',
            'item_price'       => 2999,
            'compare_price'    => 3999,
            'item_cost'        => 1000,
            'manage_stock'     => 0,
            'stock_status'     => 'in-stock',
            'total_stock'      => 0,
            'available'        => 0,
            'committed'        => 0,
            'on_hold'          => 0,
            'manage_cost'      => 'yes',
            'other_info'       => ['payment_type' => 'onetime'],
            'post_id'          => $product->ID,
            'detail_id'        => $product->detail->id,
        ]);

        $variant = ProductVariation::query()->find($result['data']['id']);
        $this->assertEquals(2999, $variant->item_price,
            'Submitted 2999 cents must persist unchanged (literal oracle; #2615 moved the cents boundary to the editor)');
        $this->assertEquals(3999, $variant->compare_price,
            'Submitted 3999 cents must persist unchanged (literal oracle; #2615 moved the cents boundary to the editor)');
        $this->assertEquals(1000, $variant->item_cost,
            'Submitted 1000 cents item cost must persist unchanged — a second toCent() pass would corrupt COGS math');
        $this->assertSame('yes', $variant->manage_cost,
            'Submitted manage_cost flag must persist');
    }

    // =========================================================================
    // Spec 116-118: ProductVariationResource::delete()
    // =========================================================================

    public function test_variation_resource_delete_removes_variant(): void
    {
        $product = $this->productFactory->createWithVariants([
            ['variation_title' => 'Keep',   'item_price' => 1000],
            ['variation_title' => 'Delete', 'item_price' => 2000],
        ]);

        $deleteVariant = $product->variants->where('variation_title', 'Delete')->first();
        ProductVariationResource::delete($deleteVariant->id);

        $this->assertNull(ProductVariation::query()->find($deleteVariant->id));
    }

    public function test_variation_resource_delete_refuses_when_processing_orders_exist(): void
    {
        $product = $this->productFactory->create();
        $variant = $product->variants->first();

        // Create an Order with processing status linked to this variant
        $orderId = \FluentCart\App\Models\Order::query()->insertGetId([
            'customer_id'    => 0,
            'status'         => \FluentCart\App\Helpers\Status::ORDER_PROCESSING,
            'payment_status' => 'pending',
            'total_amount'   => 1000,
        ]);
        \FluentCart\App\Models\OrderItem::query()->create([
            'order_id'   => $orderId,
            'post_id'    => $product->ID,
            'object_id'  => $variant->id,
            'title'      => 'Guarded Item',
            'quantity'   => 1,
            'unit_price' => 1000,
            'subtotal'   => 1000,
        ]);

        $result = ProductVariationResource::delete($variant->id);

        // makeErrorResponse returns WP_Error
        $this->assertInstanceOf(\WP_Error::class, $result,
            'Should return WP_Error for variant with processing orders');

        // Variant should still exist
        $this->assertNotNull(ProductVariation::query()->find($variant->id),
            'Variant should be preserved when orders are processing');
    }

    // =========================================================================
    // Spec 120: ProductVariationResource::updatePricingTable()
    // =========================================================================

    public function test_variation_resource_update_pricing_table(): void
    {
        $product = $this->productFactory->create();
        $variant = $product->variants->first();

        ProductVariationResource::updatePricingTable(
            ['description' => 'Special pricing info'],
            $variant->id
        );

        $refreshed = ProductVariation::query()->find($variant->id);
        $this->assertSame('Special pricing info', $refreshed->other_info['description'] ?? null);
    }

    // =========================================================================
    // Spec 121-122: ProductDetailResource::update() with variant_modified
    // =========================================================================

    public function test_detail_resource_variant_modified_sets_in_stock_when_no_manage(): void
    {
        $product = $this->productFactory->create();
        $detail = $product->detail;

        ProductDetailResource::update(
            ['id' => $detail->id, 'manage_stock' => 0],
            $detail->id,
            ['action' => 'variant_modified']
        );

        $refreshed = ProductDetail::query()->find($detail->id);
        $this->assertSame(Helper::IN_STOCK, $refreshed->stock_availability);
    }

    // =========================================================================
    // Spec 123: change_variation_type to simple deletes orphans
    // =========================================================================

    public function test_detail_resource_change_to_simple_deletes_orphan_variants(): void
    {
        $product = $this->productFactory->createWithVariants([
            ['variation_title' => 'V1', 'item_price' => 1000, 'serial_index' => 0],
            ['variation_title' => 'V2', 'item_price' => 2000, 'serial_index' => 1],
            ['variation_title' => 'V3', 'item_price' => 3000, 'serial_index' => 2],
        ]);

        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        $detail->variation_type = 'simple_variations';
        $detail->save();

        // The first variant is the one to keep
        $keepVariant = ProductVariation::query()
            ->where('post_id', $product->ID)
            ->orderBy('serial_index', 'asc')
            ->first();

        ProductDetailResource::update(
            [
                'id'             => $detail->id,
                'post_id'        => $product->ID,
                'variation_type' => 'simple',
                'variation_ids'  => [$keepVariant->id],
            ],
            $detail->id,
            ['action' => 'change_variation_type']
        );

        $remaining = ProductVariation::query()->where('post_id', $product->ID)->get();
        $this->assertSame(1, $remaining->count(), 'Only one variant should remain');
        $this->assertSame($keepVariant->id, $remaining->first()->id,
            'The preserved variant should be the one passed in variation_ids');
        $this->assertSame('V1', $remaining->first()->variation_title,
            'V1 (serial_index 0) should be the surviving variant');
    }

    // =========================================================================
    // Spec 125-126: other_info merge and signup_fee conversion
    // =========================================================================

    public function test_detail_resource_update_merges_other_info(): void
    {
        $product = $this->productFactory
            ->withDetailAttributes(['other_info' => ['existing_key' => 'keep_me']])
            ->create();
        $detail = $product->detail;

        ProductDetailResource::update(
            [
                'id'         => $detail->id,
                'other_info' => ['new_key' => 'new_value'],
            ],
            $detail->id,
            ['action' => 'variant_modified']
        );

        $refreshed = ProductDetail::query()->find($detail->id);
        $info = $refreshed->other_info;
        $this->assertSame('keep_me', $info['existing_key'] ?? null);
        $this->assertSame('new_value', $info['new_key'] ?? null);
    }

    public function test_create_does_not_create_variant(): void
    {
        // The resource layer only creates post + detail.
        // Variant creation is the controller's responsibility.
        $result = ProductResource::create([
            'post_title' => 'No Variant Product',
            'detail'     => ['fulfillment_type' => 'digital'],
        ]);

        $productId = $result['data']['ID'];
        $variantCount = ProductVariation::query()->where('post_id', $productId)->count();
        $this->assertSame(0, $variantCount);
    }
}
