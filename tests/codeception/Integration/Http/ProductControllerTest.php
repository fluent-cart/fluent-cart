<?php

namespace Tests\Integration\Http;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use Tests\Support\RestTestCase;
use Tests\Support\Factory\ProductFactory;

class ProductControllerTest extends RestTestCase
{
    private ProductFactory $productFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->productFactory = new ProductFactory();
    }

    public function tearDown(): void
    {
        // test_toggle_manage_stock_persists_to_detail arms the Stock Management
        // module; the option row rolls back with the transaction, the memoized
        // ModuleSettings cache does not.
        delete_option(ModuleSettings::MODULE_SETTINGS_OPTION);
        ModuleSettings::getAllSettings(false);
        wp_cache_flush();
        gc_collect_cycles();
        parent::tearDown();
    }

    // =========================================================================
    // Spec 127-129: GET /products
    // =========================================================================

    public function test_get_products_returns_list_in_id_desc_order(): void
    {
        $p1 = $this->productFactory->create(['post_title' => 'ListTestAlpha']);
        $p2 = $this->productFactory->create(['post_title' => 'ListTestBeta']);

        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/products');
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $products = $this->extractProducts($data);
        $productIds = array_column($products, 'ID');

        $this->assertContains($p1->ID, $productIds, 'p1 should appear in list');
        $this->assertContains($p2->ID, $productIds, 'p2 should appear in list');

        // Default sort is id DESC — p2 (higher ID) should come before p1
        $p1Idx = array_search($p1->ID, $productIds);
        $p2Idx = array_search($p2->ID, $productIds);
        $this->assertLessThan($p1Idx, $p2Idx, 'Default sort is id DESC: p2 should appear before p1');
    }

    public function test_get_products_search_excludes_non_matching(): void
    {
        $target = $this->productFactory->create(['post_title' => 'UniqueSearchXYZ123']);
        $other = $this->productFactory->create(['post_title' => 'TotallyDifferentProduct']);

        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/products');
        $request->set_param('search', 'UniqueSearchXYZ123');
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $products = $this->extractProducts($data);
        $productIds = array_column($products, 'ID');

        $this->assertContains($target->ID, $productIds, 'Search target should be found');
        $this->assertNotContains($other->ID, $productIds, 'Non-matching product should be excluded');
    }

    public function test_get_products_filters_by_active_view_correctly(): void
    {
        $published = $this->productFactory->create(['post_status' => 'publish', 'post_title' => 'StatusFilterPub']);
        $draft = $this->productFactory->create(['post_status' => 'draft', 'post_title' => 'StatusFilterDraft']);

        // ProductFilter uses 'active_view' param, mapped through tabsMap()
        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/products');
        $request->set_param('active_view', 'draft');
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $products = $this->extractProducts($data);
        $productIds = array_column($products, 'ID');

        $this->assertContains($draft->ID, $productIds, 'Draft product should appear in draft filter');
        $this->assertNotContains($published->ID, $productIds, 'Published product should not appear in draft filter');
    }

    // =========================================================================
    // Spec 130-133: POST /products
    // =========================================================================

    public function test_create_simple_product_creates_post_detail_and_starter_variant(): void
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products');
        $request->set_body_params([
            'post_title' => 'New REST Product',
            'detail'     => [
                'fulfillment_type' => 'digital',
                'variation_type'   => 'simple',
            ],
        ]);

        $response = $this->dispatchRequest($request);
        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $productId = $data['data']['ID'];

        // Verify post was created
        $post = get_post($productId);
        $this->assertSame('New REST Product', $post->post_title);
        $this->assertSame('fluent-products', $post->post_type);
        $this->assertSame('draft', $post->post_status);

        // Verify detail was created
        $detail = ProductDetail::query()->where('post_id', $productId)->first();
        $this->assertNotNull($detail, 'ProductDetail should be created');
        $this->assertSame('digital', $detail->fulfillment_type);

        // Simple products get a default starter variant from the controller.
        // Assert on the EXACT row the controller says it created (data.variant),
        // not the first row matching post_id — WordPress post IDs restart while
        // committed fct_* rows can survive, so a stale variation on a reused
        // post ID must not be able to satisfy this oracle.
        $variantId = (int) ($data['data']['variant']['id'] ?? 0);
        $this->assertGreaterThan(0, $variantId, 'create response must carry the starter variant it created');

        $variant = ProductVariation::query()->find($variantId);
        $this->assertNotNull($variant, 'the returned starter variant id must exist in fct_product_variations');
        $this->assertSame($productId, (int) $variant->post_id, 'the returned starter variant must belong to the created product');
        $this->assertSame('onetime', $variant->payment_type);
        $this->assertSame('in-stock', $variant->stock_status);
    }

    public function test_create_variations_product_creates_no_starter_variant(): void
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products');
        $request->set_body_params([
            'post_title' => 'New Variations Product',
            'detail'     => [
                'fulfillment_type' => 'digital',
                'variation_type'   => 'simple_variations',
            ],
        ]);

        $response = $this->dispatchRequest($request);
        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $productId = $data['data']['ID'];
        $this->assertNull(
            $data['data']['variant'],
            'the create response must not claim a starter variant for a variation-type product'
        );
        $this->assertNotNull(
            ProductDetail::query()->where('post_id', $productId)->first(),
            'ProductDetail should be created'
        );
        $this->assertSame(
            0,
            ProductVariation::query()->where('post_id', $productId)->count(),
            'Variation-type products must be created with no starter variant — variants are built on the edit page'
        );
    }

    public function test_create_product_requires_title(): void
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products');
        $request->set_body_params([
            'detail' => ['fulfillment_type' => 'digital'],
        ]);

        $response = $this->dispatchRequestSilently($request);
        $this->assertGreaterThanOrEqual(400, $response->get_status());
    }

    // =========================================================================
    // Spec 134-135: GET /products/{product}
    // =========================================================================

    public function test_find_product_returns_product_with_correct_title_and_type(): void
    {
        $product = $this->productFactory->create(['post_title' => 'FindMe']);

        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/products/' . $product->ID);
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertArrayHasKey('product', $data);

        // Extract product from response (may be object or array)
        $p = is_array($data['product']) ? $data['product'] : $data['product']->toArray();
        $this->assertSame($product->ID, $p['ID']);
        $this->assertSame('FindMe', $p['post_title']);
        $this->assertSame('fluent-products', $p['post_type']);
    }

    public function test_find_nonexistent_product_returns_error(): void
    {
        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/products/999999');
        $response = $this->dispatchRequestSilently($request);

        $this->assertGreaterThanOrEqual(400, $response->get_status());
    }

    // =========================================================================
    // Spec 136: GET /products/{productId}/pricing
    // =========================================================================

    public function test_get_product_pricing_returns_product_with_detail_variants_and_taxonomies(): void
    {
        $product = $this->productFactory->create(['post_title' => 'PricingProduct']);

        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();

        // Controller::get() returns product, product_menu, taxonomies
        $this->assertArrayHasKey('product', $data);
        $this->assertArrayHasKey('taxonomies', $data);

        $productData = $data['product'];
        // Product should include detail and variants from eager loading
        $this->assertArrayHasKey('detail', $productData, 'Pricing response should include detail');
        $this->assertArrayHasKey('variants', $productData, 'Pricing response should include variants');
        $this->assertArrayHasKey('featured_image_id', $productData, 'Pricing response should include featured_image_id');
    }

    // =========================================================================
    // Spec 143: DELETE /products/{product}
    // =========================================================================

    public function test_delete_product_removes_product_detail_and_variants(): void
    {
        $product = $this->productFactory->create();
        $productId = $product->ID;
        $detailId = $product->detail->id;
        $variantId = $product->variants->first()->id;

        $request = new \WP_REST_Request('DELETE', '/fluent-cart/v2/products/' . $productId);
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $this->assertNull(Product::query()->find($productId), 'Product should be deleted');
        $this->assertNull(ProductDetail::query()->find($detailId), 'Detail should be cascade-deleted');
        $this->assertNull(ProductVariation::query()->find($variantId), 'Variant should be cascade-deleted');
    }

    // =========================================================================
    // Spec 145: POST /products/{productId}/duplicate
    // =========================================================================

    public function test_duplicate_product_creates_copy_with_draft_status(): void
    {
        $product = $this->productFactory->create(['post_title' => 'To Duplicate']);

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/duplicate');
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());

        // Original should still exist
        $this->assertNotNull(Product::query()->find($product->ID), 'Original should be preserved');

        // Find the new product — it should have "(Copy)" in the title and draft status
        $copy = Product::query()
            ->where('ID', '!=', $product->ID)
            ->where('post_title', 'LIKE', '%To Duplicate%Copy%')
            ->first();
        $this->assertNotNull($copy, 'A new product with Copy in the title should exist');
        $this->assertSame('draft', $copy->post_status, 'Duplicated product should be draft');

        // Verify it has detail and at least one variant
        $detail = ProductDetail::query()->where('post_id', $copy->ID)->first();
        $this->assertNotNull($detail, 'Duplicated product should have a detail record');
        $variantCount = ProductVariation::query()->where('post_id', $copy->ID)->count();
        $this->assertGreaterThanOrEqual(1, $variantCount, 'Duplicated product should have variants');
    }

    // =========================================================================
    // Spec 146: POST /products/do-bulk-action (delete)
    // =========================================================================

    public function test_bulk_delete_removes_all_selected_products(): void
    {
        $p1 = $this->productFactory->create();
        $p2 = $this->productFactory->create();
        $p1Id = $p1->ID;
        $p2Id = $p2->ID;

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/do-bulk-action');
        $request->set_body_params([
            'action'      => 'delete_products',
            'product_ids' => [$p1Id, $p2Id],
        ]);

        $response = $this->dispatchRequest($request);
        $this->assertSame(200, $response->get_status());

        $this->assertNull(Product::query()->find($p1Id), 'Product p1 should be deleted');
        $this->assertNull(Product::query()->find($p2Id), 'Product p2 should be deleted');
    }

    // =========================================================================
    // Spec 152: POST /products/variants (create)
    // =========================================================================

    public function test_create_variant_persists_to_database(): void
    {
        $product = $this->productFactory->create();
        $initialCount = ProductVariation::query()->where('post_id', $product->ID)->count();

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/variants');
        $request->set_body_params([
            'variants' => [
                'post_id'          => $product->ID,
                'variation_title'  => 'REST Created Variant',
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
                'other_info'       => ['payment_type' => 'onetime'],
            ],
        ]);

        $response = $this->dispatchRequest($request);
        $this->assertSame(200, $response->get_status());

        $newCount = ProductVariation::query()->where('post_id', $product->ID)->count();
        $this->assertSame($initialCount + 1, $newCount, 'New variant should be persisted');

        // Verify the new variant has correct data
        $newVariant = ProductVariation::query()
            ->where('post_id', $product->ID)
            ->where('variation_title', 'REST Created Variant')
            ->first();
        $this->assertNotNull($newVariant);
        $this->assertEquals(1999, $newVariant->item_price, 'Submitted cents persist unchanged (#2615: the editor sends cents, the resource only normalizes)');
    }

    // =========================================================================
    // Spec 157: POST /products/variants/{variantId} (update)
    // =========================================================================

    public function test_update_variant_persists_changes(): void
    {
        $product = $this->productFactory->create();
        $variant = $product->variants->first();

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/variants/' . $variant->id);
        $request->set_body_params([
            'variants' => [
                'id'               => $variant->id,
                'post_id'          => $product->ID,
                'variation_title'  => 'Updated Title',
                'fulfillment_type' => 'physical',
                'item_price'       => 3999,
                'compare_price'    => 0,
                'item_cost'        => 0,
                'manage_stock'     => 0,
                'stock_status'     => 'in-stock',
                'total_stock'      => 0,
                'available'        => 0,
                'committed'        => 0,
                'on_hold'          => 0,
                'other_info'       => ['payment_type' => 'onetime'],
            ],
        ]);

        $response = $this->dispatchRequest($request);
        $this->assertSame(200, $response->get_status());

        $refreshed = ProductVariation::query()->find($variant->id);
        $this->assertSame('Updated Title', $refreshed->variation_title);
        $this->assertEquals(3999, $refreshed->item_price, 'Submitted cents persist unchanged (#2615: the editor sends cents, the resource only normalizes)');
        $this->assertSame('physical', $refreshed->fulfillment_type);
    }

    // =========================================================================
    // Spec 158: DELETE /products/variants/{variantId}
    // =========================================================================

    public function test_delete_variant_removes_from_database(): void
    {
        $product = $this->productFactory->createWithVariants([
            ['variation_title' => 'Keep',   'item_price' => 1000],
            ['variation_title' => 'Delete', 'item_price' => 2000],
        ]);
        $deleteVariant = $product->variants->where('variation_title', 'Delete')->first();
        $deleteId = $deleteVariant->id;
        $keepVariant = $product->variants->where('variation_title', 'Keep')->first();

        $request = new \WP_REST_Request('DELETE', '/fluent-cart/v2/products/variants/' . $deleteId);
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $this->assertNull(ProductVariation::query()->find($deleteId), 'Deleted variant should be gone');
        $this->assertNotNull(ProductVariation::query()->find($keepVariant->id), 'Kept variant should remain');
    }

    // =========================================================================
    // Spec 162: PUT /products/{postId}/update-manage-stock
    // =========================================================================

    public function test_toggle_manage_stock_persists_to_detail(): void
    {
        $product = $this->productFactory
            ->withDetailAttributes(['manage_stock' => 0])
            ->create();
        // Since 38700d9ec (#2643) the route refuses with 422 while the Stock
        // Management module is off, and the Integration suite boots with it off.
        update_option(ModuleSettings::MODULE_SETTINGS_OPTION, ['stock_management' => ['active' => 'yes']]);
        ModuleSettings::getAllSettings(false);

        $request = new \WP_REST_Request('PUT', '/fluent-cart/v2/products/' . $product->ID . '/update-manage-stock');
        $request->set_body_params(['manage_stock' => 1]);
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));

        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        $this->assertSame(1, (int)$detail->manage_stock, 'manage_stock should be toggled to 1');
    }

    // =========================================================================
    // Spec 170: GET /products/fetch-term
    // =========================================================================

    public function test_get_product_terms_list_returns_taxonomies_with_name_label_terms(): void
    {
        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/products/fetch-term');
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertArrayHasKey('taxonomies', $data, 'Response should contain taxonomies key');

        // Each taxonomy entry should have name, label, and terms
        $taxonomies = $data['taxonomies'];
        $this->assertNotEmpty($taxonomies, 'Should return at least one taxonomy');
        $first = is_array($taxonomies) ? reset($taxonomies) : $taxonomies->first();
        $first = is_array($first) ? $first : $first->toArray();
        $this->assertArrayHasKey('name', $first, 'Taxonomy entry should have name');
        $this->assertArrayHasKey('label', $first, 'Taxonomy entry should have label');
        $this->assertArrayHasKey('terms', $first, 'Taxonomy entry should have terms');
    }

    // =========================================================================
    // Spec 171: GET /products/searchProductByName
    // =========================================================================

    public function test_search_product_by_name_returns_matching_and_excludes_others(): void
    {
        $target = $this->productFactory->create(['post_title' => 'SearchTargetUniqueXYZ']);
        $other = $this->productFactory->create(['post_title' => 'IrrelevantProduct']);

        // Controller reads 'name', not 'search'
        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/products/searchProductByName');
        $request->set_param('name', 'SearchTargetUniqueXYZ');
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $products = $this->extractProducts($data);
        $titles = array_column($products, 'post_title');

        $this->assertContains('SearchTargetUniqueXYZ', $titles, 'Matching product should be returned');
        $this->assertNotContains('IrrelevantProduct', $titles, 'Non-matching product should be excluded');
    }

    // =========================================================================
    // Spec 174: GET /products/suggest-sku
    // =========================================================================

    public function test_suggest_sku_derives_from_title_words(): void
    {
        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/products/suggest-sku');
        $request->set_param('title', 'My Awesome Product');
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertArrayHasKey('sku', $data);
        // generateSkuFromTitle takes first 3 chars of non-stop-words, uppercased
        // "My" is a stop word, "Awesome" → "AWE", "Product" → "PRO"
        $this->assertStringContainsString('AWE', $data['sku'], 'SKU should contain prefix from Awesome');
        $this->assertStringContainsString('PRO', $data['sku'], 'SKU should contain prefix from Product');
    }

    public function test_suggest_sku_appends_suffix_on_collision(): void
    {
        // Create a product with a known SKU to force collision
        $product = $this->productFactory->create(['post_title' => 'Widget Blue']);
        $variant = $product->variants->first();

        // Generate the expected SKU for "Widget Blue"
        $request1 = new \WP_REST_Request('GET', '/fluent-cart/v2/products/suggest-sku');
        $request1->set_param('title', 'Widget Blue');
        $response1 = $this->dispatchRequest($request1);
        $firstSku = $response1->get_data()['sku'];

        // Set the variant's SKU to the generated value to create a collision
        ProductVariation::query()->where('id', $variant->id)->update(['sku' => $firstSku]);

        // Request the same title again — should get a suffixed SKU
        $request2 = new \WP_REST_Request('GET', '/fluent-cart/v2/products/suggest-sku');
        $request2->set_param('title', 'Widget Blue');
        $response2 = $this->dispatchRequest($request2);
        $secondSku = $response2->get_data()['sku'];

        $this->assertNotSame($firstSku, $secondSku, 'Colliding SKU should get a numeric suffix');
        $this->assertMatchesRegularExpression('/-\d+$/', $secondSku, 'Suffixed SKU should end with -N');
    }

    // =========================================================================
    // Spec 175: GET /products/fetchProductsByIds
    // =========================================================================

    public function test_fetch_products_by_ids_returns_only_requested(): void
    {
        $p1 = $this->productFactory->create(['post_title' => 'FetchById1']);
        $p2 = $this->productFactory->create(['post_title' => 'FetchById2']);
        $excluded = $this->productFactory->create(['post_title' => 'NotRequested']);

        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/products/fetchProductsByIds');
        $request->set_param('productIds', [$p1->ID, $p2->ID]);
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $products = $this->extractProducts($data);
        $returnedIds = array_column($products, 'ID');

        $this->assertContains($p1->ID, $returnedIds, 'Requested p1 should be returned');
        $this->assertContains($p2->ID, $returnedIds, 'Requested p2 should be returned');
        $this->assertNotContains($excluded->ID, $returnedIds, 'Non-requested product should be excluded');
    }

    // =========================================================================
    // Spec 189: GET /products/{productId}/pricing-widgets
    // =========================================================================

    public function test_get_pricing_widgets_returns_widgets_with_title_and_body(): void
    {
        $product = $this->productFactory->create();

        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/products/' . $product->ID . '/pricing-widgets');
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertArrayHasKey('widgets', $data);
        $this->assertIsArray($data['widgets']);
        $this->assertNotEmpty($data['widgets'], 'Should return at least one widget');

        $firstWidget = $data['widgets'][0];
        $this->assertArrayHasKey('title', $firstWidget, 'Widget should have a title');
        $this->assertArrayHasKey('body', $firstWidget, 'Widget should have a body');
        $this->assertNotEmpty($firstWidget['title']);
        $this->assertNotEmpty($firstWidget['body']);
    }

    // =========================================================================
    // Spec 190: POST /products/{postId}/update-long-desc-editor-mode
    // =========================================================================

    public function test_update_long_desc_editor_mode_persists_to_detail(): void
    {
        $product = $this->productFactory->create();

        // Controller reads 'active_editor', not 'editor_mode'
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/update-long-desc-editor-mode');
        $request->set_body_params(['active_editor' => 'wp-editor']);
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());

        // Verify persistence
        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        $this->assertSame('wp-editor', $detail->other_info['active_editor'] ?? null,
            'active_editor should be stored in detail.other_info');
    }
}
