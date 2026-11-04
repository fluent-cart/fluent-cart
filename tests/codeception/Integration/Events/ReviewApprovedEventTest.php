<?php

namespace Tests\Integration\Events;

use FluentCart\Api\ModuleSettings;
use FluentCart\Api\Resource\ProductReviewResource;
use FluentCart\App\App;
use FluentCart\App\Events\ReviewApproved;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Services\ProductReviewService;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\RestTestCase;

/**
 * fluent_cart/review_approved — fired on every path into the approved status.
 *
 * There are more ways to approve a review than the moderation button, and a
 * listener that only hears about one of them is worse than none: a store would
 * wire an email to it and watch it silently not send for every review the
 * store auto-approved. So each path is pinned here: a moderator promoting one
 * review, a bulk approve, a review born approved on the storefront and one a
 * moderator wrote by hand, and — never — a reply.
 *
 * The born-approved cases go through the real controllers rather than the
 * resource, because where in the request the event fires is the point: the
 * submit hooks that attach photos save other_info from a model, and the
 * approval notice claims a key in that blob when the event fires. Fired
 * before those hooks, the claim would be erased under them.
 *
 * Spec: Cases/reviews.md — "Approval event"
 */
class ReviewApprovedEventTest extends RestTestCase
{
    private ProductFactory $productFactory;

    /** @var array<int, array> every payload the hook received, in order */
    private array $fired = [];

    /** @var string[] the order the submit hook and the event ran in */
    private array $sequence = [];

    private \Closure $listener;
    private \Closure $submitListener;
    private \Closure $mailSink;

    public function setUp(): void
    {
        parent::setUp();
        $this->productFactory = new ProductFactory();
        $this->fired = [];
        $this->sequence = [];

        $this->listener = function ($payload) {
            $this->fired[] = $payload;
            $this->sequence[] = 'approved';
        };
        add_action('fluent_cart/review_approved', $this->listener, 10, 1);

        $this->submitListener = function () {
            $this->sequence[] = 'after_submit';
        };
        add_action('fluent_cart/review/after_submit', $this->submitListener, 10, 1);

        // The storefront submit also dispatches review_created, whose admin
        // mail would otherwise try to leave the test process.
        $this->mailSink = function () {
            return true;
        };
        add_filter('pre_wp_mail', $this->mailSink, 10, 2);
    }

    public function tearDown(): void
    {
        remove_action('fluent_cart/review_approved', $this->listener, 10);
        remove_action('fluent_cart/review/after_submit', $this->submitListener, 10);
        remove_filter('pre_wp_mail', $this->mailSink, 10);
        ProductReviewService::resetRuntimeCache();
        parent::tearDown();
    }

    private function createReview(array $overrides = []): ProductReview
    {
        $product = $this->productFactory->create();

        $review = ProductReviewResource::create(array_merge([
            'post_id'        => $product->ID,
            'rating'         => 4,
            'title'          => 'Approval event spec',
            'content'        => 'Written to be approved.',
            'status'         => Status::REVIEW_PENDING,
            'reviewer_name'  => 'Spec Reviewer',
            'reviewer_email' => 'spec-reviewer@example.com',
        ], $overrides));

        $this->assertFalse(is_wp_error($review), 'fixture review should be created');

        return $review;
    }

    /** A review that is approved by the time the case starts, its own event already counted away. */
    private function createApprovedReview(): ProductReview
    {
        $review = $this->createReview();
        ProductReviewResource::update(['status' => Status::REVIEW_APPROVED], $review->id);
        $this->fired = [];
        $this->sequence = [];

        return ProductReview::query()->find($review->id);
    }

    private function firedIds(): array
    {
        return array_map(function ($payload) {
            return (int) $payload['review']->id;
        }, $this->fired);
    }

    private function setStorefrontMode(string $permissionMode, string $autoApprove): void
    {
        ModuleSettings::saveSettings([
            'reviews' => [
                'active'                 => 'yes',
                'review_permission_mode' => $permissionMode,
                'auto_approve_reviews'   => $autoApprove,
            ],
        ]);
        ModuleSettings::getAllSettings(false);
        ProductReviewService::resetRuntimeCache();

        App::request()->set('order_hash', null);
        App::request()->set('reviewer_email', null);
        // A guest is identified by IP for the rate limit; without one the
        // submit refuses to guess who is asking.
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    }

