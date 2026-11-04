<?php

namespace Tests\Integration\Http;

use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use Tests\Support\RestTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * Validation and edge case tests for product endpoints.
 *
 * Covers specs 206-242 from the products test case specification.
 */
class ProductValidationTest extends RestTestCase
{
    private ProductFactory $productFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->productFactory = new ProductFactory();
    }

    public function tearDown(): void
    {
        wp_cache_flush();
        gc_collect_cycles();
        parent::tearDown();
    }

    // =========================================================================
    // Spec 206-208: ProductCreateRequest validation
    // =========================================================================

    public function test_create_requires_post_title(): void
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products');
        $request->set_body_params([
            'detail' => ['fulfillment_type' => 'digital'],
        ]);

        $response = $this->dispatchRequestSilently($request);
        $this->assertGreaterThanOrEqual(400, $response->get_status());
    }

    public function test_create_rejects_invalid_fulfillment_type(): void
    {
        // The custom validator in rules() only accepts 'physical' or 'digital'.
        // Even though sanitize() defaults empty to 'digital', the rules() validator
        // fires on the raw input before sanitization applies, so empty is rejected.
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products');
        $request->set_body_params([
            'post_title' => 'Bad Fulfillment',
            'detail'     => ['fulfillment_type' => 'invalid_type'],
        ]);

        $response = $this->dispatchRequestSilently($request);
        $this->assertGreaterThanOrEqual(400, $response->get_status());
    }

    public function test_create_accepts_digital_fulfillment_type(): void
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products');
        $request->set_body_params([
            'post_title' => 'Digital Product',
            'detail'     => ['fulfillment_type' => 'digital'],
        ]);

        $response = $this->dispatchRequest($request);
        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $productId = $data['data']['ID'];
        $detail = ProductDetail::query()->where('post_id', $productId)->first();
        $this->assertSame('digital', $detail->fulfillment_type);
    }

    public function test_create_sanitizer_defaults_empty_fulfillment_to_digital(): void
    {
        // Spec 207: ProductCreateRequest::sanitize() has a function that defaults
        // empty fulfillment_type to 'digital'. We test the sanitizer directly since
        // the validation rule rejects empty before the REST layer can apply it.
        $request = new \FluentCart\App\Http\Requests\ProductCreateRequest(
            \FluentCart\App\App::getInstance(),
            ['post_title' => 'Test', 'detail' => ['fulfillment_type' => '']],
            []
        );
        $sanitizers = $request->sanitize();
        $fulfillmentSanitizer = $sanitizers['detail.fulfillment_type'];

        // The sanitizer function should return 'digital' for empty input
        $this->assertSame('digital', $fulfillmentSanitizer(''));
        // And pass through valid values
        $this->assertSame('physical', $fulfillmentSanitizer('physical'));
    }

    public function test_create_accepts_physical_fulfillment_type(): void
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products');
        $request->set_body_params([
            'post_title' => 'Physical Product',
            'detail'     => ['fulfillment_type' => 'physical'],
        ]);

        $response = $this->dispatchRequest($request);
        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $productId = $data['data']['ID'];
        $detail = ProductDetail::query()->where('post_id', $productId)->first();
        $this->assertSame('physical', $detail->fulfillment_type);
    }

    public function test_create_sanitizes_title_via_sanitize_text_field(): void
    {
        // Verify the sanitizer runs by testing the documented behavior of
        // sanitize_text_field: it strips octets, removes invalid UTF-8, and
        // converts single < to entities. We can't send raw <script> through the
        // REST layer (validation rejects it), but we can verify sanitize_text_field
        // is applied by testing its other behaviors directly.
        $sanitizer = (new \FluentCart\App\Http\Requests\ProductCreateRequest(
            \FluentCart\App\App::getInstance(), [], []
        ))->sanitize();

        // The sanitize map for post_title should be sanitize_text_field
        $this->assertSame('sanitize_text_field', $sanitizer['post_title'],
            'post_title should be sanitized via sanitize_text_field');

        // Also verify a real request stores the title correctly
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products');
        $request->set_body_params([
            'post_title' => 'Normal Product Title',
            'detail'     => ['fulfillment_type' => 'digital'],
        ]);

        $response = $this->dispatchRequest($request);
        $this->assertSame(200, $response->get_status());

        $product = get_post($response->get_data()['data']['ID']);
        $this->assertSame('Normal Product Title', $product->post_title);
    }

    // =========================================================================
    // Spec 230: auto-draft excluded from queries
    // =========================================================================

    public function test_auto_draft_excluded_from_product_queries(): void
    {
        wp_insert_post([
            'post_title'  => 'Auto Draft Test',
            'post_status' => 'auto-draft',
            'post_type'   => 'fluent-products',
        ]);

        $all = Product::query()->get();
        foreach ($all as $p) {
            $this->assertNotSame('auto-draft', $p->post_status);
        }
    }

    // =========================================================================
    // Spec 233: Subscription quantity > 1
    // =========================================================================

    public function test_subscription_variant_cannot_purchase_quantity_gt_1(): void
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

    // =========================================================================
    // Spec 237: Empty price normalized to 0
    // =========================================================================

    public function test_empty_price_normalized_to_zero_via_request(): void
    {
        // Spec 237: ProductVariationRequest::afterValidation() converts empty
        // item_price to 0. Test this through the REST endpoint.
        $product = $this->productFactory->create();

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/variants');
        $request->set_body_params([
            'variants' => [
                'post_id'          => $product->ID,
                'variation_title'  => 'Empty Price Variant',
                'fulfillment_type' => 'digital',
                'item_price'       => '',
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

        $created = ProductVariation::query()
            ->where('post_id', $product->ID)
            ->where('variation_title', 'Empty Price Variant')
            ->first();
        $this->assertNotNull($created, 'Variant should be created');
        $this->assertEquals(0, $created->item_price, 'Empty price should be normalized to 0');
    }

    // =========================================================================
    // Spec 238: Changing variation_type to simple deletes orphans
    // =========================================================================

    public function test_changing_to_simple_deletes_orphan_variants(): void
    {
        $product = $this->productFactory->createWithVariants([
            ['variation_title' => 'V1', 'item_price' => 1000, 'serial_index' => 0],
            ['variation_title' => 'V2', 'item_price' => 2000, 'serial_index' => 1],
        ]);

        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        $detail->variation_type = 'simple_variations';
        $detail->save();

        $keepVariant = ProductVariation::query()
            ->where('post_id', $product->ID)
            ->orderBy('serial_index', 'asc')
            ->first();

        \FluentCart\Api\Resource\ProductDetailResource::update(
            [
                'id'             => $detail->id,
                'post_id'        => $product->ID,
                'variation_type' => 'simple',
                'variation_ids'  => [$keepVariant->id],
            ],
            $detail->id,
            ['action' => 'change_variation_type']
        );

        $count = ProductVariation::query()->where('post_id', $product->ID)->count();
        $this->assertSame(1, $count);
    }

    // =========================================================================
    // Spec 239: Product update fires action
    // =========================================================================

    public function test_product_update_fires_action_with_data_and_product(): void
    {
        $product = $this->productFactory->create(['post_title' => 'Before Update']);
        $variant = $product->variants->first();

        $receivedPayload = null;
        add_action('fluent_cart/product_updated', function ($payload) use (&$receivedPayload) {
            $receivedPayload = $payload;
        });

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $request->set_body_params([
            'post_title'  => 'After Update',
            'post_status' => 'publish',
            'detail' => [
                'id'               => $product->detail->id,
                'post_id'          => $product->ID,
                'fulfillment_type' => 'digital',
                'variation_type'   => 'simple',
            ],
            'variants' => [
                [
                    'id'         => $variant->id,
                    'post_id'    => $product->ID,
                    'variation_title' => 'Default',
                    'fulfillment_type' => 'digital',
                    'item_price' => 2999,
                    'other_info' => ['payment_type' => 'onetime'],
                ],
            ],
        ]);

        $response = $this->dispatchRequest($request);
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));

        // Verify the hook fired with the documented payload shape
        $this->assertNotNull($receivedPayload, 'fluent_cart/product_updated should fire');
        $this->assertArrayHasKey('data', $receivedPayload, 'Payload should contain data key');
        $this->assertArrayHasKey('product', $receivedPayload, 'Payload should contain product key');
    }

    // =========================================================================
    // Spec 240: Other_info preserves bundle data across updates
    // =========================================================================

    public function test_other_info_preserves_bundle_data_across_updates(): void
    {
        $child = $this->productFactory->create();
        $childVariantId = $child->variants->first()->id;

        $bundle = $this->productFactory->createBundle([$childVariantId]);
        $bundleVariant = $bundle->variants->first();

        // Update the variant via resource — id must be in the data array
        \FluentCart\Api\Resource\ProductVariationResource::update(
            [
                'id'               => $bundleVariant->id,
                'variation_title'  => 'Updated Bundle',
                'fulfillment_type' => 'digital',
                'item_price'       => 39.99,
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
            $bundleVariant->id,
            ['post_id' => $bundle->ID]
        );

        $refreshed = ProductVariation::query()->find($bundleVariant->id);
        $info = $refreshed->other_info;
        $this->assertSame('yes', $info['is_bundle_product'] ?? null);
        $this->assertContains($childVariantId, $info['bundle_child_ids'] ?? []);
    }

    // =========================================================================
    // Spec 241: min_price/max_price auto-calculated from variants
    // =========================================================================

    public function test_detail_prices_auto_calculated_from_variants(): void
    {
        $product = $this->productFactory->createWithVariants([
            ['variation_title' => 'Cheap',     'item_price' => 1000],
            ['variation_title' => 'Mid',       'item_price' => 3000],
            ['variation_title' => 'Expensive', 'item_price' => 5000],
        ]);

        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        // min_price and max_price have custom accessors querying variants
        $this->assertEquals(1000, $detail->min_price);
        $this->assertEquals(5000, $detail->max_price);
    }

    // =========================================================================
    // Spec 242: Subscription signup_fee stored as the submitted cents
    // =========================================================================

    public function test_subscription_signup_fee_stored_as_submitted_cents(): void
    {
        $product = $this->productFactory->create();
        $detail = $product->detail;

        \FluentCart\Api\Resource\ProductDetailResource::update(
            [
                'id'         => $detail->id,
                'other_info' => [
                    'payment_type'     => 'subscription',
                    'manage_setup_fee' => 'yes',
                    'signup_fee'       => 999,
                ],
            ],
            $detail->id,
            ['action' => 'variant_modified']
        );

        $refreshed = ProductDetail::query()->find($detail->id);
        $info = $refreshed->other_info;
        $this->assertSame(999, $info['signup_fee'], 'Submitted cents persist unchanged (#2615: the editor sends cents, the resource only normalizes)');
    }
}
