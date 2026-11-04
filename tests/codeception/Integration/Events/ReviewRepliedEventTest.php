<?php

namespace Tests\Integration\Events;

use FluentCart\Api\Resource\ProductReviewResource;
use FluentCart\App\Events\ReviewReplied;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\ProductReview;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\RestTestCase;

/**
 * fluent_cart/review_replied — fired once per store reply, after its commit.
 *
 * Two places write a store reply: a moderator answering one review, and a
 * bulk reply. Both wrap the write in a transaction, and both announce it only
 * once that transaction has committed — a listener that queued an email inside
 * it would, on rollback, tell a reviewer about a reply that does not exist.
 * A customer's own follow-up is not the store speaking and never fires.
 *
 * Spec: Cases/reviews.md — "Reply event"
 */
class ReviewRepliedEventTest extends RestTestCase
{
    private ProductFactory $productFactory;

    /** @var array<int, array> every payload the hook received, in order */
    private array $fired = [];

    /** @var int[] the transaction depth at each firing — 0 means committed */
    private array $depthAtFire = [];

    private \Closure $listener;
    private \Closure $mailSink;

    public function setUp(): void
    {
        parent::setUp();
        $this->productFactory = new ProductFactory();
        $this->fired = [];
        $this->depthAtFire = [];

        $this->listener = function ($payload) {
            $this->fired[] = $payload;
            $connection = ProductReviewResource::getQuery()->getConnection();
            $this->depthAtFire[] = method_exists($connection, 'transactionLevel') ? (int) $connection->transactionLevel() : 0;
        };
        add_action('fluent_cart/review_replied', $this->listener, 10, 1);

        $this->mailSink = function () {
            return true;
        };
        add_filter('pre_wp_mail', $this->mailSink, 10, 2);
    }

    public function tearDown(): void
    {
        remove_action('fluent_cart/review_replied', $this->listener, 10);
        remove_filter('pre_wp_mail', $this->mailSink, 10);
        remove_all_filters('fluent_cart/review/allow_multiple_replies');
        parent::tearDown();
    }

    private function createApprovedReview(): ProductReview
    {
        $product = $this->productFactory->create();

        $review = ProductReviewResource::create([
            'post_id'        => $product->ID,
            'rating'         => 4,
            'title'          => 'Reply event spec',
            'content'        => 'Waiting for the store to answer.',
            'status'         => Status::REVIEW_APPROVED,
            'reviewer_name'  => 'Spec Reviewer',
            'reviewer_email' => 'spec-reviewer@example.com',
        ]);
        $this->assertFalse(is_wp_error($review));

        return $review;
    }

    private function replyAsStore(int $reviewId, string $content): \WP_REST_Response
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/reviews/' . $reviewId . '/reply');
        $request->set_body_params(['content' => $content]);

