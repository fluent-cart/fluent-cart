<?php

namespace Tests\Integration\Services;

use FluentCart\Api\Resource\ProductReviewResource;
use FluentCart\App\Events\ReviewCreated;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Services\TemplateService;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\EmailNotificationConfigFactory;
use Tests\Support\Factory\ProductFactory;

/**
 * The "new review submitted" email to the store.
 *
 * It had no test at all. These pin what a moderator needs from it — who
 * wrote it, whether they bought the product, whether there are photos to
 * look at, where it stands, and a link to act on it — and that rendering it
 * leaves the review model it was handed exactly as it found it.
 *
 * Spec: Cases/reviews.md — "Submitted notice"
 */
class ReviewSubmittedAdminEmailTest extends WPTestCase
{
    private ProductFactory $productFactory;
    private EmailNotificationConfigFactory $configFactory;

    private array $sentMail = [];
    private \Closure $mailCapture;

    public function setUp(): void
    {
        parent::setUp();
        $this->productFactory = new ProductFactory();
        $this->configFactory = new EmailNotificationConfigFactory();
        $this->configFactory->reset();
        $this->sentMail = [];

        $this->mailCapture = function ($null, array $atts) {
            $this->sentMail[] = $atts;
            return true;
        };
        add_filter('pre_wp_mail', $this->mailCapture, 10, 2);
    }

    public function tearDown(): void
    {
        remove_filter('pre_wp_mail', $this->mailCapture, 10);
        $this->configFactory->reset();
        parent::tearDown();
    }

    private function createReview(array $overrides = []): ProductReview
    {
        $product = $this->productFactory->create();

        $review = ProductReviewResource::create(array_merge([
            'post_id'        => $product->ID,
            'rating'         => 4,
            'title'          => 'Submitted notice spec',
            'content'        => 'What the customer wrote.',
            'status'         => Status::REVIEW_PENDING,
            'reviewer_name'  => 'Ada',
            'reviewer_email' => 'ada@example.com',
        ], $overrides));
        $this->assertFalse(is_wp_error($review));

        return $review;
    }

    private function render(ProductReview $review): string
    {
        $review->load('product');

        return TemplateService::getTemplateByPathName('review.submitted.admin', [
            'review'  => $review,
            'product' => $review->product,
        ]);
    }

    /**
     * Spec: Cases/reviews.md [72]
     *   "The store's new-review email names the reviewer, their address, the product, and whether the review is waiting for approval, with a link to moderate it."
     */
    public function test_a_pending_review_is_presented_for_moderation(): void
    {
        $review = $this->createReview();
        $html = $this->render($review);

        $this->assertStringContainsString('Ada', $html);
        $this->assertStringContainsString('ada@example.com', $html);
        $this->assertStringContainsString(get_the_title($review->post_id), $html);
        $this->assertStringContainsString('What the customer wrote.', $html);
        $this->assertStringContainsString('Pending approval', $html);
        $this->assertStringContainsString('Moderate review', $html);
        $this->assertStringContainsString('#/reviews/' . $review->id . '/view', $html, 'links to the review in the admin');
        $this->assertStringNotContainsString('Verified purchase', $html, 'not a verified purchase');
        $this->assertStringContainsString('4 out of 5', $html, 'the rating reads without colour');
    }

    /**
     * Spec: Cases/reviews.md [73]
     *   "An auto-approved review is presented as already live, with a verified badge and a photo count when it has them."
     */
    public function test_an_approved_verified_photo_review_shows_each_fact(): void
    {
        $review = $this->createReview(['status' => Status::REVIEW_APPROVED, 'is_verified' => 1]);
        // The media pipeline writes the count straight onto the row.
        ProductReview::query()->where('id', $review->id)->update(['media_count' => 2]);
        $review = ProductReview::query()->find($review->id);

        $html = $this->render($review);

        $this->assertStringContainsString('already live', $html);
        $this->assertStringContainsString('Verified purchase', $html);
        $this->assertStringContainsString('2 photos', $html);
        $this->assertStringContainsString('View review', $html);
        $this->assertStringNotContainsString('Pending approval', $html);
    }

    /**
     * Spec: Cases/reviews.md [74]
     *   "Rendering the email leaves the review model it was handed unchanged."
     */
    public function test_rendering_does_not_touch_the_model_it_was_handed(): void
    {
        $review = $this->createReview();

        $this->render($review);

        $this->assertNull($review->getAttribute('item_label'), 'the item label is resolved on a copy, never left on the caller\'s model');
    }

    /**
     * Spec: Cases/reviews.md [75]
     *   "The email goes out on fluent_cart/review_created, to the store, and not for a reply."
     */
    public function test_it_is_sent_on_review_created_and_not_for_a_reply(): void
    {
        $review = $this->createReview();
        (new ReviewCreated($review))->dispatch();
        $this->assertCount(1, $this->sentMail);
        $this->assertStringContainsString('Ada', $this->sentMail[0]['message']);

        $reply = ProductReviewResource::create([
            'post_id'        => $review->post_id,
            'parent_id'      => $review->id,
            'content'        => 'Thanks.',
            'status'         => Status::REVIEW_APPROVED,
            'is_admin_reply' => 1,
            'reviewer_name'  => 'Store',
            'reviewer_email' => 'store@example.com',
        ]);
        (new ReviewCreated($reply))->dispatch();
        $this->assertCount(1, $this->sentMail, 'a reply is not a new review');
    }
}