    /**
     * Spec: Cases/reviews.md [39]
     *   "A moderator approving a pending review fires the event once, with the review and its product."
     */
    public function test_a_moderator_approving_a_review_fires_once(): void
    {
        $review = $this->createReview();
        $this->assertSame([], $this->fired, 'a pending review is not an approval');

        ProductReviewResource::update(['status' => Status::REVIEW_APPROVED], $review->id);

        $this->assertCount(1, $this->fired);
        $this->assertSame((int) $review->id, (int) $this->fired[0]['review']->id);
        $this->assertSame(Status::REVIEW_APPROVED, $this->fired[0]['review']->status, 'the payload is the row as saved');
        $this->assertSame((int) $review->post_id, (int) $this->fired[0]['product']->ID, 'the product rides along');
    }

    /**
     * Spec: Cases/reviews.md [40]
     *   "Editing an approved review without changing its status is not an approval."
     */
    public function test_editing_an_already_approved_review_does_not_fire(): void
    {
        $review = $this->createApprovedReview();

        ProductReviewResource::update(['title' => 'Retitled'], $review->id);
        ProductReviewResource::update(['status' => Status::REVIEW_APPROVED], $review->id);

        $this->assertSame([], $this->fired, 'approved to approved is not a transition');
    }

    /**
     * Spec: Cases/reviews.md [41]
     *   "The event is the transition: approved, held back, approved again fires twice."
     */
    public function test_re_approval_after_holding_back_fires_again(): void
    {
        $review = $this->createReview();

        ProductReviewResource::update(['status' => Status::REVIEW_APPROVED], $review->id);
        ProductReviewResource::update(['status' => Status::REVIEW_PENDING], $review->id);
        ProductReviewResource::update(['status' => Status::REVIEW_APPROVED], $review->id);

        $this->assertCount(2, $this->fired, 'each transition into approved is an approval; once-only delivery is the notification\'s job, not the event\'s');
    }

    /**
     * Spec: Cases/reviews.md [42]
     *   "A review the store auto-approves on submission fires the event, after the submit hooks have run."
     */
    public function test_a_storefront_review_auto_approved_fires_after_the_submit_hooks(): void
    {
        $this->setStorefrontMode(Status::REVIEW_PERMISSION_ANYONE, 'yes');
        $product = $this->productFactory->create();

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/public/reviews/' . $product->ID);
        $request->set_body_params([
            'rating'         => 5,
            'title'          => 'Auto-approved',
            'content'        => 'The store approves these on sight.',
            'reviewer_name'  => 'Guest Reviewer',
            'reviewer_email' => 'guest-reviewer@example.com',
        ]);
        $response = $this->dispatchRequestAsGuest($request);

        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $this->assertCount(1, $this->fired);
        $this->assertSame(Status::REVIEW_APPROVED, $this->fired[0]['review']->status);
        $this->assertSame(
            ['after_submit', 'approved'],
            $this->sequence,
            'the photo hooks save other_info from a model; the event — and the claim it triggers — must land after them, not under them'
        );
    }

    /**
     * Spec: Cases/reviews.md [42a]
     *   "A review the store holds for moderation on submission does not fire."
     */
    public function test_a_storefront_review_held_for_moderation_does_not_fire(): void
    {
        $this->setStorefrontMode(Status::REVIEW_PERMISSION_ANYONE, 'no');
        $product = $this->productFactory->create();

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/public/reviews/' . $product->ID);
        $request->set_body_params([
            'rating'         => 5,
            'content'        => 'Held for a moderator.',
            'reviewer_name'  => 'Guest Reviewer',
            'reviewer_email' => 'guest-reviewer@example.com',
        ]);
        $response = $this->dispatchRequestAsGuest($request);

        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $this->assertSame([], $this->fired);
    }

    /**
     * Spec: Cases/reviews.md [42b]
     *   "A review a moderator writes and saves as approved fires the event, after the submit hooks have run."
     */
    public function test_a_review_a_moderator_writes_approved_fires_after_the_submit_hooks(): void
    {
        $product = $this->productFactory->create();

        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/reviews');
        $request->set_body_params([
            'post_id'        => $product->ID,
            'rating'         => 4,
            'title'          => 'Written by the store',
            'content'        => 'Transcribed from a letter.',
            'reviewer_name'  => 'Letter Writer',
            'reviewer_email' => 'letter-writer@example.com',
            'status'         => Status::REVIEW_APPROVED,
        ]);
        $response = $this->dispatchRequest($request);

        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $this->assertCount(1, $this->fired);
        $this->assertSame('letter-writer@example.com', $this->fired[0]['review']->reviewer_email);
        $this->assertSame(['after_submit', 'approved'], $this->sequence);
    }

