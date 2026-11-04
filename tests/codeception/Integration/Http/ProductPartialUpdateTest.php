<?php

namespace Tests\Integration\Http;

use FluentCart\App\Models\Product;
use Tests\Support\RestTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * Integration tests for the partial product update path added in
 * feat/product-partial-post-field-update.
 *
 * A request routes to ProductResource::partialUpdate() when the body contains
 * neither a 'detail' array nor a 'variants' array. All other requests continue
 * to the existing full-update path unchanged.
 *
 * Covers specs 243–281 from tests/codeception/Cases/Integration/products.md.
 */
class ProductPartialUpdateTest extends RestTestCase
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
    // Spec 243: non-existent post ID
    // =========================================================================

    public function test_partial_update_returns_error_for_non_existent_post_id(): void
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/999999999/pricing');
        $request->set_body_params(['post_title' => 'Ghost Product']);
        
        $response = $this->dispatchRequestSilently($request);

        $this->assertEquals(404, $response->get_status());

        $data = $response->get_data();
        $message = $data['message'] ?? ($data['errors']['message'] ?? '');
        $this->assertSame('Product not found', (string) $message);
    }

    // =========================================================================
    // Spec 244: wrong post_type
    // =========================================================================

    public function test_partial_update_returns_error_when_post_type_is_not_fluent_products(): void
    {
        $wrongId = wp_insert_post([
            'post_title'  => 'Regular Post',
            'post_status' => 'publish',
            'post_type'   => 'post',
        ]);

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $wrongId . '/pricing');
        $request->set_body_params(['post_title' => 'Sneaky Update']);

        $response = $this->dispatchRequestSilently($request);

        $this->assertEquals(404, $response->get_status());
        $data = $response->get_data();
        $message = $data['message'] ?? ($data['errors']['message'] ?? '');
        $this->assertSame('Product not found', (string) $message);
    }

    // =========================================================================
    // Spec 245: no allowed fields in body
    // =========================================================================

    public function test_partial_update_returns_error_when_no_allowed_fields_provided(): void
    {
        $product = $this->productFactory->create();

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $request->set_body_params(['unknown_key' => 'value']);

        $response = $this->dispatchRequestSilently($request);

        $this->assertEquals(422, $response->get_status());
        $data = $response->get_data();
        $message = $data['message'] ?? ($data['errors']['message'] ?? '');
        $this->assertSame('No valid fields provided for update', (string) $message);
    }

    // =========================================================================
    // Spec 246: updates post_title only — other fields unchanged
    // =========================================================================

    public function test_partial_update_changes_title_without_touching_other_fields(): void
    {
        $product = $this->productFactory->create([
            'post_title'   => 'Original Title',
            'post_excerpt' => 'Original Excerpt',
            'post_status'  => 'publish',
        ]);
        $originalExcerpt = get_post($product->ID)->post_excerpt;
        $originalStatus  = get_post($product->ID)->post_status;

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $request->set_body_params(['post_title' => 'Updated Title Only']);

        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $post = get_post($product->ID);
        $this->assertSame('Updated Title Only', $post->post_title);
        $this->assertSame($originalExcerpt, $post->post_excerpt, 'post_excerpt must not change');
        $this->assertSame($originalStatus,  $post->post_status,  'post_status must not change');
    }

    // =========================================================================
    // Spec 247: post_status → draft
    // =========================================================================

    public function test_partial_update_changes_status_to_draft(): void
    {
        $product = $this->productFactory->create(['post_status' => 'publish']);

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $request->set_body_params(['post_status' => 'draft']);

        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $this->assertSame('draft', get_post($product->ID)->post_status);
    }

    // =========================================================================
    // Spec 248: post_status → private
    // =========================================================================

    public function test_partial_update_changes_status_to_private(): void
    {
        $product = $this->productFactory->create(['post_status' => 'publish']);

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $request->set_body_params(['post_status' => 'private']);

        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $this->assertSame('private', get_post($product->ID)->post_status);
    }

    // =========================================================================
    // Spec 249: post_excerpt updated independently
    // =========================================================================

    public function test_partial_update_changes_excerpt_without_touching_title(): void
    {
        $product = $this->productFactory->create(['post_title' => 'Keep This Title']);

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $request->set_body_params(['post_excerpt' => 'New short description']);

        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $post = get_post($product->ID);
        $this->assertSame('New short description', $post->post_excerpt);
        $this->assertSame('Keep This Title', $post->post_title, 'post_title must be unchanged');
    }

    // =========================================================================
    // Spec 250: partial update only writes the fields present in the request —
    // sending post_title alone must leave post_content untouched, and sending
    // post_content explicitly must update it (it is an allowed field).
    // =========================================================================

    public function test_partial_update_leaves_unsent_post_content_untouched(): void
    {
        $product = $this->productFactory->create(['post_title' => 'Content Test']);
        wp_update_post(['ID' => $product->ID, 'post_content' => 'Original body']);
        $originalContent = get_post($product->ID)->post_content;

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $request->set_body_params(['post_title' => 'Content Test Updated']);

        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $post = get_post($product->ID);
        $this->assertSame('Content Test Updated', $post->post_title);
        $this->assertSame(
            $originalContent,
            $post->post_content,
            'post_content was not sent and must remain unchanged'
        );
    }

    // =========================================================================
    // Spec 250 (continued): sending post_content explicitly must update it
    // =========================================================================
    public function test_partial_update_writes_post_content_when_sent(): void
    {
        $product = $this->productFactory->create(['post_title' => 'Content Test']);

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $request->set_body_params(['post_content' => 'Fresh body copy']);

        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $this->assertSame('Fresh body copy', get_post($product->ID)->post_content);
    }

    // =========================================================================
    // Spec 251-252: scheduling — future status stores GMT date in wp_posts + ORM
    // =========================================================================

    public function test_partial_update_schedules_product_and_stores_gmt_date(): void
    {
        $product = $this->productFactory->create(['post_status' => 'publish']);

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $request->set_body_params([
            'post_status' => 'future',
            'post_date'   => '2099-01-01 10:00:00',
        ]);

        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());

        $post = get_post($product->ID);
        $this->assertSame('future', $post->post_status);
        $this->assertNotEmpty($post->post_date_gmt);
        $this->assertNotSame('0000-00-00 00:00:00', $post->post_date_gmt);

        // ORM row must also reflect the scheduled date (syncOrmDates=true path)
        $orm = Product::query()->find($product->ID);
        $this->assertNotSame('0000-00-00 00:00:00', $orm->post_date_gmt);
    }

    // =========================================================================
    // Spec 253: future → publish resets post_date to now
    // =========================================================================

    public function test_partial_update_publish_from_future_resets_post_date_to_now(): void
    {
        $product = $this->productFactory->create(['post_status' => 'publish']);

        // Schedule it first
        $scheduleReq = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $scheduleReq->set_body_params(['post_status' => 'future', 'post_date' => '2099-06-15 12:00:00']);
        $this->dispatchRequest($scheduleReq);
        $this->assertSame('future', get_post($product->ID)->post_status);

        // Now publish
        $publishReq = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $publishReq->set_body_params(['post_status' => 'publish']);

        $response = $this->dispatchRequest($publishReq);

        $this->assertSame(200, $response->get_status());

        $post = get_post($product->ID);
        $this->assertSame('publish', $post->post_status);
        $this->assertNotSame(
            '2099-06-15 12:00:00',
            $post->post_date,
            'post_date must be reset to now, not kept as the scheduled future date'
        );

        $orm = Product::query()->find($product->ID);
        $this->assertSame('publish', $orm->post_status);
    }

    // =========================================================================
    // Spec 254: simple status change — post_date unchanged, ORM reads correctly
    // =========================================================================

    public function test_partial_update_draft_does_not_change_post_date(): void
    {
        $product      = $this->productFactory->create(['post_status' => 'publish']);
        $originalDate = get_post($product->ID)->post_date;

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $request->set_body_params(['post_status' => 'draft']);

        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $orm = Product::query()->find($product->ID);
        $this->assertSame('draft', $orm->post_status);
        $this->assertSame($originalDate, $orm->post_date, 'post_date must not change on simple status transition');
    }

    // =========================================================================
    // Spec 255: response contains full ORM product (variants + viewUrl)
    // =========================================================================

    public function test_partial_update_response_contains_product_with_variants_and_view_url(): void
    {
        $product = $this->productFactory->create(['post_title' => 'Shape Test']);

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $request->set_body_params(['post_title' => 'Shape Test Updated']);

        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('data', $data);

        $productData = $data['data'];
        $this->assertArrayHasKey('ID',       $productData);
        $this->assertArrayHasKey('variants', $productData);
        $this->assertArrayHasKey('viewUrl',  $productData);
    }

    // =========================================================================
    // Spec 257: partial path activates when neither detail nor variants present
    // =========================================================================

    public function test_partial_path_activates_when_detail_and_variants_absent(): void
    {
        $product = $this->productFactory->create(['post_title' => 'Before']);

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $request->set_body_params(['post_title' => 'After Partial']);

        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $this->assertSame('After Partial', get_post($product->ID)->post_title);
    }

    // =========================================================================
    // Spec 258: partial path activates when detail is not an array (string)
    // =========================================================================

    public function test_partial_path_activates_when_detail_is_not_an_array(): void
    {
        $product = $this->productFactory->create();

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $request->set_body_params([
            'post_title' => 'Non-array Detail',
            'detail'     => 'string-not-array',
        ]);

        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $this->assertSame('Non-array Detail', get_post($product->ID)->post_title);
    }

    // =========================================================================
    // Spec 259: full update path activates when detail IS an array
    // =========================================================================

    public function test_full_update_path_activates_when_detail_is_an_array(): void
    {
        $product = $this->productFactory->create();
        $variant = $product->variants->first();

        // Omit post_title — full path requires it, so this must fail validation
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $request->set_body_params([
            'detail' => [
                'id'               => $product->detail->id,
                'post_id'          => $product->ID,
                'fulfillment_type' => 'digital',
                'variation_type'   => 'simple',
            ],
            'variants' => [[
                'id'               => $variant->id,
                'post_id'          => $product->ID,
                'variation_title'  => 'Default',
                'fulfillment_type' => 'digital',
                'item_price'       => 2999,
                'other_info'       => ['payment_type' => 'onetime'],
            ]],
        ]);

        $response = $this->dispatchRequestSilently($request);

        $this->assertGreaterThanOrEqual(
            422,
            $response->get_status(),
            'Full update path requires post_title — omitting it must return a validation error'
        );
    }

    // =========================================================================
    // Spec 260: post_title max 200 chars on partial path
    // =========================================================================

    public function test_partial_update_rejects_post_title_exceeding_200_chars(): void
    {
        $product = $this->productFactory->create();

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $request->set_body_params(['post_title' => str_repeat('A', 201)]);

        $response = $this->dispatchRequestSilently($request);

        $this->assertGreaterThanOrEqual(422, $response->get_status());
    }

    // =========================================================================
    // Spec 261-262: post_status whitelist — pending and trash rejected
    // =========================================================================

    public function test_partial_update_rejects_pending_status(): void
    {
        $product = $this->productFactory->create();

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $request->set_body_params(['post_status' => 'pending']);

        $response = $this->dispatchRequestSilently($request);

        $this->assertGreaterThanOrEqual(400, $response->get_status());
    }

    public function test_partial_update_rejects_trash_status(): void
    {
        $product = $this->productFactory->create();

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $request->set_body_params(['post_status' => 'trash']);

        $response = $this->dispatchRequestSilently($request);

        $this->assertGreaterThanOrEqual(400, $response->get_status());
    }

    // =========================================================================
    // Spec 263: post_date not validated when status is not future
    // =========================================================================

    public function test_partial_update_does_not_validate_post_date_when_status_is_not_future(): void
    {
        $product = $this->productFactory->create();

        // Past date sent with draft status — must NOT trigger date validation
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $request->set_body_params([
            'post_status' => 'draft',
            'post_date'   => '2020-01-01 00:00:00',
        ]);

        $response = $this->dispatchRequest($request);

        $this->assertSame(
            200,
            $response->get_status(),
            'post_date is only validated when post_status=future; a past date with draft must pass'
        );
    }

    // =========================================================================
    // Spec 264: future without post_date → required error
    // =========================================================================

    public function test_partial_update_future_without_post_date_returns_required_error(): void
    {
        $product = $this->productFactory->create();

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $request->set_body_params(['post_status' => 'future']);

        $response = $this->dispatchRequestSilently($request);

        $this->assertGreaterThanOrEqual(400, $response->get_status());
        $data   = $response->get_data();
        $errors = json_encode($data);
        $this->assertStringContainsStringIgnoringCase('required', $errors);
    }

    // =========================================================================
    // Spec 265: future with past post_date → must-be-future error
    // =========================================================================

    public function test_partial_update_future_with_past_date_returns_error(): void
    {
        $product = $this->productFactory->create();

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $request->set_body_params([
            'post_status' => 'future',
            'post_date'   => '2020-01-01 10:00:00',
        ]);

        $response = $this->dispatchRequestSilently($request);

        $this->assertGreaterThanOrEqual(400, $response->get_status());
        $data   = $response->get_data();
        $errors = json_encode($data);
        $this->assertStringContainsStringIgnoringCase('future', $errors);
    }

    // =========================================================================
    // Spec 266: future with malformed date → invalid error
    // =========================================================================

    public function test_partial_update_future_with_malformed_date_returns_error(): void
    {
        $product = $this->productFactory->create();

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $request->set_body_params([
            'post_status' => 'future',
            'post_date'   => 'not-a-date',
        ]);

        $response = $this->dispatchRequestSilently($request);

        $this->assertGreaterThanOrEqual(400, $response->get_status());
    }

    // =========================================================================
    // Spec 267: future with valid future date passes
    // =========================================================================

    public function test_partial_update_future_with_valid_future_date_passes(): void
    {
        $product = $this->productFactory->create();

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $request->set_body_params([
            'post_status' => 'future',
            'post_date'   => '2099-12-31 23:59:59',
        ]);

        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $this->assertSame('future', get_post($product->ID)->post_status);
    }

    // =========================================================================
    // Spec 268: empty body → error
    // =========================================================================

    public function test_partial_update_with_empty_body_returns_error(): void
    {
        $product = $this->productFactory->create();

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $request->set_body_params([]);

        $response = $this->dispatchRequestSilently($request);
       

        $this->assertGreaterThanOrEqual(400, $response->get_status());
        $data   = $response->get_data();
        $errors = json_encode($data);
        $this->assertStringContainsStringIgnoringCase('No valid fields', $errors);
    }

    // =========================================================================
    // Spec 274: multiple partial fields in one request — all updated
    // =========================================================================

    public function test_multiple_partial_fields_all_updated_in_one_request(): void
    {
        $product = $this->productFactory->create([
            'post_title'  => 'Old Title',
            'post_status' => 'publish',
        ]);

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $request->set_body_params([
            'post_title'   => 'New Title',
            'post_excerpt' => 'New excerpt',
            'post_status'  => 'draft',
        ]);

        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $post = get_post($product->ID);
        $this->assertSame('New Title',    $post->post_title);
        $this->assertSame('New excerpt',  $post->post_excerpt);
        $this->assertSame('draft',        $post->post_status);
    }

    // =========================================================================
    // Spec 276: full payload routes to full update path
    // =========================================================================

    public function test_full_payload_routes_to_full_update_path_and_saves_title(): void
    {
        $product = $this->productFactory->create(['post_title' => 'Before Full']);
        $variant = $product->variants->first();

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $request->set_body_params([
            'post_title'  => 'After Full Update',
            'post_status' => 'publish',
            'detail' => [
                'id'               => $product->detail->id,
                'post_id'          => $product->ID,
                'fulfillment_type' => 'digital',
                'variation_type'   => 'simple',
            ],
            'variants' => [[
                'id'               => $variant->id,
                'post_id'          => $product->ID,
                'variation_title'  => 'Default',
                'fulfillment_type' => 'digital',
                'item_price'       => 2999,
                'other_info'       => ['payment_type' => 'onetime'],
            ]],
        ]);

        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $this->assertSame('After Full Update', get_post($product->ID)->post_title);
    }

    // =========================================================================
    // Spec 277: fluent_cart/product_updated fires on partial update
    // =========================================================================

    public function test_partial_update_fires_product_updated_hook(): void
    {
        $product = $this->productFactory->create();

        $receivedPayload = null;
        add_action('fluent_cart/product_updated', function ($payload) use (&$receivedPayload) {
            $receivedPayload = $payload;
        });

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $request->set_body_params(['post_title' => 'Hook Fired']);

        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status());
        $this->assertNotNull($receivedPayload, 'fluent_cart/product_updated must fire on partial update');
        $this->assertArrayHasKey('data',    $receivedPayload, 'Hook payload must have data key');
        $this->assertArrayHasKey('product', $receivedPayload, 'Hook payload must have product key');
    }

    // =========================================================================
    // Spec 278: hook product key is ORM model with variants + viewUrl (same shape as full path)
    // =========================================================================

    public function test_partial_and_full_update_fire_hook_with_identical_product_shape(): void
    {
        $product = $this->productFactory->create();
        $variant = $product->variants->first();

        $products = [];
        add_action('fluent_cart/product_updated', function ($payload) use (&$products) {
            $products[] = $payload['product'];
        });

        // Full update
        $fullReq = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $fullReq->set_body_params([
            'post_title'  => 'Full Path',
            'post_status' => 'publish',
            'detail' => [
                'id'               => $product->detail->id,
                'post_id'          => $product->ID,
                'fulfillment_type' => 'digital',
                'variation_type'   => 'simple',
            ],
            'variants' => [[
                'id'               => $variant->id,
                'post_id'          => $product->ID,
                'variation_title'  => 'Default',
                'fulfillment_type' => 'digital',
                'item_price'       => 2999,
                'other_info'       => ['payment_type' => 'onetime'],
            ]],
        ]);
        $this->dispatchRequest($fullReq);

        // Partial update
        $partialReq = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $partialReq->set_body_params(['post_title' => 'Partial Path']);
        $this->dispatchRequest($partialReq);

        $this->assertCount(2, $products, 'Hook must fire twice — once per update');

        foreach ($products as $i => $hookProduct) {
            $path = $i === 0 ? 'full' : 'partial';

            // The TYPE is the contract, not just the keys. Both paths hand
            // listeners the ORM model (ProductResource::update() and
            // ::partialUpdate() each return the addAppends(['viewUrl']) find()),
            // so an extension can call model methods and walk relations on it.
            // Accepting an array here would let either dispatch be changed to
            // toArray() with every assertion still green.
            $this->assertInstanceOf(Product::class, $hookProduct,
                "$path path hook must dispatch the ORM product model, not an array");

            $arr = $hookProduct->toArray();
            $this->assertArrayHasKey('ID',       $arr, "$path path hook product must have ID");
            $this->assertArrayHasKey('variants', $arr, "$path path hook product must have variants");
            $this->assertArrayHasKey('viewUrl',  $arr, "$path path hook product must have viewUrl");
            $this->assertSame($product->ID, (int) $arr['ID']);
            $this->assertSame(
                [(int) $variant->id],
                array_map('intval', array_column($arr['variants'], 'id')),
                "$path path hook product must carry the product's own variant"
            );
            $this->assertNotEmpty($arr['viewUrl'], "$path path hook product must carry a resolved viewUrl");
        }
    }

    // =========================================================================
    // Spec 281: future→publish without gmtNow() reset would stay as future
    // =========================================================================

    public function test_publish_from_future_actually_sets_status_to_publish(): void
    {
        $product = $this->productFactory->create(['post_status' => 'publish']);

        // Schedule
        $scheduleReq = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $scheduleReq->set_body_params(['post_status' => 'future', 'post_date' => '2099-03-15 09:00:00']);
        $this->dispatchRequest($scheduleReq);
        $this->assertSame('future', get_post($product->ID)->post_status);

        // Publish
        $publishReq = new \WP_REST_Request('POST', '/fluent-cart/v2/products/' . $product->ID . '/pricing');
        $publishReq->set_body_params(['post_status' => 'publish']);

        $response = $this->dispatchRequest($publishReq);

        $this->assertSame(200, $response->get_status());
        $this->assertSame(
            'publish',
            get_post($product->ID)->post_status,
            'Without resetting post_date to gmtNow(), WordPress silently keeps status as future. '
            . 'partialUpdate() must reset post_date before calling wp_update_post.'
        );
    }
}
