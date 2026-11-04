<?php

namespace Tests\Integration\Services;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Services\ProductReviewService;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\ProductFactory;

/**
 * What a customer sees in My Reviews → History.
 *
 * Everything they wrote except spam and trash: a pending review is still
 * theirs and still shown, so they can see it is waiting rather than conclude
 * it was lost. Spam and trash are held back because a customer should not be
 * shown a moderator's judgement of them.
 *
 * The list and the tab's count run the same query, so they cannot disagree
 * about what "history" means — a count that included a row the list hides
 * would read as a page that failed to load.
 *
 * Spec: Cases/reviews.md — "Customer review history"
 */
class CustomerReviewHistoryStatusTest extends WPTestCase
{
    private $customer;
    private $product;

    public function setUp(): void
    {
        parent::setUp();
        $this->customer = (new CustomerFactory())->create();
        $this->product = (new ProductFactory())->create(['post_title' => 'History Widget']);
    }

    public function tearDown(): void
    {
        remove_all_filters('fluent_cart/review_duplicate_statuses');
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function writeReview(string $status, int $userId = 0): ProductReview
    {
        $review = new ProductReview([
            'post_id'        => $this->product->ID,
            'reviewer_name'  => 'Dana Buyer',
            'reviewer_email' => $this->customer->email,
            'review'         => 'Filed as ' . $status . '.',
            'rating'         => 4,
        ]);
        $review->customer_id = $this->customer->id;
        $review->user_id = $userId ?: null;
        $review->status = $status;
        $review->save();

        return $review;
    }

    /**
     * @return array<int, bool> the can_edit flag of each row, in order
     */
    private function historyCanEdit(): array
    {
        $payload = ProductReviewService::getCustomerReviewsPayload($this->customer, ['per_page' => 50]);

        return array_map(function ($row) {
            return $row['can_edit'];
        }, $payload['reviews']['data']);
    }

    /**
     * @return array<int, string> status of each row the history returns
     */
    private function historyStatuses(): array
    {
        $payload = ProductReviewService::getCustomerReviewsPayload($this->customer, ['per_page' => 50]);

        return array_map(function ($row) {
            return $row['status'];
        }, $payload['reviews']['data']);
    }

    public function test_history_shows_approved_and_pending_alike(): void
    {
        $this->writeReview(Status::REVIEW_APPROVED);
        $this->writeReview(Status::REVIEW_PENDING);

        $statuses = $this->historyStatuses();

        sort($statuses);
        $this->assertSame(
            [Status::REVIEW_APPROVED, Status::REVIEW_PENDING],
            $statuses,
            'a review waiting on moderation is still the customer\'s, and still shown'
        );
    }

    public function test_history_hides_spam_and_trash(): void
    {
        $this->writeReview(Status::REVIEW_APPROVED);
        $this->writeReview(Status::REVIEW_SPAM);
        $this->writeReview(Status::REVIEW_TRASH);

        $this->assertSame(
            [Status::REVIEW_APPROVED],
            $this->historyStatuses(),
            'only the two that mean "not really a review" are held back'
        );
    }

    /**
     * The count behind the History tab label and the list it opens are the
     * same question, so they run the same query.
     */
    public function test_the_tab_count_matches_the_rows_the_list_returns(): void
    {
        $this->writeReview(Status::REVIEW_APPROVED);
        $this->writeReview(Status::REVIEW_PENDING);
        $this->writeReview(Status::REVIEW_SPAM);
        $this->writeReview(Status::REVIEW_TRASH);

        $this->assertSame(
            count($this->historyStatuses()),
            ProductReviewService::countCustomerReviews($this->customer),
            'the tab cannot promise rows the list will not show'
        );
    }

    /**
     * The Edit button is offered only where updateReview() would accept the
     * change: it pins id + post_id + user_id, so a review with an account
     * behind it is editable and one reached by email alone is not.
     */
    public function test_only_a_review_owned_by_the_signed_in_account_may_be_edited(): void
    {
        $userId = wp_insert_user([
            'user_login' => 'dana-history-' . uniqid(),
            'user_email' => 'dana-history-' . uniqid() . '@example.com',
            'user_pass'  => wp_generate_password(),
        ]);

        $this->writeReview(Status::REVIEW_APPROVED, (int) $userId);
        $this->writeReview(Status::REVIEW_APPROVED);

        wp_set_current_user((int) $userId);

        $flags = $this->historyCanEdit();
        sort($flags);

        $this->assertSame(
            [false, true],
            $flags,
            'the guest-email row is theirs to read, not to change'
        );

        wp_set_current_user(0);
        wp_delete_user((int) $userId);
    }

    /**
     * A logged-out reader owns nothing, whatever the rows say.
     */
    public function test_nothing_is_editable_without_a_signed_in_account(): void
    {
        $this->writeReview(Status::REVIEW_APPROVED, 1);
        wp_set_current_user(0);

        $this->assertSame([false], $this->historyCanEdit());
    }

    /**
     * The history used to borrow getReviewDuplicateStatuses(), which is
     * filterable. A site narrowing its duplicate rules would then have
     * narrowed what customers see of their own writing — this pins that the
     * two questions are now answered separately.
     */
    public function test_narrowing_the_duplicate_rules_does_not_hide_a_pending_review(): void
    {
        $this->writeReview(Status::REVIEW_PENDING);

        add_filter('fluent_cart/review_duplicate_statuses', function () {
            return [Status::REVIEW_APPROVED];
        });

        $this->assertSame(
            [Status::REVIEW_PENDING],
            $this->historyStatuses(),
            'the duplicate guard has no say over what an author sees'
        );
    }
}
