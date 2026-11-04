<?php

namespace Tests\Integration\Http;

use FluentCart\Api\Resource\ProductReviewResource;
use FluentCart\App\App;
use FluentCart\App\Services\ProductReviewService;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\RestTestCase;

/**
 * Single-store-reply rule: a review carries at most one store reply, and
 * reviewer (customer) replies are disabled. Threaded replies are a Pro
 * capability re-enabled via the fluent_cart/review/allow_threaded_replies
 * filter (only honored while Pro is active).
 */
class ReviewSingleReplyTest extends RestTestCase
{
    protected bool $rebuildRestServerPerDispatch = true;

    private ProductFactory $productFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->productFactory = new ProductFactory();
    }

    public function tearDown(): void
    {
        remove_all_filters('fluent_cart/review/allow_threaded_replies');
        remove_all_filters('fluent_cart/review/allow_multiple_replies');
        wp_cache_flush();
        parent::tearDown();
    }

    private function createReview(int $postId)
    {
        $review = ProductReviewResource::create([
            'parent_id'      => 0,
            'post_id'        => $postId,
            'rating'         => 5,
            'title'          => 'Single reply spec review',
            'content'        => 'Review content for the single-reply spec.',
            'status'         => 'approved',
            'is_verified'    => 0,
            'is_admin_reply' => 0,
            'user_id'        => self::$persistentAdminId,
            'reviewer_name'  => 'Spec Reviewer',
            'reviewer_email' => 'spec-reviewer@example.com',
        ]);

        $this->assertFalse(is_wp_error($review), 'fixture review should be created');

        return $review;
    }

    private function dispatchAdminReply(int $reviewId, string $content): \WP_REST_Response
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/reviews/' . $reviewId . '/reply');
        $request->set_body_params(['content' => $content]);

        return $this->dispatchRequest($request);
    }

    public function test_first_store_reply_succeeds_and_second_is_rejected(): void
    {
        $product = $this->productFactory->create();
        $review = $this->createReview($product->ID);

        $first = $this->dispatchAdminReply($review->id, 'First store reply');
        $this->assertSame(200, $first->get_status(), 'first store reply should be accepted');

        $second = $this->dispatchAdminReply($review->id, 'Second store reply');
        $this->assertSame(422, $second->get_status(), 'second store reply must be rejected');

        $replyCount = ProductReviewResource::getQuery()
            ->where('parent_id', $review->id)
            ->count();
        $this->assertSame(1, $replyCount, 'exactly one reply row must exist');
    }

    public function test_bulk_reply_skips_reviews_that_already_have_a_reply(): void
    {
        $product = $this->productFactory->create();
        $replied = $this->createReview($product->ID);
        $fresh = $this->createReview($product->ID);

        $seed = $this->dispatchAdminReply($replied->id, 'Existing store reply');
        $this->assertSame(200, $seed->get_status());

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/reviews/bulk-reply');
        $request->set_body_params([
            'content'    => 'Bulk store reply',
            'review_ids' => [$replied->id, $fresh->id],
        ]);
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status(), 'bulk should succeed for the fresh review');
        // bulkReply() wraps its counts in makeSuccessResponse(), so they sit under data.
        $data = $response->get_data();
        $this->assertSame(1, (int) ($data['data']['replied'] ?? -1), 'only the fresh review gets a reply');
        $this->assertSame(1, (int) ($data['data']['skipped'] ?? -1), 'the already-replied review is skipped');

        $repliedCount = ProductReviewResource::getQuery()->where('parent_id', $replied->id)->count();
        $freshCount = ProductReviewResource::getQuery()->where('parent_id', $fresh->id)->count();
        $this->assertSame(1, $repliedCount, 'replied review must stay at one reply');
        $this->assertSame(1, $freshCount, 'fresh review received the bulk reply');
    }

    public function test_bulk_reply_errors_when_every_review_already_has_a_reply(): void
    {
        $product = $this->productFactory->create();
        $review = $this->createReview($product->ID);
        $this->dispatchAdminReply($review->id, 'Existing store reply');

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/reviews/bulk-reply');
        $request->set_body_params([
            'content'    => 'Bulk store reply',
            'review_ids' => [$review->id],
        ]);
        $response = $this->dispatchRequestSilently($request);

        $this->assertGreaterThanOrEqual(400, $response->get_status(), 'all-replied batch must not succeed');
        $count = ProductReviewResource::getQuery()->where('parent_id', $review->id)->count();
        $this->assertSame(1, $count, 'no additional reply may be created');
    }

    public function test_customer_reply_endpoint_does_not_exist_in_free(): void
    {
        $product = $this->productFactory->create();
        $review = $this->createReview($product->ID);

        // Customer replies moved to Pro entirely — free registers no such
        // route, so the request must fall through to rest_no_route.
        $request = new \WP_REST_Request(
            'POST',
            '/fluent-cart/v2/public/reviews/' . $product->ID . '/' . $review->id . '/reply'
        );
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $request->set_body_params(['content' => 'Customer reply attempt']);
        $response = $this->dispatchRequestSilently($request);

        $this->assertSame(404, $response->get_status(), 'free registers no customer-reply route');
        $count = ProductReviewResource::getQuery()->where('parent_id', $review->id)->count();
        $this->assertSame(0, $count, 'no reply row may be created');
    }

}
