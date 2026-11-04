<?php

namespace Tests\Integration\Models;

use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductDownload;
use FluentCart\App\Models\ProductMeta;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductDownloadFactory;
use Tests\Support\Factory\ProductFactory;

class ProductTest extends WPTestCase
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
    // Spec 1: Table & Global Scope
    // =========================================================================

    public function test_product_uses_posts_table_with_fluent_products_cpt_and_excludes_auto_draft(): void
    {
        $product = $this->productFactory->create();
        $this->assertSame('posts', (new Product())->getTable());
        $this->assertSame(FluentProducts::CPT_NAME, $product->post_type);

        // Create an auto-draft — should not appear in queries
        wp_insert_post([
            'post_title'  => 'Auto Draft Product',
            'post_status' => 'auto-draft',
            'post_type'   => FluentProducts::CPT_NAME,
        ]);

        $all = Product::query()->get();
        foreach ($all as $p) {
            $this->assertNotSame('auto-draft', $p->post_status);
        }
    }

    // =========================================================================
    // Spec 2: Creating event auto-sets post_type
    // =========================================================================

    public function test_creating_product_auto_sets_post_type(): void
    {
        $product = new Product();
        $product->post_title = 'Type Test';
        $product->post_status = 'draft';
        $product->save();

        $this->assertSame(FluentProducts::CPT_NAME, $product->post_type);
    }

    // =========================================================================
    // Spec 3: Fillable fields
    // =========================================================================

    public function test_fillable_fields_are_mass_assignable(): void
    {
        $expected = [
            'post_content', 'post_title', 'post_excerpt', 'post_author',
            'post_date', 'post_date_gmt', 'post_content_filtered', 'post_status',
            'post_type', 'comment_status', 'ping_status', 'post_password',
            'post_name', 'to_ping', 'pinged', 'post_modified', 'post_modified_gmt',
            'post_parent', 'menu_order', 'post_mime_type', 'guid',
        ];

        $model = new Product();
        foreach ($expected as $field) {
            $this->assertContains($field, $model->getFillable(), "Field {$field} should be fillable");
        }
    }

    // =========================================================================
    // Spec 4: Hidden fields excluded from serialization
    // =========================================================================

    public function test_hidden_fields_excluded_from_serialization(): void
    {
        $product = $this->productFactory->create();
        $array = $product->toArray();

        $hidden = [
            'post_content_filtered', 'post_password', 'post_author',
            'to_ping', 'pinged', 'post_parent', 'menu_order',
            'post_mime_type', 'comment_count',
        ];

        foreach ($hidden as $field) {
            $this->assertArrayNotHasKey($field, $array, "Field {$field} should be hidden");
        }
    }

    // =========================================================================
    // Spec 5: thumbnail accessor
    // =========================================================================

    public function test_thumbnail_accessor_returns_placeholder_when_no_featured_image(): void
    {
        $product = $this->productFactory->create();
        $thumbnail = $product->thumbnail;

        $this->assertNotEmpty($thumbnail);
        $this->assertStringContainsString('placeholder', $thumbnail);
    }

    public function test_thumbnail_accessor_returns_gallery_url_when_set(): void
    {
        $product = $this->productFactory->create();

        // Seed gallery images — thumbnail accessor reads detail.featured_media
        // which is the first element of the gallery post meta
        $gallery = [
            ['id' => 10, 'url' => 'http://example.com/featured.jpg', 'title' => 'Featured'],
        ];
        update_post_meta($product->ID, 'fluent-products-gallery-image', $gallery);

        // Re-fetch with detail to pick up the gallery relation
        $fresh = Product::query()->with(['detail.galleryImage'])->find($product->ID);
        $this->assertSame('http://example.com/featured.jpg', $fresh->thumbnail);
    }

    // =========================================================================
    // Spec 6: has_subscription accessor
    // =========================================================================

    public function test_has_subscription_returns_true_when_subscription_variant_exists(): void
    {
        $product = $this->productFactory->createWithVariants([
            [
                'variation_title' => 'Monthly',
                'payment_type'    => 'subscription',
                'item_price'      => 999,
                'other_info'      => ['payment_type' => 'subscription', 'repeat_interval' => 'monthly'],
            ],
        ]);

        $this->assertTrue($product->has_subscription);
    }

    public function test_has_subscription_returns_false_when_all_variants_onetime(): void
    {
        $product = $this->productFactory->create();
        $this->assertFalse($product->has_subscription);
    }

    // =========================================================================
    // Spec 7: view_url accessor
    // =========================================================================

    public function test_view_url_returns_product_permalink(): void
    {
        $product = $this->productFactory->create();
        $expected = get_permalink($product->ID);
        $this->assertSame($expected, $product->view_url,
            'view_url should return the same value as get_permalink()');
    }

    // =========================================================================
    // Spec 8: edit_url accessor
    // =========================================================================

    public function test_edit_url_returns_admin_edit_url(): void
    {
        $product = $this->productFactory->create();
        $this->assertStringContainsString('post.php?post=' . $product->ID, $product->edit_url);
        $this->assertStringContainsString('action=edit', $product->edit_url);
    }

    // =========================================================================
    // Spec 9-10: categories accessor
    //
    // The two tags-accessor cases were deleted here: product-tags does not ship.
    // ecc86def4 ("rework: remove product-tags consumers — the taxonomy will not
    // ship", product decision 2026-08-06) removed the Product tag getters and the
    // shop shortcode tag= filter, and app/CPT/FluentProducts.php:313 records that
    // the taxonomy is deliberately NOT registered. That commit left these two
    // tests behind, so they asserted an accessor production no longer has and
    // both registered the taxonomy themselves to make the assertion reachable.
    // See tests/Cases/DELETION-ALLOWLIST.md — no successor: the feature is gone.
    // =========================================================================

    public function test_categories_accessor_returns_empty_when_none_assigned(): void
    {
        if (!taxonomy_exists('product-categories')) {
            register_taxonomy('product-categories', 'fluent-products');
        }
        $product = $this->productFactory->create();
        $categories = $product->categories;
        $this->assertFalse($categories, 'categories accessor should return false when none assigned');
    }

    public function test_categories_accessor_returns_assigned_categories(): void
    {
        $product = $this->productFactory->create();
        if (!taxonomy_exists('product-categories')) {
            register_taxonomy('product-categories', 'fluent-products');
        }
        wp_set_object_terms($product->ID, ['test-category'], 'product-categories');

        $refreshed = Product::query()->find($product->ID);
        $categories = $refreshed->categories;
        $this->assertIsArray($categories);
        $this->assertCount(1, $categories);
        $this->assertSame('test-category', $categories[0]->slug);
    }

    // =========================================================================
    // Spec 11: published scope
    // =========================================================================

    public function test_published_scope_filters_to_publish_only(): void
    {
        $this->productFactory->create(['post_status' => 'publish']);
        $this->productFactory->create(['post_status' => 'draft']);

        $published = Product::query()->published()->get();
        foreach ($published as $p) {
            $this->assertSame('publish', $p->post_status);
        }
        $this->assertGreaterThanOrEqual(1, $published->count());
    }

    // =========================================================================
    // Spec 12: statusOf scope
    // =========================================================================

    public function test_status_of_scope_filters_to_given_status(): void
    {
        $this->productFactory->create(['post_status' => 'draft']);
        $drafts = Product::query()->statusOf('draft')->get();

        $this->assertGreaterThanOrEqual(1, $drafts->count());
        foreach ($drafts as $p) {
            $this->assertSame('draft', $p->post_status);
        }
    }

    // =========================================================================
    // Spec 13: adminAll scope
    // =========================================================================

    public function test_admin_all_scope_includes_publish_and_draft_but_not_trash(): void
    {
        $published = $this->productFactory->create(['post_status' => 'publish']);
        $draft = $this->productFactory->create(['post_status' => 'draft']);

        $adminStatuses = Status::productAdminAllStatuses();
        $all = Product::query()->adminAll()->get();
        $ids = $all->pluck('ID')->toArray();

        // Both non-trash products should be included
        $this->assertContains($published->ID, $ids, 'Published product should be in adminAll');
        $this->assertContains($draft->ID, $ids, 'Draft product should be in adminAll');

        // Every returned product should have an allowed status
        foreach ($all as $p) {
            $this->assertContains($p->post_status, $adminStatuses);
        }

        // Trash should not be in adminAll statuses
        $this->assertNotContains('trash', $adminStatuses);
    }

    // =========================================================================
    // Spec 14-15: bundle / nonBundle scopes
    // =========================================================================

    public function test_bundle_scope_includes_bundles_and_excludes_regular(): void
    {
        $regular = $this->productFactory->create();
        $bundle = $this->productFactory
            ->withDetailAttributes(['other_info' => ['is_bundle_product' => 'yes']])
            ->create();

        $bundles = Product::query()->bundle()->get();
        $ids = $bundles->pluck('ID')->toArray();
        $this->assertContains($bundle->ID, $ids, 'Bundle product should be included');
        $this->assertNotContains($regular->ID, $ids, 'Regular product should be excluded from bundle scope');
    }

    public function test_non_bundle_scope_includes_regular_and_excludes_bundles(): void
    {
        $regular = $this->productFactory->create();
        $bundle = $this->productFactory
            ->withDetailAttributes(['other_info' => ['is_bundle_product' => 'yes']])
            ->create();

        $nonBundles = Product::query()->nonBundle()->get();
        $ids = $nonBundles->pluck('ID')->toArray();
        $this->assertContains($regular->ID, $ids, 'Regular product should be included');
        $this->assertNotContains($bundle->ID, $ids, 'Bundle product should be excluded from nonBundle scope');
    }

    // =========================================================================
    // Spec 16-19: byVariantTypes scope
    // =========================================================================

    public function test_by_variant_types_physical_includes_and_excludes_correctly(): void
    {
        $physical = $this->productFactory->withVariantAttributes(['fulfillment_type' => 'physical'])->create();
        $digital = $this->productFactory->withVariantAttributes(['fulfillment_type' => 'digital'])->create();

        $results = Product::query()->byVariantTypes('physical')->get();
        $ids = $results->pluck('ID')->toArray();
        $this->assertContains($physical->ID, $ids, 'Physical product should be included');
        $this->assertNotContains($digital->ID, $ids, 'Digital product should be excluded from physical filter');
    }

    public function test_by_variant_types_digital_includes_and_excludes_correctly(): void
    {
        $digital = $this->productFactory->withVariantAttributes(['fulfillment_type' => 'digital'])->create();
        $physical = $this->productFactory->withVariantAttributes(['fulfillment_type' => 'physical'])->create();

        $results = Product::query()->byVariantTypes('digital')->get();
        $ids = $results->pluck('ID')->toArray();
        $this->assertContains($digital->ID, $ids, 'Digital product should be included');
        $this->assertNotContains($physical->ID, $ids, 'Physical product should be excluded from digital filter');
    }

    public function test_by_variant_types_subscription_includes_and_excludes_correctly(): void
    {
        $sub = $this->productFactory->withVariantAttributes([
            'payment_type' => 'subscription',
            'other_info'   => ['payment_type' => 'subscription', 'repeat_interval' => 'monthly'],
        ])->create();
        $onetime = $this->productFactory->create();

        $results = Product::query()->byVariantTypes('subscription')->get();
        $ids = $results->pluck('ID')->toArray();
        $this->assertContains($sub->ID, $ids, 'Subscription product should be included');
        $this->assertNotContains($onetime->ID, $ids, 'Onetime product should be excluded from subscription filter');
    }

    public function test_by_variant_types_onetime_includes_and_excludes_correctly(): void
    {
        $onetime = $this->productFactory->create();
        $sub = $this->productFactory->withVariantAttributes([
            'payment_type' => 'subscription',
            'other_info'   => ['payment_type' => 'subscription', 'repeat_interval' => 'monthly'],
        ])->create();

        $results = Product::query()->byVariantTypes('onetime')->get();
        $ids = $results->pluck('ID')->toArray();
        $this->assertContains($onetime->ID, $ids, 'Onetime product should be included');
        $this->assertNotContains($sub->ID, $ids, 'Subscription product should be excluded from onetime filter');
    }

    // =========================================================================
    // Spec 20: applyCustomSortBy
    // =========================================================================

    public function test_apply_custom_sort_by_title_asc(): void
    {
        $p1 = $this->productFactory->create(['post_title' => 'AAA Sort Product']);
        $p2 = $this->productFactory->create(['post_title' => 'ZZZ Sort Product']);

        $sorted = Product::query()->applyCustomSortBy('title', 'ASC')->get();
        $titles = $sorted->pluck('post_title')->toArray();
        $expected = $titles;
        sort($expected);
        $this->assertSame($expected, $titles, 'Title ASC should sort alphabetically');
    }

    public function test_apply_custom_sort_by_id_desc(): void
    {
        $p1 = $this->productFactory->create(['post_title' => 'ID Sort A']);
        $p2 = $this->productFactory->create(['post_title' => 'ID Sort B']);

        $sorted = Product::query()->applyCustomSortBy('id', 'DESC')->get();
        $ids = $sorted->pluck('ID')->toArray();
        // DESC means highest ID first
        $expected = $ids;
        rsort($expected);
        $this->assertSame($expected, $ids, 'ID DESC should have highest ID first');
    }

    public function test_apply_custom_sort_by_price(): void
    {
        $cheap = $this->productFactory
            ->withVariantAttributes(['item_price' => 100])
            ->create(['post_title' => 'PriceSort Cheap']);
        $expensive = $this->productFactory
            ->withVariantAttributes(['item_price' => 9999])
            ->create(['post_title' => 'PriceSort Expensive']);

        // Update stored min_price so the join works
        ProductDetail::query()->where('post_id', $cheap->ID)->update(['min_price' => 100]);
        ProductDetail::query()->where('post_id', $expensive->ID)->update(['min_price' => 9999]);

        $sorted = Product::query()->applyCustomSortBy('price', 'ASC')->get();
        $sortedIds = $sorted->pluck('ID')->toArray();

        $cheapIdx = array_search($cheap->ID, $sortedIds);
        $expensiveIdx = array_search($expensive->ID, $sortedIds);
        $this->assertLessThan($expensiveIdx, $cheapIdx, 'Cheaper product should come first in price ASC');
    }

    public function test_apply_custom_sort_by_date(): void
    {
        $older = $this->productFactory->create(['post_title' => 'DateSort Older', 'post_date' => '2024-01-01 00:00:00']);
        $newer = $this->productFactory->create(['post_title' => 'DateSort Newer', 'post_date' => '2025-06-01 00:00:00']);

        $sorted = Product::query()->applyCustomSortBy('date', 'ASC')->get();
        $sortedIds = $sorted->pluck('ID')->toArray();

        $olderIdx = array_search($older->ID, $sortedIds);
        $newerIdx = array_search($newer->ID, $sortedIds);
        $this->assertLessThan($newerIdx, $olderIdx, 'Older product should come first in date ASC');
    }

    // =========================================================================
    // Spec 21-26: Relationships
    // =========================================================================

    public function test_detail_relationship_returns_product_detail(): void
    {
        $product = $this->productFactory->create();
        $this->assertInstanceOf(ProductDetail::class, $product->detail);
        $this->assertSame($product->ID, $product->detail->post_id);
    }

    public function test_variants_relationship_returns_collection(): void
    {
        $product = $this->productFactory->create();
        $this->assertGreaterThanOrEqual(1, $product->variants->count());
        $this->assertInstanceOf(ProductVariation::class, $product->variants->first());
    }

    public function test_downloadable_files_relationship(): void
    {
        $product = $this->productFactory->create();
        $this->downloadFactory->create($product->ID);

        $product->load('downloadable_files');
        $this->assertGreaterThanOrEqual(1, $product->downloadable_files->count());
        $this->assertInstanceOf(ProductDownload::class, $product->downloadable_files->first());
    }

    public function test_order_items_relationship_returns_linked_items(): void
    {
        $product = $this->productFactory->create();

        // Create an OrderItem linked to this product's post_id
        \FluentCart\App\Models\OrderItem::query()->create([
            'order_id'  => 0,
            'post_id'   => $product->ID,
            'object_id' => $product->variants->first()->id,
            'title'     => 'Test Item',
            'quantity'  => 1,
            'unit_price' => 1000,
            'subtotal'  => 1000,
        ]);

        $fresh = Product::query()->find($product->ID);
        $items = $fresh->orderItems;
        $this->assertCount(1, $items);
        $this->assertSame('Test Item', $items->first()->title);
    }

    public function test_integrations_relationship_filters_by_object_type(): void
    {
        $product = $this->productFactory->create();

        // Matching row: object_type = 'product_integration'
        ProductMeta::query()->create([
            'object_id'   => $product->ID,
            'object_type' => 'product_integration',
            'meta_key'    => 'test_integration',
            'meta_value'  => ['enabled' => true],
        ]);
        // Distractor row: different object_type on the same product
        ProductMeta::query()->create([
            'object_id'   => $product->ID,
            'object_type' => 'other_type',
            'meta_key'    => 'should_not_appear',
            'meta_value'  => 'noise',
        ]);

        $fresh = Product::query()->find($product->ID);
        $integrations = $fresh->integrations;

        $this->assertCount(1, $integrations, 'Should return only the product_integration row');
        $this->assertSame('product_integration', $integrations->first()->object_type);
        $this->assertSame('test_integration', $integrations->first()->meta_key);
    }

    public function test_licenses_meta_relationship(): void
    {
        $product = $this->productFactory->create();
        ProductMeta::query()->create([
            'object_id'   => $product->ID,
            'object_type' => null,
            'meta_key'    => 'license_settings',
            'meta_value'  => ['max_activations' => 5],
        ]);

        $product->load('licensesMeta');
        $this->assertNotNull($product->licensesMeta);
        $this->assertSame('license_settings', $product->licensesMeta->meta_key);
    }

    // =========================================================================
    // Spec 27-28: getProductMeta / updateProductMeta
    // =========================================================================

    public function test_get_product_meta_returns_value_for_existing_key(): void
    {
        $product = $this->productFactory->create();
        ProductMeta::query()->create([
            'object_id'  => $product->ID,
            'meta_key'   => 'test_key',
            'meta_value' => 'test_value',
        ]);

        $this->assertSame('test_value', $product->getProductMeta('test_key'));
    }

    public function test_get_product_meta_returns_default_for_nonexistent_key(): void
    {
        $product = $this->productFactory->create();
        $this->assertSame('fallback', $product->getProductMeta('nonexistent', null, 'fallback'));
        $this->assertNull($product->getProductMeta('nonexistent'));
    }

    public function test_update_product_meta_creates_new_when_not_exists(): void
    {
        $product = $this->productFactory->create();
        $meta = $product->updateProductMeta('new_key', 'new_value');

        $this->assertSame('new_key', $meta->meta_key);
        $this->assertSame('new_value', $meta->meta_value);
    }

    public function test_update_product_meta_updates_existing(): void
    {
        $product = $this->productFactory->create();
        $product->updateProductMeta('my_key', 'old_value');
        $product->updateProductMeta('my_key', 'new_value');

        $this->assertSame('new_value', $product->getProductMeta('my_key'));
        // Ensure only one meta record exists
        $count = ProductMeta::query()
            ->where('object_id', $product->ID)
            ->where('meta_key', 'my_key')
            ->count();
        $this->assertSame(1, $count);
    }

    // =========================================================================
    // Spec 29: isBundleProduct
    // =========================================================================

    public function test_is_bundle_product_returns_true_for_bundle(): void
    {
        $product = $this->productFactory
            ->withDetailAttributes(['other_info' => ['is_bundle_product' => 'yes']])
            ->create();
        $this->assertTrue($product->isBundleProduct());
    }

    public function test_is_bundle_product_returns_false_for_regular(): void
    {
        $product = $this->productFactory->create();
        $this->assertFalse($product->isBundleProduct());
    }

    // =========================================================================
    // Spec 30: soldIndividually
    // =========================================================================

    public function test_sold_individually_returns_true_when_set(): void
    {
        $product = $this->productFactory->create();

        // Force the other_info via direct DB to avoid ORM caching in full-suite
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'fct_product_details',
            ['other_info' => json_encode(['sold_individually' => 'yes'])],
            ['post_id' => $product->ID]
        );

        // Re-fetch fresh
        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        $this->assertSame('yes', $detail->other_info['sold_individually'] ?? null,
            'other_info.sold_individually should be stored as yes');

        $fresh = Product::query()->find($product->ID);
        $fresh->setRelation('detail', $detail);
        $this->assertTrue($fresh->soldIndividually());
    }

    public function test_sold_individually_returns_false_by_default(): void
    {
        $product = $this->productFactory->create();
        $this->assertFalse($product->soldIndividually());
    }

    // =========================================================================
    // Spec 31: isStock
    // =========================================================================

    public function test_is_stock_returns_true_when_not_managing_stock(): void
    {
        $product = $this->productFactory->create();
        $this->assertTrue($product->isStock());
    }

    public function test_is_stock_returns_true_when_in_stock(): void
    {
        $product = $this->productFactory
            ->withDetailAttributes([
                'manage_stock'       => 1,
                'stock_availability' => Helper::IN_STOCK,
            ])
            ->withVariantAttributes([
                'manage_stock' => 1,
                'available'    => 10,
                'stock_status' => Helper::IN_STOCK,
            ])
            ->create();
        $this->assertTrue($product->isStock());
    }

    public function test_is_stock_returns_false_when_out_of_stock(): void
    {
        $created = $this->productFactory
            ->withDetailAttributes([
                'manage_stock'       => 1,
                'stock_availability' => Helper::OUT_OF_STOCK,
            ])
            ->withVariantAttributes([
                'manage_stock' => 1,
                'available'    => 0,
                'stock_status' => Helper::OUT_OF_STOCK,
            ])
            ->create();

        // Verify and force the correct state via direct DB update to avoid
        // any ORM caching from prior tests in the suite
        global $wpdb;
        $detailTable = $wpdb->prefix . 'fct_product_details';
        $variantTable = $wpdb->prefix . 'fct_product_variations';

        $wpdb->update($detailTable, [
            'manage_stock'       => 1,
            'stock_availability' => Helper::OUT_OF_STOCK,
        ], ['post_id' => $created->ID]);

        $wpdb->update($variantTable, [
            'manage_stock' => 1,
            'available'    => 0,
            'stock_status' => Helper::OUT_OF_STOCK,
        ], ['post_id' => $created->ID]);

        // Re-fetch everything fresh from DB
        $product = Product::query()->find($created->ID);
        $detail = ProductDetail::query()->where('post_id', $created->ID)->first();
        $variants = ProductVariation::query()->where('post_id', $created->ID)->get();
        $product->setRelation('detail', $detail);
        $product->setRelation('variants', $variants);
        $this->assertFalse($product->isStock());
    }

    // =========================================================================
    // Spec 32: images
    // =========================================================================

    public function test_images_returns_thumbnail_when_no_gallery(): void
    {
        $product = $this->productFactory->create();
        $images = $product->images();

        $this->assertIsArray($images);
        $this->assertGreaterThanOrEqual(1, count($images));
        // With no gallery, images() returns a single thumbnail entry
        $this->assertSame('thumbnail', $images[0]['type']);
        $this->assertArrayHasKey('url', $images[0]);
        $this->assertArrayHasKey('alt', $images[0]);
        $this->assertArrayHasKey('product_title', $images[0]);
    }

    public function test_images_includes_gallery_images_when_set(): void
    {
        $product = $this->productFactory->create();

        $gallery = [
            ['id' => 10, 'url' => 'http://example.com/gallery1.jpg', 'title' => 'Gallery 1'],
            ['id' => 11, 'url' => 'http://example.com/gallery2.jpg', 'title' => 'Gallery 2'],
        ];
        update_post_meta($product->ID, 'fluent-products-gallery-image', $gallery);

        $fresh = Product::query()->with(['detail', 'variants'])->find($product->ID);
        $images = $fresh->images();

        $galleryImages = array_filter($images, fn($img) => $img['type'] === 'gallery_image');
        $this->assertCount(2, $galleryImages, 'Should include 2 gallery images');
        $this->assertSame('http://example.com/gallery1.jpg', array_values($galleryImages)[0]['url']);
    }

    public function test_images_includes_variation_images_when_media_attached(): void
    {
        $product = $this->productFactory->create();
        $variant = $product->variants->first();

        // Attach media to the variant via product_thumbnail meta
        ProductMeta::query()->create([
            'object_id'  => $variant->id,
            'meta_key'   => 'product_thumbnail',
            'meta_value' => [['url' => 'http://example.com/variant-img.jpg', 'id' => 20, 'title' => 'Variant Img']],
        ]);

        $fresh = Product::query()->with(['detail', 'variants.media'])->find($product->ID);
        $images = $fresh->images();

        $variantImages = array_filter($images, fn($img) => $img['type'] === 'variation_image');
        $this->assertCount(1, $variantImages, 'Should include 1 variation image');
        $this->assertSame('http://example.com/variant-img.jpg', array_values($variantImages)[0]['url']);
    }

    // =========================================================================
    // Spec 33-37: Product Duplication
    // =========================================================================

    public function test_duplicate_product_creates_new_product_with_same_data(): void
    {
        $userId = static::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($userId);

        // Register taxonomies so term assignment works
        if (!taxonomy_exists('product-categories')) {
            register_taxonomy('product-categories', 'fluent-products');
        }

        $product = $this->productFactory->create([
            'post_title'   => 'Original Product',
            'post_content' => 'Original content',
        ]);

        // Assign a taxonomy term
        wp_set_object_terms($product->ID, ['dup-test-category'], 'product-categories');

        // Add post meta
        add_post_meta($product->ID, '_custom_test_meta', 'test_value');

        $newId = Product::duplicateProduct($product->ID);

        $this->assertGreaterThan(0, $newId);
        $this->assertNotSame($product->ID, $newId);

        $newProduct = Product::query()->with(['detail', 'variants'])->find($newId);

        // Core fields
        $this->assertStringContainsString('Copy', $newProduct->post_title);
        $this->assertSame('draft', $newProduct->post_status);
        $this->assertSame('Original content', $newProduct->post_content);

        // Detail and variants copied
        $this->assertNotNull($newProduct->detail);
        $this->assertSame($product->detail->fulfillment_type, $newProduct->detail->fulfillment_type);
        $newVariants = ProductVariation::query()->where('post_id', $newId)->get();
        $this->assertGreaterThanOrEqual(1, $newVariants->count());

        // Taxonomy terms copied
        $newTerms = wp_get_object_terms($newId, 'product-categories', ['fields' => 'slugs']);
        $this->assertContains('dup-test-category', $newTerms, 'Taxonomy terms should be copied');

        // Post meta copied
        $metaValue = get_post_meta($newId, '_custom_test_meta', true);
        $this->assertSame('test_value', $metaValue, 'Post meta should be copied');
    }

    public function test_duplicate_product_fires_action_with_full_payload(): void
    {
        $userId = static::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($userId);

        $product = $this->productFactory->create();

        $receivedData = null;
        add_action('fluent_cart/product_duplicated', function ($data) use (&$receivedData) {
            $receivedData = $data;
        });

        $newId = Product::duplicateProduct($product->ID, ['import_stock_management' => true]);

        $this->assertNotNull($receivedData, 'fluent_cart/product_duplicated should fire');
        $this->assertSame($product->ID, $receivedData['original_product_id']);
        $this->assertSame($newId, $receivedData['new_product_id']);

        // Spec 36: options key must be present with the flags passed to duplicateProduct
        $this->assertArrayHasKey('options', $receivedData, 'Payload should include options');
        $this->assertTrue($receivedData['options']['import_stock_management']);
        $this->assertFalse($receivedData['options']['import_license_settings']);
        $this->assertFalse($receivedData['options']['import_downloadable_files']);
    }

    public function test_duplicate_product_with_nonexistent_id_throws_runtime_exception(): void
    {
        // The source code explicitly throws RuntimeException('Product not found')
        // for missing products. Spec 37 originally said "returns 0 or handles
        // gracefully" but the implementation chose to throw — this test matches
        // the actual code.
        $userId = static::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($userId);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Product not found');
        Product::duplicateProduct(999999);
    }
}
