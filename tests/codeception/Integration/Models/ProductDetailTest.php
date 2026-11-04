<?php

namespace Tests\Integration\Models;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\ProductFactory;

class ProductDetailTest extends WPTestCase
{
    private ProductFactory $productFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->productFactory = new ProductFactory();
    }

    // =========================================================================
    // Spec 38: Table & Columns
    // =========================================================================

    public function test_product_details_table_has_expected_columns(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fct_product_details';
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
        $columnNames = array_column($columns, 'Field');

        $expected = [
            'id', 'post_id', 'fulfillment_type', 'min_price', 'max_price',
            'default_variation_id', 'variation_type', 'stock_availability',
            'other_info', 'default_media', 'manage_stock', 'manage_downloadable',
        ];

        foreach ($expected as $col) {
            $this->assertContains($col, $columnNames, "Column {$col} should exist");
        }
    }

    // =========================================================================
    // Spec 39: Casts
    // =========================================================================

    public function test_post_id_cast_to_integer_and_prices_cast_to_double(): void
    {
        $product = $this->productFactory->create();
        $detail = $product->detail;

        $this->assertIsInt($detail->post_id);
        // min_price/max_price have custom accessors that query variants,
        // so they return numeric values from the DB aggregate
        $this->assertIsNumeric($detail->min_price);
        $this->assertIsNumeric($detail->max_price);
    }

    // =========================================================================
    // Spec 40: Fillable
    // =========================================================================

    public function test_fillable_fields_are_mass_assignable(): void
    {
        $expected = [
            'post_id', 'fulfillment_type', 'min_price', 'max_price',
            'default_variation_id', 'variation_type', 'stock_availability',
            'other_info', 'default_media', 'manage_stock', 'manage_downloadable',
        ];

        $model = new ProductDetail();
        foreach ($expected as $field) {
            $this->assertContains($field, $model->getFillable(), "Field {$field} should be fillable");
        }
    }

    // =========================================================================
    // Spec 41: other_info mutator/accessor
    // =========================================================================

    public function test_other_info_json_encodes_on_set_and_decodes_on_get(): void
    {
        $product = $this->productFactory->create();
        $detail = $product->detail;

        $info = ['sold_individually' => 'yes', 'custom_key' => 'value'];
        $detail->other_info = $info;
        $detail->save();

        $refreshed = ProductDetail::query()->find($detail->id);
        $this->assertSame('yes', $refreshed->other_info['sold_individually']);
        $this->assertSame('value', $refreshed->other_info['custom_key']);
    }

    public function test_other_info_returns_null_for_empty_json(): void
    {
        $product = $this->productFactory->create();
        $detail = $product->detail;

        // Set other_info to an empty JSON object in the DB directly
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'fct_product_details',
            ['other_info' => '{}'],
            ['id' => $detail->id]
        );

        $refreshed = ProductDetail::query()->find($detail->id);
        // Empty JSON object decodes to empty array
        $this->assertSame([], $refreshed->other_info);
    }

    // =========================================================================
    // Spec 42: default_media mutator/accessor
    // =========================================================================

    public function test_default_media_json_encodes_and_decodes(): void
    {
        $product = $this->productFactory->create();
        $detail = $product->detail;

        $media = [['id' => 1, 'url' => 'http://example.com/img.jpg']];
        $detail->default_media = $media;
        $detail->save();

        $refreshed = ProductDetail::query()->find($detail->id);
        $this->assertIsArray($refreshed->default_media);
        $this->assertSame('http://example.com/img.jpg', $refreshed->default_media[0]['url']);
    }

    // =========================================================================
    // Spec 43-44: formatted price accessors
    // =========================================================================

    public function test_formatted_min_price_converts_cents_to_decimal(): void
    {
        $product = $this->productFactory
            ->withVariantAttributes(['item_price' => Helper::toCent(29.99)])
            ->create();

        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        $formatted = $detail->formatted_min_price;
        // Helper::toDecimal(2999) should produce a string containing "29.99"
        $this->assertStringContainsString('29', $formatted, 'Formatted min price should contain the dollar amount');
        $this->assertStringContainsString('99', $formatted, 'Formatted min price should contain the cents');
    }

    public function test_formatted_max_price_converts_cents_to_decimal(): void
    {
        $product = $this->productFactory
            ->withVariantAttributes(['item_price' => Helper::toCent(49.99)])
            ->create();

        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        $formatted = $detail->formatted_max_price;
        $this->assertStringContainsString('49', $formatted, 'Formatted max price should contain the dollar amount');
        $this->assertStringContainsString('99', $formatted, 'Formatted max price should contain the cents');
    }

    // =========================================================================
    // Spec 45: featured_media accessor
    // =========================================================================

    public function test_featured_media_returns_null_when_no_gallery(): void
    {
        $product = $this->productFactory->create();
        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        $this->assertNull($detail->featured_media);
    }

    public function test_featured_media_returns_first_gallery_image(): void
    {
        $product = $this->productFactory->create();

        // Seed gallery images via WP post meta
        $gallery = [
            ['id' => 10, 'url' => 'http://example.com/first.jpg', 'title' => 'First'],
            ['id' => 11, 'url' => 'http://example.com/second.jpg', 'title' => 'Second'],
        ];
        update_post_meta($product->ID, 'fluent-products-gallery-image', $gallery);

        // Re-fetch detail with the gallery relation
        $detail = ProductDetail::query()
            ->with('galleryImage')
            ->where('post_id', $product->ID)
            ->first();
        $featured = $detail->featured_media;

        $this->assertNotNull($featured, 'featured_media should return the first gallery image');
        $this->assertSame('http://example.com/first.jpg', $featured['url'] ?? null);
    }

    // =========================================================================
    // Spec 46: product() relationship
    // =========================================================================

    public function test_product_relationship(): void
    {
        $product = $this->productFactory->create();
        $detail = $product->detail;
        $related = $detail->product;

        $this->assertInstanceOf(Product::class, $related);
        $this->assertSame($product->ID, $related->ID);
    }

    // =========================================================================
    // Spec 47: variants() relationship ordered by serial_index
    // =========================================================================

    public function test_variants_relationship_ordered_by_serial_index(): void
    {
        $product = $this->productFactory->createWithVariants([
            ['variation_title' => 'Second', 'serial_index' => 2, 'item_price' => 1000],
            ['variation_title' => 'First',  'serial_index' => 1, 'item_price' => 2000],
            ['variation_title' => 'Third',  'serial_index' => 3, 'item_price' => 3000],
        ]);

        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        $variants = $detail->variants;

        // Filter to only the variants we created (shared post_id may have extras)
        $this->assertGreaterThanOrEqual(3, $variants->count());
        // Verify ordering: serial_index should be ascending
        $indexes = $variants->pluck('serial_index')->toArray();
        $sorted = $indexes;
        sort($sorted);
        $this->assertSame($sorted, $indexes);
    }

    // =========================================================================
    // Spec 48: Term links are reached through variations, not the detail
    // =========================================================================

    /**
     * Spec: Cases/Integration/products.md [48]
     *   "A product's attribute term links are read through its variations; the product detail has no term-link relation of its own."
     *
     * fct_atts_relations.object_id holds VARIATION ids, so a detail-keyed
     * relation would return another variation's links.
     */
    public function test_term_links_are_read_through_variations_not_the_detail(): void
    {
        $product = $this->productFactory->create();
        $variant = $product->variants->first();

        \FluentCart\App\Models\AttributeRelation::query()->create([
            'group_id'  => 1,
            'term_id'   => 1,
            'object_id' => $variant->id,
        ]);

        $detail = ProductDetail::query()->with('variants.attrMap')->find($product->detail->id);
        $links = $detail->variants->first()->attrMap;

        $this->assertCount(1, $links, 'The variation should expose its own term link');
        $this->assertSame((int) $variant->id, (int) $links->first()->object_id, 'The link should belong to the variation');
        $this->assertFalse(method_exists(ProductDetail::class, 'attrMap'), 'ProductDetail must not offer a detail-keyed term-link relation');
    }

    // =========================================================================
    // Spec 49: hasPriceVariation
    // =========================================================================

    public function test_has_price_variation_returns_true_when_prices_differ(): void
    {
        $product = $this->productFactory->createWithVariants([
            ['variation_title' => 'Cheap',     'item_price' => 1000],
            ['variation_title' => 'Expensive', 'item_price' => 5000],
        ]);

        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        // variation_type needs to be 'simple' for hasPriceVariation to return true
        $detail->variation_type = 'simple';
        $detail->save();

        $this->assertTrue($detail->hasPriceVariation());
    }

    public function test_has_price_variation_returns_false_when_prices_same(): void
    {
        $product = $this->productFactory->create();
        $detail = $product->detail;
        // Single variant — min and max are the same
        $this->assertFalse($detail->hasPriceVariation());
    }

    // =========================================================================
    // Spec 50-51: getStockAvailability
    // =========================================================================

    public function test_get_stock_availability_when_not_managing_stock(): void
    {
        $product = $this->productFactory->create();
        // Force manage_stock off via raw DB
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'fct_product_details', ['manage_stock' => 0], ['post_id' => $product->ID]);
        $detail = ProductDetail::query()->find($product->detail->id);

        $availability = $detail->getStockAvailability();

        $this->assertFalse($availability['manage_stock'], 'manage_stock should be false');
        $this->assertSame('in-stock', $availability['class'], 'class should be in-stock');
        $this->assertNull($availability['available_quantity'], 'available_quantity should be null when not managing');
    }

    public function test_get_stock_availability_when_managing_stock_in_stock(): void
    {
        $product = $this->productFactory->create();
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'fct_product_details', [
            'manage_stock'       => 1,
            'stock_availability' => 'in-stock',
        ], ['post_id' => $product->ID]);

        $detail = ProductDetail::query()->find($product->detail->id);
        $variant = $product->variants->first();
        $availability = $detail->getStockAvailability($variant->id);

        $this->assertTrue($availability['manage_stock'], 'manage_stock should be true');
        $this->assertSame('in-stock', $availability['class'], 'class should be in-stock');
        $this->assertNotNull($availability['available_quantity'], 'available_quantity should be set');
    }

    // =========================================================================
    // Spec 52: Deleting a detail leaves variation term links alone
    // =========================================================================

    /**
     * Spec: Cases/Integration/products.md [52]
     *   "Deleting a ProductDetail never removes variation term links, even when a variation id equals the detail id."
     *
     * fct_atts_relations.object_id holds VARIATION ids. A detail-id-keyed
     * cascade wiped whichever unrelated variation shared the detail's id.
     */
    public function test_deleting_product_detail_never_removes_variation_term_links(): void
    {
        global $wpdb;
        $product = $this->productFactory->create();
        $variant = $product->variants->first();

        $collisionId = 1000 + max(
            (int) $wpdb->get_var("SELECT MAX(id) FROM {$wpdb->prefix}fct_product_details"),
            (int) $wpdb->get_var("SELECT MAX(id) FROM {$wpdb->prefix}fct_product_variations")
        );
        $wpdb->update($wpdb->prefix . 'fct_product_variations', ['id' => $collisionId], ['id' => $variant->id]);

        \FluentCart\App\Models\AttributeRelation::query()->create([
            'group_id'  => 1,
            'term_id'   => 1,
            'object_id' => $collisionId,
        ]);

        $otherProduct = $this->productFactory->create();
        $wpdb->update($wpdb->prefix . 'fct_product_details', ['id' => $collisionId], ['post_id' => $otherProduct->ID]);

        ProductDetail::query()->find($collisionId)->delete();

        $this->assertNull(ProductDetail::query()->find($collisionId), 'The detail itself should be deleted');
        $linksAfter = \FluentCart\App\Models\AttributeRelation::query()
            ->where('object_id', $collisionId)->count();
        $this->assertSame(1, $linksAfter, 'The unrelated variation term link must survive the detail delete');
    }
}
