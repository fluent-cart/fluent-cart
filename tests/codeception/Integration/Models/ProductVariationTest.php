<?php

namespace Tests\Integration\Models;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductDownload;
use FluentCart\App\Models\ProductMeta;
use FluentCart\App\Models\ProductVariation;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductDownloadFactory;
use Tests\Support\Factory\ProductFactory;

class ProductVariationTest extends WPTestCase
{
    private ProductFactory $productFactory;
    private ProductDownloadFactory $downloadFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->productFactory = new ProductFactory();
        $this->downloadFactory = new ProductDownloadFactory();
    }

    // =========================================================================
    // Spec 53: Table & Columns
    // =========================================================================

    public function test_product_variations_table_has_expected_columns(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fct_product_variations';
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
        $columnNames = array_column($columns, 'Field');

        $expected = [
            'id', 'post_id', 'media_id', 'serial_index', 'sold_individually',
            'variation_title', 'variation_identifier', 'sku', 'manage_stock',
            'payment_type', 'stock_status', 'backorders', 'total_stock',
            'available', 'committed', 'on_hold', 'fulfillment_type',
            'item_status', 'manage_cost', 'item_price', 'item_cost',
            'compare_price', 'other_info', 'downloadable', 'shipping_class',
        ];

        foreach ($expected as $col) {
            $this->assertContains($col, $columnNames, "Column {$col} should exist");
        }
    }

    // =========================================================================
    // Spec 54: Casts
    // =========================================================================

    public function test_numeric_casts(): void
    {
        $product = $this->productFactory
            ->withVariantAttributes([
                'item_price'    => Helper::toCent(19.99),
                'compare_price' => Helper::toCent(29.99),
                'item_cost'     => Helper::toCent(5.00),
                'total_stock'   => 100,
                'available'     => 80,
                'committed'     => 15,
                'on_hold'       => 5,
            ])
            ->create();

        $variant = $product->variants->first();
        $this->assertIsInt($variant->post_id);
        $this->assertIsFloat($variant->item_price);
        $this->assertIsFloat($variant->compare_price);
        $this->assertIsFloat($variant->item_cost);
        $this->assertIsInt($variant->total_stock);
        $this->assertIsInt($variant->available);
        $this->assertIsInt($variant->committed);
        $this->assertIsInt($variant->on_hold);
        $this->assertIsInt($variant->serial_index);
    }

    // =========================================================================
    // Spec 55: other_info getter includes subscription defaults
    // =========================================================================

    public function test_other_info_includes_subscription_defaults_for_subscription(): void
    {
        // Create a subscription variant directly via DB to guarantee the column values
        $product = $this->productFactory->withoutVariant()->create();
        $variantId = ProductVariation::query()->insertGetId([
            'post_id'          => $product->ID,
            'variation_title'  => 'Sub Variant',
            'serial_index'     => 0,
            'payment_type'     => 'subscription',
            'fulfillment_type' => 'digital',
            'item_status'      => 'active',
            'item_price'       => 999,
            'manage_stock'     => 0,
            'stock_status'     => 'in-stock',
            'total_stock'      => 0,
            'available'        => 0,
            'committed'        => 0,
            'on_hold'          => 0,
            'other_info'       => json_encode(['payment_type' => 'subscription', 'repeat_interval' => 'monthly']),
        ]);

        // Verify column-level storage
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT other_info, payment_type FROM {$wpdb->prefix}fct_product_variations WHERE id = %d",
            $variantId
        ));
        $this->assertSame('subscription', $row->payment_type);

        $storedInfo = json_decode($row->other_info, true);
        $this->assertSame('monthly', $storedInfo['repeat_interval']);

        // Verify the getter adds subscription defaults when called with raw JSON
        $variant = new ProductVariation();
        $variant->setRawAttributes([
            'id'           => $variantId,
            'payment_type' => 'subscription',
            'other_info'   => $row->other_info,
        ], true);
        $info = $variant->other_info;

        $this->assertIsArray($info);
        $this->assertSame('monthly', $info['repeat_interval']);
        $this->assertArrayHasKey('times', $info);
        $this->assertArrayHasKey('trial_days', $info);
        $this->assertArrayHasKey('manage_setup_fee', $info);
    }

    // =========================================================================
    // Spec 56: formatted_total accessor
    // =========================================================================

    public function test_formatted_total_converts_cents_to_decimal_string(): void
    {
        $product = $this->productFactory
            ->withVariantAttributes(['item_price' => Helper::toCent(49.99)])
            ->create();

        $variant = $product->variants->first();
        $formatted = $variant->formatted_total;
        // Helper::toDecimal(4999) should produce a string containing "49" and "99"
        $this->assertStringContainsString('49', $formatted, 'Should contain dollar amount');
        $this->assertStringContainsString('99', $formatted, 'Should contain cents');
    }

    // =========================================================================
    // Spec 57: thumbnail accessor
    // =========================================================================

    public function test_thumbnail_returns_null_when_no_media(): void
    {
        $product = $this->productFactory->create();
        $variant = $product->variants->first();
        $this->assertNull($variant->thumbnail);
    }

    public function test_thumbnail_returns_url_when_media_attached(): void
    {
        $product = $this->productFactory->create();
        $variant = $product->variants->first();

        // Create a product_thumbnail meta record for this variant
        ProductMeta::query()->create([
            'object_id'  => $variant->id,
            'meta_key'   => 'product_thumbnail',
            'meta_value' => [['url' => 'http://example.com/thumb.jpg', 'id' => 42, 'title' => 'Thumb']],
        ]);

        // Re-fetch with media relation
        $fresh = ProductVariation::query()->with('media')->find($variant->id);
        $this->assertSame('http://example.com/thumb.jpg', $fresh->thumbnail);
    }

    public function test_simple_product_thumbnail_falls_back_to_gallery_image(): void
    {
        $product = $this->productFactory->create();
        update_post_meta($product->ID, 'fluent-products-gallery-image', [['id' => 7, 'url' => 'http://example.com/gallery.jpg', 'title' => 'Gallery']]);

        $variant = ProductVariation::query()->find($product->variants->first()->id);
        $this->assertSame('http://example.com/gallery.jpg', $variant->thumbnail);
    }

    public function test_eager_loaded_gallery_feeds_thumbnail_and_stays_out_of_the_array(): void
    {
        $product = $this->productFactory->create();
        update_post_meta($product->ID, 'fluent-products-gallery-image', [['id' => 7, 'url' => 'http://example.com/gallery.jpg', 'title' => 'Gallery']]);

        $variant = ProductVariation::query()->with(['media', 'simpleProductGallery'])->find($product->variants->first()->id);
        $data = $variant->toArray();

        $this->assertSame('http://example.com/gallery.jpg', $data['thumbnail']);
        $this->assertArrayNotHasKey('simple_product_gallery', $data);
        $this->assertArrayNotHasKey('simpleProductGallery', $data);
    }

    public function test_simple_product_prefers_its_own_variant_image(): void
    {
        $product = $this->productFactory->create();
        $variant = $product->variants->first();
        update_post_meta($product->ID, 'fluent-products-gallery-image', [['id' => 7, 'url' => 'http://example.com/gallery.jpg', 'title' => 'Gallery']]);
        ProductMeta::query()->create([
            'object_id'  => $variant->id,
            'meta_key'   => 'product_thumbnail',
            'meta_value' => [['url' => 'http://example.com/thumb.jpg', 'id' => 42, 'title' => 'Thumb']],
        ]);

        $this->assertSame('http://example.com/thumb.jpg', ProductVariation::query()->find($variant->id)->thumbnail);
    }

    public function test_variable_product_variant_without_image_keeps_null_thumbnail(): void
    {
        // Variant swatches treat null as "no image of its own"; the gallery must not fill it.
        $product = $this->productFactory
            ->withDetailAttributes(['variation_type' => Helper::PRODUCT_TYPE_SIMPLE_VARIATION])
            ->createWithVariants([['variation_title' => 'Small'], ['variation_title' => 'Large']]);
        update_post_meta($product->ID, 'fluent-products-gallery-image', [['id' => 7, 'url' => 'http://example.com/gallery.jpg', 'title' => 'Gallery']]);

        $this->assertNull(ProductVariation::query()->find($product->variants->first()->id)->thumbnail);
    }

    // =========================================================================
    // Spec 58-66: Relationships
    // =========================================================================

    public function test_product_relationship(): void
    {
        $product = $this->productFactory->create();
        $variant = $product->variants->first();
        $related = $variant->product;

        $this->assertInstanceOf(Product::class, $related);
        $this->assertSame($product->ID, $related->ID);
    }

    public function test_product_detail_relationship(): void
    {
        $product = $this->productFactory->create();
        $variant = $product->variants->first();
        $detail = $variant->product_detail;

        $this->assertInstanceOf(ProductDetail::class, $detail);
        $this->assertSame($product->ID, $detail->post_id);
    }

    public function test_media_relationship_returns_null_when_no_media(): void
    {
        $product = $this->productFactory->create();
        $variant = $product->variants->first();
        $this->assertNull($variant->media);
    }

    public function test_media_relationship_returns_meta_when_attached(): void
    {
        $product = $this->productFactory->create();
        $variant = $product->variants->first();

        ProductMeta::query()->create([
            'object_id'  => $variant->id,
            'meta_key'   => 'product_thumbnail',
            'meta_value' => [['url' => 'http://example.com/media.jpg']],
        ]);

        // media() selects only id, object_id, meta_value — so check those
        $fresh = ProductVariation::query()->with('media')->find($variant->id);
        $this->assertNotNull($fresh->media, 'media relation should load the attached meta');
        $this->assertEquals($variant->id, $fresh->media->object_id);
        $this->assertIsArray($fresh->media->meta_value);
        $this->assertSame('http://example.com/media.jpg', $fresh->media->meta_value[0]['url']);
    }

    public function test_shipping_class_relationship(): void
    {
        $classId = \FluentCart\App\Models\ShippingClass::query()->insertGetId([
            'name' => 'Heavy Items',
            'cost' => 500,
            'type' => 'flat_rate',
        ]);

        $product = $this->productFactory
            ->withVariantAttributes(['shipping_class' => $classId])
            ->create();
        $variant = ProductVariation::query()->find($product->variants->first()->id);

        $shippingClass = $variant->shippingClass;
        $this->assertNotNull($shippingClass, 'shippingClass should load the related row');
        $this->assertSame('Heavy Items', $shippingClass->name);
        $this->assertEquals($classId, $shippingClass->id);
    }

    public function test_product_downloads_returns_variant_linked_downloads(): void
    {
        $product = $this->productFactory->create();
        $variant = $product->variants->first();

        // Create a variant-specific download
        $this->downloadFactory->create($product->ID, [
            'product_variation_id' => [$variant->id],
            'title'                => 'Variant Download',
        ]);

        // Create a download for a DIFFERENT product to act as distractor
        $otherProduct = $this->productFactory->create();
        $this->downloadFactory->create($otherProduct->ID, [
            'product_variation_id' => [$otherProduct->variants->first()->id],
            'title'                => 'Other Product Download',
        ]);

        $variant->load('product_downloads');
        $titles = $variant->product_downloads->pluck('title')->toArray();
        $this->assertContains('Variant Download', $titles, 'Variant-linked download should be loaded');
        $this->assertNotContains('Other Product Download', $titles,
            'Download from a different product should not leak');
    }

    public function test_order_items_relationship_returns_linked_items(): void
    {
        $product = $this->productFactory->create();
        $variant = $product->variants->first();

        // Create an OrderItem linked to this variant's object_id
        \FluentCart\App\Models\OrderItem::query()->create([
            'order_id'   => 0,
            'post_id'    => $product->ID,
            'object_id'  => $variant->id,
            'title'      => 'Variant Item',
            'quantity'   => 2,
            'unit_price' => 500,
            'subtotal'   => 1000,
        ]);

        $fresh = ProductVariation::query()->find($variant->id);
        $items = $fresh->order_items;
        $this->assertCount(1, $items);
        $this->assertSame('Variant Item', $items->first()->title);
    }

    public function test_upgrade_paths_relationship_loads_matching_meta(): void
    {
        $product = $this->productFactory->create();
        $variant = $product->variants->first();

        // Create a Meta record matching the PlanUpgradeService filters
        \FluentCart\App\Models\Meta::query()->create([
            'object_id'   => $variant->id,
            'object_type' => \FluentCart\App\Services\PlanUpgradeService::$metaType,
            'meta_key'    => \FluentCart\App\Services\PlanUpgradeService::$metaKey,
            'meta_value'  => ['from_variant' => $variant->id, 'to_variants' => [999]],
        ]);

        $fresh = ProductVariation::query()->find($variant->id);
        $paths = $fresh->upgrade_paths;
        $this->assertCount(1, $paths, 'Should load the matching upgrade path');
        $this->assertSame('variant_upgrade', $paths->first()->object_type);
    }

    public function test_bundle_children_relationship_resolves_correct_variants(): void
    {
        $child1 = $this->productFactory->create(['post_title' => 'Child A']);
        $child2 = $this->productFactory->create(['post_title' => 'Child B']);
        $childId1 = $child1->variants->first()->id;
        $childId2 = $child2->variants->first()->id;

        $bundle = $this->productFactory->createBundle([$childId1, $childId2]);
        $bundleVariant = $bundle->variants->first();
        $children = $bundleVariant->bundleChildren;

        $this->assertCount(2, $children);
        $resolvedIds = $children->pluck('id')->toArray();
        $this->assertContains($childId1, $resolvedIds, 'Child variant 1 should be resolved');
        $this->assertContains($childId2, $resolvedIds, 'Child variant 2 should be resolved');
    }

    // =========================================================================
    // Spec 67-72: canPurchase()
    // =========================================================================

    public function test_can_purchase_returns_true_for_active_published_variant(): void
    {
        $product = $this->productFactory->create();
        $variant = $product->variants->first();

        $this->assertTrue($variant->canPurchase());
    }

    public function test_can_purchase_returns_error_when_item_status_inactive(): void
    {
        $product = $this->productFactory
            ->withVariantAttributes(['item_status' => 'inactive'])
            ->create();
        $variant = $product->variants->first();

        $result = $variant->canPurchase();
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_can_purchase_returns_error_when_product_not_published(): void
    {
        $product = $this->productFactory->create(['post_status' => 'draft']);
        $variant = $product->variants->first();

        $result = $variant->canPurchase();
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_can_purchase_returns_error_when_insufficient_stock(): void
    {
        // Enable stock_management module so canPurchase checks stock
        update_option('fluent_cart_modules_settings', [
            'stock_management' => ['active' => 'yes'],
        ]);
        // Clear the static cache in ModuleSettings::getAllSettings
        \FluentCart\Api\ModuleSettings::getAllSettings(false);

        $product = $this->productFactory
            ->withDetailAttributes([
                'manage_stock'       => 1,
                'stock_availability' => Helper::IN_STOCK,
            ])
            ->withVariantAttributes([
                'manage_stock' => 1,
                'available'    => 2,
                'total_stock'  => 2,
                'stock_status' => Helper::IN_STOCK,
            ])
            ->create();
        $variant = ProductVariation::query()->find($product->variants->first()->id);

        // Requesting more than available
        $result = $variant->canPurchase(5);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('insufficient_stock', $result->get_error_code());
    }

    public function test_can_purchase_returns_error_for_subscription_quantity_gt_1(): void
    {
        $product = $this->productFactory
            ->withVariantAttributes([
                'payment_type' => 'subscription',
                'other_info'   => ['payment_type' => 'subscription', 'repeat_interval' => 'monthly'],
            ])
            ->create();
        $variant = $product->variants->first();

        $result = $variant->canPurchase(2);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_subscription_quantity', $result->get_error_code());
    }

    public function test_can_purchase_rejects_bundle_product_without_pro(): void
    {
        // canPurchase() checks App::isProActive() before the bundle child stock
        // filter. Without Pro, bundles are rejected with 'invalid_bundle_product'.
        // This test verifies the guard fires; child-level stock checks require Pro.
        $child = $this->productFactory->create();
        $childVariantId = $child->variants->first()->id;
        $bundle = $this->productFactory->createBundle([$childVariantId]);
        $bundleVariant = ProductVariation::query()->find($bundle->variants->first()->id);

        $result = $bundleVariant->canPurchase(1);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_bundle_product', $result->get_error_code());
    }

    // =========================================================================
    // Spec 73-76: isStock()
    // =========================================================================

    public function test_is_stock_returns_true_when_active_with_stock(): void
    {
        $product = $this->productFactory
            ->withVariantAttributes([
                'item_status'  => 'active',
                'manage_stock' => 1,
                'available'    => 10,
                'stock_status' => Helper::IN_STOCK,
            ])
            ->create();
        $variant = $product->variants->first();

        $this->assertTrue($variant->isStock());
    }

    public function test_is_stock_returns_false_when_out_of_stock_no_manage(): void
    {
        $product = $this->productFactory
            ->withVariantAttributes([
                'item_status'  => 'active',
                'manage_stock' => 0,
                'stock_status' => Helper::OUT_OF_STOCK,
            ])
            ->create();
        $variant = $product->variants->first();

        $this->assertFalse($variant->isStock());
    }

    public function test_is_stock_returns_false_when_available_zero_no_backorders(): void
    {
        $product = $this->productFactory
            ->withVariantAttributes([
                'item_status'  => 'active',
                'manage_stock' => 1,
                'available'    => 0,
                'stock_status' => Helper::IN_STOCK,
                'backorders'   => 0,
            ])
            ->create();
        $variant = $product->variants->first();

        $this->assertFalse($variant->isStock());
    }

    public function test_is_stock_returns_false_when_item_status_inactive(): void
    {
        $product = $this->productFactory
            ->withVariantAttributes(['item_status' => 'inactive'])
            ->create();
        $variant = $product->variants->first();

        $this->assertFalse($variant->isStock());
    }

    public function test_is_stock_returns_false_for_bundle_with_oos_child(): void
    {
        // Create a child with stock management on and 0 available
        $child = $this->productFactory
            ->withVariantAttributes([
                'item_status'  => 'active',
                'manage_stock' => 1,
                'available'    => 0,
                'stock_status' => Helper::OUT_OF_STOCK,
            ])
            ->create();
        $childVariantId = $child->variants->first()->id;

        // Create bundle with stock management off (parent itself is "in stock")
        $bundle = $this->productFactory->createBundle([$childVariantId]);
        $bundleVariant = ProductVariation::query()
            ->where('post_id', $bundle->ID)
            ->first();

        // isStock() should return false because the child is OOS
        $this->assertFalse($bundleVariant->isStock());
    }

    // =========================================================================
    // Spec 77: getSubscriptionTermsText
    // =========================================================================

    public function test_get_subscription_terms_text_contains_price_and_interval(): void
    {
        $product = $this->productFactory
            ->withVariantAttributes([
                'payment_type' => 'subscription',
                'item_price'   => Helper::toCent(9.99),
                'other_info'   => [
                    'payment_type'    => 'subscription',
                    'repeat_interval' => 'monthly',
                    'times'           => 0,
                    'trial_days'      => 0,
                ],
            ])
            ->create();
        $variant = $product->variants->first();
        $text = $variant->getSubscriptionTermsText();

        $this->assertNotEmpty($text);
        // The formatted text should reference the price amount and the interval
        $this->assertStringContainsString('9', $text, 'Should contain the price amount');
        $this->assertMatchesRegularExpression('/month/i', $text, 'Should reference the monthly interval');
    }

    public function test_get_subscription_terms_text_returns_empty_for_onetime(): void
    {
        $product = $this->productFactory->create();
        $variant = $product->variants->first();

        $this->assertSame('', $variant->getSubscriptionTermsText());
    }

    // =========================================================================
    // Spec 78: getPurchaseUrl
    // =========================================================================

    public function test_get_purchase_url_contains_variant_id(): void
    {
        $product = $this->productFactory->create();
        $variant = $product->variants->first();
        $url = $variant->getPurchaseUrl();

        $this->assertStringContainsString('fluent-cart=instant_checkout', $url);
        $this->assertStringContainsString('item_id=' . $variant->id, $url);
    }

    // =========================================================================
    // Spec 79: soldIndividually delegates to product
    // =========================================================================

    public function test_sold_individually_delegates_to_product(): void
    {
        $product = $this->productFactory
            ->withDetailAttributes(['other_info' => ['sold_individually' => 'yes']])
            ->create();
        $variant = $product->variants->first();

        $this->assertTrue($variant->soldIndividually());
    }

    public function test_sold_individually_returns_false_by_default(): void
    {
        $product = $this->productFactory->create();
        $variant = $product->variants->first();

        $this->assertFalse($variant->soldIndividually());
    }

    // =========================================================================
    // Spec 80: Deleting variant cascades media and attrMap
    // =========================================================================

    public function test_deleting_variant_cascades_media_and_attr_map(): void
    {
        $product = $this->productFactory->create();
        $variant = $product->variants->first();
        $variantId = $variant->id;

        // Seed media (Meta::deleteVariationMedia targets object_type='product_variant_info')
        ProductMeta::query()->create([
            'object_id'   => $variantId,
            'object_type' => 'product_variant_info',
            'meta_key'    => 'media_data',
            'meta_value'  => [['url' => 'http://example.com/img.jpg']],
        ]);
        // Seed attrMap
        \FluentCart\App\Models\AttributeRelation::query()->create([
            'group_id'  => 1,
            'term_id'   => 1,
            'object_id' => $variantId,
        ]);

        $this->assertSame(1, ProductMeta::query()
            ->where('object_id', $variantId)->where('object_type', 'product_variant_info')->count());
        $this->assertSame(1, \FluentCart\App\Models\AttributeRelation::query()
            ->where('object_id', $variantId)->count());

        $variant->delete();

        $this->assertNull(ProductVariation::query()->find($variantId), 'Variant should be gone');
        $this->assertSame(0, ProductMeta::query()
            ->where('object_id', $variantId)->where('object_type', 'product_variant_info')->count(),
            'Media should be cascade-deleted');
        $this->assertSame(0, \FluentCart\App\Models\AttributeRelation::query()
            ->where('object_id', $variantId)->count(),
            'AttrMap should be cascade-deleted');
    }
}