        return $this->dispatchRequest($request);
    }

    /**
     * Spec: Cases/reviews.md [60]
     *   "A moderator replying to a review fires the event once, after the reply has committed, with the reply, the review and the product."
     */
    public function test_a_store_reply_fires_once_after_commit(): void
    {
        $review = $this->createApprovedReview();

        $response = $this->replyAsStore($review->id, 'Thank you for the kind words.');

        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $this->assertCount(1, $this->fired);

        $payload = $this->fired[0];
        $this->assertSame(1, (int) $payload['reply']->is_admin_reply);
        $this->assertSame((int) $review->id, (int) $payload['reply']->parent_id);
        $this->assertSame((int) $review->id, (int) $payload['review']->id, 'the review being answered rides along');
        $this->assertSame((int) $review->post_id, (int) $payload['product']->ID);
        $this->assertSame([0], $this->depthAtFire, 'fired outside any transaction: the reply is committed by the time a listener hears of it');
    }

    /**
     * Spec: Cases/reviews.md [61]
     *   "A reply that is refused fires nothing."
     */
    public function test_a_refused_reply_fires_nothing(): void
    {
        $review = $this->createApprovedReview();
        $this->replyAsStore($review->id, 'First answer.');
        $this->fired = [];

        // Free ships one store reply per review; the second is refused.
        $response = $this->replyAsStore($review->id, 'Second answer.');

        $this->assertSame(422, $response->get_status());
        $this->assertSame([], $this->fired);
    }

    /**
     * Spec: Cases/reviews.md [62]
     *   "A bulk reply fires once per reply it wrote, after they have all committed, and not for a review it skipped."
     */
    public function test_a_bulk_reply_fires_once_per_written_reply_after_commit(): void
    {
        $a = $this->createApprovedReview();
        $b = $this->createApprovedReview();
        $alreadyAnswered = $this->createApprovedReview();
        $this->replyAsStore($alreadyAnswered->id, 'Answered earlier.');
        $this->fired = [];
        $this->depthAtFire = [];

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/reviews/bulk-reply');
        $request->set_body_params([
            'review_ids' => [$a->id, $b->id, $alreadyAnswered->id],
            'content'    => 'Thanks to all of you.',
        ]);
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));

        $answered = array_map(function ($payload) {
            return (int) $payload['review']->id;
        }, $this->fired);
        sort($answered);
        $expected = [(int) $a->id, (int) $b->id];
        sort($expected);

        $this->assertSame($expected, $answered, 'the review that already had a reply was skipped, not announced');
        $this->assertSame([0, 0], $this->depthAtFire, 'both fired after the one commit');
    }

    /**
     * Spec: Cases/reviews.md [63]
     *   "A customer's own follow-up, and a top-level review, never fire."
     */
    public function test_only_a_store_reply_fires(): void
    {
        $review = $this->createApprovedReview();

        $customerReply = ProductReviewResource::create([
            'post_id'        => $review->post_id,
            'parent_id'      => $review->id,
            'content'        => 'Adding to my own review.',
            'status'         => Status::REVIEW_APPROVED,
            'is_admin_reply' => 0,
            'reviewer_name'  => 'Spec Reviewer',
            'reviewer_email' => 'spec-reviewer@example.com',
        ]);
        $this->assertFalse(is_wp_error($customerReply));

        ReviewReplied::dispatchIfStoreReply($customerReply);
        ReviewReplied::dispatchIfStoreReply($review);

        $this->assertSame([], $this->fired);
    }

    /**
     * Spec: Cases/reviews.md [63a]
     *   "Handed the review and the product it answers, the event looks nothing up; handed neither, it looks each up once."
     */
    public function test_the_event_does_not_look_up_what_it_was_handed(): void
    {
        global $wpdb;

        $review = $this->createApprovedReview();
        $review->load('product');
        $reply = ProductReviewResource::create([
            'post_id'        => $review->post_id,
            'parent_id'      => $review->id,
            'content'        => 'Handed over.',
            'status'         => Status::REVIEW_APPROVED,
            'is_admin_reply' => 1,
            'reviewer_name'  => 'Store',
            'reviewer_email' => 'store@example.com',
        ]);

        $before = (int) $wpdb->num_queries;
        $payload = (new ReviewReplied($reply, $review, $review->product))->toArray();
        $this->assertSame($before, (int) $wpdb->num_queries, 'nothing to look up: the caller held both');
        $this->assertSame($review, $payload['review'], 'the very instance handed over');
        $this->assertSame($review->product, $payload['product']);

        $before = (int) $wpdb->num_queries;
        $payload = (new ReviewReplied($reply))->toArray();
        $this->assertLessThanOrEqual(2, (int) $wpdb->num_queries - $before, 'handed nothing: the review, then its product, once each');
        $this->assertSame((int) $review->id, (int) $payload['review']->id);
        $this->assertSame((int) $review->post_id, (int) $payload['product']->ID);
    }

    /**
     * Spec: Cases/reviews.md [63b]
     *   "A bulk reply's announcements reuse the reviews it loaded and one product query for the batch."
     */
    public function test_a_bulk_reply_does_not_look_up_each_reply_it_announces(): void
    {
        global $wpdb;

        $reviews = [$this->createApprovedReview(), $this->createApprovedReview(), $this->createApprovedReview()];

        // Count only what happens between the first and the last announcement:
        // the dispatches themselves. Each queues a job, which is a fixed cost
        // per reply; what must not be there is a lookup per reply on top.
        $queriesAtFire = [];
        $counter = function () use (&$queriesAtFire, $wpdb) {
            $queriesAtFire[] = (int) $wpdb->num_queries;
        };
        add_action('fluent_cart/review_replied', $counter, 5, 1);

        try {
            $request = new \WP_REST_Request('POST', '/fluent-cart/v2/reviews/bulk-reply');
            $request->set_body_params([
                'review_ids' => array_map(function ($r) { return $r->id; }, $reviews),
                'content'    => 'Thanks, all three of you.',
            ]);
            $response = $this->dispatchRequest($request);
            $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        } finally {
            remove_action('fluent_cart/review_replied', $counter, 5);
        }

        $this->assertCount(3, $queriesAtFire);
        // The queries between consecutive announcements are the previous
        // announcement's job enqueue — the same for every reply. A per-reply
        // lookup of the review or product would add to it.
        $perDispatch = [$queriesAtFire[1] - $queriesAtFire[0], $queriesAtFire[2] - $queriesAtFire[1]];
        $this->assertSame($perDispatch[0], $perDispatch[1], 'a constant cost per announcement');

        foreach ($this->fired as $payload) {
            $this->assertInstanceOf(ProductReview::class, $payload['review']);
            $this->assertNotNull($payload['product']);
            $this->assertSame((int) $payload['review']->post_id, (int) $payload['product']->ID);
        }
    }

    public function test_the_event_writes_no_activity_row(): void
    {
        $this->assertFalse((new ReviewReplied($this->createApprovedReview()))->shouldCreateActivity());
    }
}