    /**
     * Spec: Cases/reviews.md [43]
     *   "A reply never fires the event, whatever its status."
     */
    public function test_a_reply_never_fires(): void
    {
        $parent = $this->createApprovedReview();

        $reply = ProductReviewResource::create([
            'post_id'        => $parent->post_id,
            'parent_id'      => $parent->id,
            'content'        => 'Thanks for the kind words.',
            'status'         => Status::REVIEW_APPROVED,
            'is_admin_reply' => 1,
            'reviewer_name'  => 'Store',
            'reviewer_email' => 'store@example.com',
        ]);
        $this->assertFalse(is_wp_error($reply));

        ProductReviewResource::update(['status' => Status::REVIEW_PENDING], $reply->id);
        ProductReviewResource::update(['status' => Status::REVIEW_APPROVED], $reply->id);

        $this->assertSame([], $this->fired, 'a reply moving into approved is not an approval');
    }

    /**
     * Spec: Cases/reviews.md [44]
     *   "A bulk approve fires once for each review it actually moved, and not for one that was already approved."
     */
    public function test_bulk_approve_fires_only_for_the_rows_it_moved(): void
    {
        $pendingA = $this->createReview();
        $pendingB = $this->createReview();
        $already = $this->createApprovedReview();

        $result = ProductReviewResource::bulkAction('approve', [$pendingA->id, $pendingB->id, $already->id]);
        $this->assertFalse(is_wp_error($result));

        $ids = $this->firedIds();
        sort($ids);
        $expected = [(int) $pendingA->id, (int) $pendingB->id];
        sort($expected);

        $this->assertSame($expected, $ids, 'the row already approved was not approved by this action');
        foreach ($this->fired as $payload) {
            $this->assertSame(Status::REVIEW_APPROVED, $payload['review']->status, 'the payload is re-read after the bulk write, not the stale pre-update row');
        }
    }

    /**
     * Spec: Cases/reviews.md [44a]
     *   "A bulk approve loads the products once for the batch; each announcement carries its product without a lookup of its own."
     */
    public function test_bulk_approve_does_not_look_up_the_product_per_review(): void
    {
        global $wpdb;

        $reviews = [$this->createReview(), $this->createReview(), $this->createReview()];
        $this->fired = [];

        $queriesAtFire = [];
        $counter = function () use (&$queriesAtFire, $wpdb) {
            $queriesAtFire[] = (int) $wpdb->num_queries;
        };
        add_action('fluent_cart/review_approved', $counter, 5, 1);

        try {
            $result = ProductReviewResource::bulkAction('approve', array_map(function ($r) { return $r->id; }, $reviews));
            $this->assertFalse(is_wp_error($result));
        } finally {
            remove_action('fluent_cart/review_approved', $counter, 5);
        }

        $this->assertCount(3, $queriesAtFire);
        // Between consecutive announcements only the previous one's job
        // enqueue runs — the same cost every time. A product lookup per
        // review would add to it.
        $perDispatch = [$queriesAtFire[1] - $queriesAtFire[0], $queriesAtFire[2] - $queriesAtFire[1]];
        $this->assertSame($perDispatch[0], $perDispatch[1], 'a constant cost per announcement');

        foreach ($this->fired as $payload) {
            $this->assertTrue($payload['review']->relationLoaded('product'), 'the product came with the batch query');
            $this->assertSame((int) $payload['review']->post_id, (int) $payload['product']->ID);
        }
    }

    /**
     * Spec: Cases/reviews.md [45]
     *   "A bulk action other than approve fires nothing."
     */
    public function test_other_bulk_actions_fire_nothing(): void
    {
        $approved = $this->createApprovedReview();

        ProductReviewResource::bulkAction('pending', [$approved->id]);
        ProductReviewResource::bulkAction('spam', [$approved->id]);

        $this->assertSame([], $this->fired);
    }

    public function test_the_guard_answers_the_same_question_for_every_caller(): void
    {
        $review = $this->createApprovedReview();

        ReviewApproved::dispatchIfApproved($review, Status::REVIEW_APPROVED);
        $this->assertSame([], $this->fired, 'was already approved');

        ReviewApproved::dispatchIfApproved($review, Status::REVIEW_PENDING);
        $this->assertCount(1, $this->fired, 'moved into approved');

        $review->status = Status::REVIEW_PENDING;
        ReviewApproved::dispatchIfApproved($review, null);
        $this->assertCount(1, $this->fired, 'not approved now');
    }

    public function test_the_event_writes_no_activity_row(): void
    {
        $this->assertFalse((new ReviewApproved($this->createReview()))->shouldCreateActivity());
    }
}
