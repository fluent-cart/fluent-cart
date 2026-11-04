<?php

namespace Tests\Integration\Services;

use FluentCart\Api\Resource\ProductReviewResource;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Services\ProductReviewService;
use FluentCart\App\Services\ShortCodeParser\ShortcodeTemplateBuilder;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\EmailNotificationConfigFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\WPUserFactory;

/**
 * The approval notice to the reviewer: who gets it, and that they get it once.
 *
 * The event fires on every transition into approved; this is the layer that
 * turns that into exactly one email per review. Nothing is sent in the
 * approving request: the event queues a job, the job re-reads the review and
 * fires fluent_cart/review_approved_done only if it is still approved, and
 * the mailer sends on that hook behind a claim. So the cases here are about
 * the claim that stops a second send, the states it can end in, and what the
 * job does when the world has moved on since the approval.
 *
 * Spec: Cases/reviews.md — "Approval notice"
 */
class ReviewApprovedNotificationTest extends WPTestCase
{
    private const MAIL_NAME = 'review_approved_customer';

    private ProductFactory $productFactory;
    private CustomerFactory $customerFactory;
    private EmailNotificationConfigFactory $configFactory;

    private array $sentMail = [];
    private \Closure $mailCapture;

    public function setUp(): void
    {
        parent::setUp();
        $this->productFactory = new ProductFactory();
        $this->customerFactory = new CustomerFactory();
        $this->configFactory = new EmailNotificationConfigFactory();
        $this->configFactory->reset();
        $this->sentMail = [];

        // What wp_mail() does when the transport accepts the message: it
        // returns true and fires wp_mail_succeeded. A capture that returned
        // true in silence would read, correctly, as not delivered.
        $this->mailCapture = function ($null, array $atts) {
            $this->sentMail[] = $atts;
            do_action('wp_mail_succeeded', $atts);
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
            'rating'         => 5,
            'title'          => 'Notice spec',
            'content'        => 'The body of the review that was approved.',
            'status'         => Status::REVIEW_PENDING,
            'reviewer_name'  => 'Notice Reviewer',
            'reviewer_email' => 'notice-reviewer@example.com',
        ], $overrides));

        $this->assertFalse(is_wp_error($review), 'fixture review should be created');

        return $review;
    }

    private function approve(ProductReview $review): void
    {
        $result = ProductReviewResource::update(['status' => Status::REVIEW_APPROVED], $review->id);
        $this->assertFalse(is_wp_error($result));
    }

    private function approvalNoticeState(int $reviewId)
    {
        $row = ProductReview::query()->find($reviewId);

        return $row && is_array($row->other_info) ? ($row->other_info['approval_notice'] ?? null) : null;
    }

    /** The queued job, run by hand — what Action Scheduler would do later. */
    private function runApprovalJob(int $reviewId): void
    {
        do_action('fluent_cart/review_approved_async_private_handle', ['review_id' => $reviewId]);
    }

    // ------------------------------------------------------------------
    // The claim
    // ------------------------------------------------------------------

    /**
     * Spec: Cases/reviews.md [46]
     *   "The approval notice is claimed by exactly one caller; a second claim on the same review is refused."
     */
    public function test_the_claim_is_taken_once(): void
    {
        $review = $this->createReview();

        $this->assertNotNull(ProductReviewService::claimApprovalNotice($review));
        $this->assertNull(ProductReviewService::claimApprovalNotice($review), 'the second caller sees zero affected rows');
        $this->assertSame('sending', $this->approvalNoticeState($review->id), 'a lease, settled after the send');
    }

    /**
     * Spec: Cases/reviews.md [47]
     *   "Claiming writes only its own key: the review's photos in other_info survive it."
     */
    public function test_the_claim_leaves_the_rest_of_other_info_alone(): void
    {
        $review = $this->createReview();
        $media = [['id' => 12, 'type' => 'image', 'order' => 0]];

        // The same blob the media pipeline owns, written the way it writes it.
        ProductReview::query()->where('id', $review->id)->update([
            'other_info' => wp_json_encode(['media' => $media, 'note' => 'kept']),
        ]);

        $this->assertNotNull(ProductReviewService::claimApprovalNotice($review));

        $stored = ProductReview::query()->find($review->id)->other_info;
        $this->assertSame($media, $stored['media'], 'photos are untouched by the claim');
        $this->assertSame('kept', $stored['note']);
        $this->assertSame('sending', $stored['approval_notice']);
    }

    /**
     * Spec: Cases/reviews.md [47a]
     *   "Request metadata can neither set nor erase the notice state: a submitted approval_notice is dropped, and an edit's metadata leaves a stored one exactly as it was."
     */
    public function test_request_metadata_cannot_set_or_erase_the_notice_state(): void
    {
        // Injection on create: a payload that claims to have been notified.
        $injected = $this->createReview(['meta' => ['approval_notice' => 'sent', 'approval_notice_token' => 'forged', 'note' => 'kept']]);
        $stored = ProductReview::query()->find($injected->id)->other_info;
        $this->assertSame('kept', $stored['note'], 'ordinary metadata is stored');
        $this->assertArrayNotHasKey('approval_notice', $stored, 'the notice state is not the caller\'s to set');
        $this->assertArrayNotHasKey('approval_notice_token', $stored);
        $this->approve($injected);
        $this->runApprovalJob($injected->id);
        $this->assertCount(1, $this->sentMail, 'and the email goes out regardless');
        $this->sentMail = [];

        // Erasure on update: an edit with metadata after the notice was sent.
        $result = ProductReviewResource::update(['meta' => ['note' => 'edited', 'approval_notice' => null]], $injected->id);
        $this->assertFalse(is_wp_error($result));
        $stored = ProductReview::query()->find($injected->id)->other_info;
        $this->assertSame('edited', $stored['note']);
        $this->assertSame('sent', $stored['approval_notice'], 'a real sent survives an edit\'s metadata');
        ProductReviewResource::update(['status' => Status::REVIEW_PENDING], $injected->id);
        $this->approve(ProductReview::query()->find($injected->id));
        $this->runApprovalJob($injected->id);
        $this->assertSame([], $this->sentMail, 'so a re-approval still does not send again');

        // Erasure of a live lease: an edit while a send is in progress.
        $leased = $this->createReview();
        $token = ProductReviewService::claimApprovalNotice($leased);
        ProductReviewResource::update(['meta' => ['note' => 'mid-send']], $leased->id);
        $stored = ProductReview::query()->find($leased->id)->other_info;
        $this->assertSame('sending', $stored['approval_notice'], 'the lease is still held');
        $this->assertSame($token, $stored['approval_notice_token'], 'by the same holder');
    }

    /**
     * Spec: Cases/reviews.md [48]
     *   "A delivered notice is final; an undelivered one releases the lease so the next job can send."
     */
    public function test_which_outcomes_can_be_claimed_again(): void
    {
        $review = $this->createReview();

        $token = ProductReviewService::claimApprovalNotice($review);
        ProductReviewService::settleApprovalNotice($review->id, $token, false);
        $this->assertNull($this->approvalNoticeState($review->id), 'released: the row reads as never sent');
        $token = ProductReviewService::claimApprovalNotice($review);
        $this->assertNotNull($token, 'and can be leased again');

        ProductReviewService::settleApprovalNotice($review->id, $token, true);
        $this->assertSame('sent', $this->approvalNoticeState($review->id));
        $this->assertNull(ProductReviewService::claimApprovalNotice($review), 'delivered is final');
    }

    /**
     * Spec: Cases/reviews.md [48a]
     *   "A lease left at "sending" longer than the timeout is abandoned and may be taken; a fresh one may not."
     */
    public function test_an_abandoned_lease_can_be_taken_over_but_a_fresh_one_cannot(): void
    {
        $abandoned = $this->createReview();
        $staleAt = gmdate('Y-m-d H:i:s', time() - ProductReviewService::NOTICE_LEASE_SECONDS - 60);
        ProductReview::query()->where('id', $abandoned->id)->update([
            'other_info' => wp_json_encode(['approval_notice' => 'sending', 'approval_notice_at' => $staleAt]),
        ]);
        $this->assertNotNull(ProductReviewService::claimApprovalNotice($abandoned), 'a worker died holding this; the next one takes over');

        $fresh = $this->createReview();
        ProductReview::query()->where('id', $fresh->id)->update([
            'other_info' => wp_json_encode(['approval_notice' => 'sending', 'approval_notice_at' => gmdate('Y-m-d H:i:s')]),
        ]);
        $this->assertNull(ProductReviewService::claimApprovalNotice($fresh), 'still being sent by someone');

        $done = $this->createReview();
        ProductReview::query()->where('id', $done->id)->update([
            'other_info' => wp_json_encode(['approval_notice' => 'sent', 'approval_notice_at' => $staleAt]),
        ]);
        $this->assertNull(ProductReviewService::claimApprovalNotice($done), 'delivered long ago is still delivered');
    }

    /**
     * Spec: Cases/reviews.md [48b]
     *   "A lease is settled only by its holder: a worker that stalled past the timeout cannot close or release the lease another has since taken."
     */
    public function test_a_stale_holder_cannot_settle_a_lease_taken_over_since(): void
    {
        $review = $this->createReview();

        // The first worker takes the lease, then stalls past the timeout.
        $staleToken = ProductReviewService::claimApprovalNotice($review);
        $this->assertNotNull($staleToken);
        $staleAt = gmdate('Y-m-d H:i:s', time() - ProductReviewService::NOTICE_LEASE_SECONDS - 60);
        ProductReview::query()->where('id', $review->id)->update([
            'other_info' => wp_json_encode(['approval_notice' => 'sending', 'approval_notice_at' => $staleAt, 'approval_notice_token' => $staleToken]),
        ]);

        // A second worker takes it over.
        $freshToken = ProductReviewService::claimApprovalNotice($review);
        $this->assertNotNull($freshToken);
        $this->assertNotSame($staleToken, $freshToken);

        // The first wakes and tries to release — and then to close — what is
        // no longer its own. Neither lands.
        ProductReviewService::settleApprovalNotice($review->id, $staleToken, false);
        $this->assertSame('sending', $this->approvalNoticeState($review->id), 'a stale release does not free the second worker\'s lease');
        ProductReviewService::settleApprovalNotice($review->id, $staleToken, true);
        $this->assertSame('sending', $this->approvalNoticeState($review->id), 'nor does a stale close');

        // The holder settles it.
        ProductReviewService::settleApprovalNotice($review->id, $freshToken, true);
        $this->assertSame('sent', $this->approvalNoticeState($review->id));
    }

    // ------------------------------------------------------------------
    // Approving queues; the worker sends
    // ------------------------------------------------------------------

    /**
     * Spec: Cases/reviews.md [49]
     *   "Approving a review queues the notice and sends nothing in that request."
     */
    public function test_approving_queues_and_does_not_send_inline(): void
    {
        $review = $this->createReview();

        $this->approve($review);

        $this->assertSame([], $this->sentMail, 'nothing goes out inside the approving request');
        $this->assertNull($this->approvalNoticeState($review->id), 'the notice is decided by the job, not by the approving request');
    }

    /**
     * Spec: Cases/reviews.md [50]
     *   "The job sends the notice to the reviewer's address and marks it sent."
     */
    public function test_the_job_sends_to_the_reviewer_and_marks_sent(): void
    {
        $review = $this->createReview(['reviewer_email' => 'the-author@example.com']);
        $this->approve($review);

        $this->runApprovalJob($review->id);

        $this->assertCount(1, $this->sentMail);
        $mail = $this->sentMail[0];
        $this->assertSame('the-author@example.com', $mail['to']);
        $this->assertStringContainsString(get_the_title($review->post_id), $mail['subject'], 'the subject names the product');
        $this->assertStringContainsString('The body of the review that was approved.', $mail['message']);
        $this->assertStringNotContainsString('Order Date', $mail['message'], 'a review is not about an order; no order header');
        $this->assertStringContainsString('5 out of 5', $mail['message'], 'the rating reads without colour');
        $this->assertSame('sent', $this->approvalNoticeState($review->id));
    }

    /**
     * Spec: Cases/reviews.md [50a]
     *   "A review written in paragraphs arrives in paragraphs, escaped."
     */
    public function test_the_reviewers_line_breaks_survive_and_markup_does_not(): void
    {
        $review = $this->createReview(['content' => "First thought.\n\nSecond thought <b>bold</b>."]);
        $this->approve($review);

        $this->runApprovalJob($review->id);

        // The CSS inliner rewrites the markup on its way out (<br /> becomes
        // <br>), so the break is matched by shape, not by bytes.
        $message = $this->sentMail[0]['message'];
        $this->assertMatchesRegularExpression('/First thought\.\s*<br\s*\/?>\s*<br\s*\/?>\s*Second thought/', $message, 'the paragraph break is kept');
        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $message, 'and markup is shown, not run');
    }

    /**
     * Spec: Cases/reviews.md [56a]
     *   "The active-names gate answers exactly as getNotificationsOfEvent() would, switched on and off."
     */
    public function test_the_two_gates_agree(): void
    {
        $data = ['review' => $this->createReview(), 'product' => null];

        $this->assertSame(
            array_keys(\FluentCart\App\Services\Email\EmailNotifications::getNotificationsOfEvent('review_approved_done', $data)),
            \FluentCart\App\Services\Email\EmailNotifications::activeNotificationNamesOfEvent('review_approved_done')
        );

        $this->configFactory->withNotificationConfig(self::MAIL_NAME, ['active' => 'no']);

        $this->assertSame([], \FluentCart\App\Services\Email\EmailNotifications::activeNotificationNamesOfEvent('review_approved_done'));
        $this->assertSame([], array_keys(\FluentCart\App\Services\Email\EmailNotifications::getNotificationsOfEvent('review_approved_done', $data)));
    }

    public function test_the_two_gates_agree_with_a_second_notification_registered(): void
    {
        $review = $this->createReview();
        $data = ['review' => $review, 'product' => null];

        $addCopy = function ($notifications) {
            $copy = $notifications['review_approved_customer'];
            $notifications['review_approved_customer_copy'] = $copy;
            return $notifications;
        };
        add_filter('fluent_cart/email_notifications', $addCopy, 10, 1);

        try {
            $this->assertSame(
                ['review_approved_customer', 'review_approved_customer_copy'],
                \FluentCart\App\Services\Email\EmailNotifications::activeNotificationNamesOfEvent('review_approved_done')
            );
            $this->assertSame(
                array_keys(\FluentCart\App\Services\Email\EmailNotifications::getNotificationsOfEvent('review_approved_done', $data)),
                \FluentCart\App\Services\Email\EmailNotifications::activeNotificationNamesOfEvent('review_approved_done')
            );
        } finally {
            remove_filter('fluent_cart/email_notifications', $addCopy, 10);
        }
    }

    /**
     * Spec: Cases/reviews.md [51]
     *   "A review approved, held back and approved again is told once."
     */
    public function test_a_second_approval_after_a_send_does_not_email_again(): void
    {
        $review = $this->createReview();
        $this->approve($review);
        $this->runApprovalJob($review->id);
        $this->assertCount(1, $this->sentMail);

        ProductReviewResource::update(['status' => Status::REVIEW_PENDING], $review->id);
        $this->approve($review);

        $this->runApprovalJob($review->id);
        $this->assertCount(1, $this->sentMail, 'the second job could not take the claim');
        $this->assertSame('sent', $this->approvalNoticeState($review->id));
    }

    /**
     * Spec: Cases/reviews.md [52]
     *   "A job that finds the review no longer approved fires nothing and claims nothing, so the next real approval starts clean."
     */
    public function test_the_job_stands_down_when_the_review_was_held_back_meanwhile(): void
    {
        $review = $this->createReview();
        $this->approve($review);

        // Held back between the approval and the job running — by hand here,
        // the way a status write from any other path would land.
        ProductReview::query()->where('id', $review->id)->update(['status' => Status::REVIEW_PENDING]);

        $this->runApprovalJob($review->id);

        $this->assertSame([], $this->sentMail);
        $this->assertNull($this->approvalNoticeState($review->id), 'nothing claimed, so the next real approval starts clean');

        $this->approve(ProductReview::query()->find($review->id));
        $this->runApprovalJob($review->id);
        $this->assertCount(1, $this->sentMail);
        $this->assertSame('sent', $this->approvalNoticeState($review->id));
    }

    /**
     * Spec: Cases/reviews.md [53]
     *   "A job for a review that has been deleted does nothing."
     */
    public function test_the_job_ignores_a_deleted_review(): void
    {
        $review = $this->createReview();
        $this->approve($review);
        ProductReview::query()->where('id', $review->id)->delete();

        $this->runApprovalJob($review->id);

        $this->assertSame([], $this->sentMail);
    }

    /**
     * Spec: Cases/reviews.md [54]
     *   "fluent_cart/review_approved_done fires from the job only for a review that is still an approved top-level review, with the review and its product."
     */
    public function test_the_done_hook_fires_only_for_a_review_still_approved(): void
    {
        $done = [];
        $listener = function ($data) use (&$done) {
            $done[] = $data;
        };
        add_action('fluent_cart/review_approved_done', $listener, 10, 1);

        try {
            $review = $this->createReview();
            $this->approve($review);
            $this->runApprovalJob($review->id);
            $this->assertCount(1, $done, 'still approved: announced');
            $this->assertSame((int) $review->id, (int) $done[0]['review']->id);
            $this->assertSame((int) $review->post_id, (int) $done[0]['product']->ID);

            ProductReview::query()->where('id', $review->id)->update(['status' => Status::REVIEW_SPAM]);
            $this->runApprovalJob($review->id);
            $this->assertCount(1, $done, 'marked spam since: not announced');

            $this->runApprovalJob(999999);
            $this->assertCount(1, $done, 'no such review: not announced');
        } finally {
            remove_action('fluent_cart/review_approved_done', $listener, 10);
        }
    }

    /**
     * Spec: Cases/reviews.md [55]
     *   "A delivery the transport refuses releases the lease; the next job sends."
     */
    public function test_a_refused_delivery_releases_the_lease_for_the_next_job(): void
    {
        $review = $this->createReview();
        $this->approve($review);

        // What wp_mail() does when the transport refuses the message: it
        // returns false and fires wp_mail_failed with the reason.
        remove_filter('pre_wp_mail', $this->mailCapture, 10);
        $refuse = function () {
            do_action('wp_mail_failed', new \WP_Error('wp_mail_failed', 'transport refused'));
            return false;
        };
        add_filter('pre_wp_mail', $refuse, 10, 2);

        try {
            $this->runApprovalJob($review->id);
            $this->assertNull($this->approvalNoticeState($review->id), 'released, not recorded as sent');
        } finally {
            remove_filter('pre_wp_mail', $refuse, 10);
            add_filter('pre_wp_mail', $this->mailCapture, 10, 2);
        }

        $this->runApprovalJob($review->id);
        $this->assertCount(1, $this->sentMail, 'delivery works again: the next job sends');
        $this->assertSame('sent', $this->approvalNoticeState($review->id));
    }

    /**
     * Spec: Cases/reviews.md [55b]
     *   "With two notifications on the event, a failure on the second does not send the first again on retry."
     */
    public function test_a_failure_on_the_second_notification_does_not_repeat_the_first(): void
    {
        // A second notification on the same event, the way an add-on adds one.
        $addCopy = function ($notifications) {
            $copy = $notifications['review_approved_customer'];
            $copy['settings']['subject'] = 'COPY for the store owner';
            $notifications['review_approved_customer_copy'] = $copy;
            return $notifications;
        };
        add_filter('fluent_cart/email_notifications', $addCopy, 10, 1);

        // The transport refuses only the copy.
        remove_filter('pre_wp_mail', $this->mailCapture, 10);
        $refuseCopy = function ($null, array $atts) {
            if (strpos((string) $atts['subject'], 'COPY') !== false) {
                do_action('wp_mail_failed', new \WP_Error('wp_mail_failed', 'transport refused'));
                return false;
            }
            $this->sentMail[] = $atts;
            do_action('wp_mail_succeeded', $atts);
            return true;
        };
        add_filter('pre_wp_mail', $refuseCopy, 10, 2);

        try {
            $review = $this->createReview();
            $this->approve($review);

            $this->runApprovalJob($review->id);
            $this->assertCount(1, $this->sentMail, 'the first went out, the copy did not');
            $this->assertNull($this->approvalNoticeState($review->id), 'released for a retry');
            $this->assertSame(['review_approved_customer'], ProductReviewService::deliveredApprovalNotices($review->id), 'but the one that went out is remembered');

            // Transport recovers; the retry sends only the copy.
            remove_filter('pre_wp_mail', $refuseCopy, 10);
            add_filter('pre_wp_mail', $this->mailCapture, 10, 2);

            $this->runApprovalJob($review->id);
            $this->assertCount(2, $this->sentMail, 'exactly one more');
            $this->assertStringContainsString('COPY', $this->sentMail[1]['subject'], 'and it is the copy, not the first again');
            $this->assertSame('sent', $this->approvalNoticeState($review->id));
            $this->assertSame([], ProductReviewService::deliveredApprovalNotices($review->id), 'the memory is cleared once everything is out');
        } finally {
            remove_filter('pre_wp_mail', $refuseCopy, 10);
            remove_filter('fluent_cart/email_notifications', $addCopy, 10);
            remove_filter('pre_wp_mail', $this->mailCapture, 10);
            add_filter('pre_wp_mail', $this->mailCapture, 10, 2);
        }
    }

    /**
     * Spec: Cases/reviews.md [55c]
     *   "A send that wp_mail() short-circuits — false, with no failure reported — is not delivered: the lease is released and the next job sends."
     */
    public function test_a_silent_false_from_wp_mail_is_not_taken_as_delivered(): void
    {
        $review = $this->createReview();
        $this->approve($review);

        // A pre_wp_mail filter returning false: wp_mail() returns it and
        // fires neither wp_mail_succeeded nor wp_mail_failed.
        remove_filter('pre_wp_mail', $this->mailCapture, 10);
        $silentFalse = function () {
            return false;
        };
        add_filter('pre_wp_mail', $silentFalse, 10, 2);

        try {
            $this->runApprovalJob($review->id);
            $this->assertNull($this->approvalNoticeState($review->id), 'silence is not success: released');
        } finally {
            remove_filter('pre_wp_mail', $silentFalse, 10);
            add_filter('pre_wp_mail', $this->mailCapture, 10, 2);
        }

        $this->runApprovalJob($review->id);
        $this->assertCount(1, $this->sentMail, 'the next job sends');
        $this->assertSame('sent', $this->approvalNoticeState($review->id));
    }

    /**
     * Spec: Cases/reviews.md [55d]
     *   "Each delivery is recorded before the next notification is attempted, so a worker killed between two sends leaves the first recorded."
     */
    public function test_each_delivery_is_recorded_before_the_next_is_attempted(): void
    {
        $addCopy = function ($notifications) {
            $copy = $notifications['review_approved_customer'];
            $copy['settings']['subject'] = 'COPY for the store owner';
            $notifications['review_approved_customer_copy'] = $copy;
            return $notifications;
        };
        add_filter('fluent_cart/email_notifications', $addCopy, 10, 1);

        $review = $this->createReview();
        $this->approve($review);

        // When the second send begins, the first must already be on the row.
        $recordedWhenCopyWasSent = null;
        remove_filter('pre_wp_mail', $this->mailCapture, 10);
        $observe = function ($null, array $atts) use (&$recordedWhenCopyWasSent, $review) {
            if (strpos((string) $atts['subject'], 'COPY') !== false) {
                $recordedWhenCopyWasSent = ProductReviewService::deliveredApprovalNotices($review->id);
            }
            $this->sentMail[] = $atts;
            do_action('wp_mail_succeeded', $atts);
            return true;
        };
        add_filter('pre_wp_mail', $observe, 10, 2);

        try {
            $this->runApprovalJob($review->id);
        } finally {
            remove_filter('pre_wp_mail', $observe, 10);
            remove_filter('fluent_cart/email_notifications', $addCopy, 10);
            add_filter('pre_wp_mail', $this->mailCapture, 10, 2);
        }

        $this->assertCount(2, $this->sentMail);
        $this->assertSame(['review_approved_customer'], $recordedWhenCopyWasSent, 'the first delivery was on the row before the second was tried');
        $this->assertSame('sent', $this->approvalNoticeState($review->id));
    }

    /**
     * Spec: Cases/reviews.md [55a]
     *   "A send that throws releases the lease on the way out and lets the exception through."
     */
    public function test_a_throwing_send_releases_the_lease_and_rethrows(): void
    {
        $review = $this->createReview();
        $this->approve($review);

        $explode = function () {
            throw new \RuntimeException('mailer filter exploded');
        };
        add_filter('fluent_cart/email_notification/mailer', $explode, 10, 2);

        try {
            $thrown = null;
            try {
                $this->runApprovalJob($review->id);
            } catch (\RuntimeException $e) {
                $thrown = $e;
            }
            $this->assertNotNull($thrown, 'the exception reaches the job runner, which records the failure');
            $this->assertNull($this->approvalNoticeState($review->id), 'and the lease was released on the way out');
        } finally {
            remove_filter('fluent_cart/email_notification/mailer', $explode, 10);
        }

        $this->runApprovalJob($review->id);
        $this->assertCount(1, $this->sentMail, 'a re-run sends');
    }

    /**
     * Spec: Cases/reviews.md [56]
     *   "A switched-off notification does not spend the claim, so switching it on later still tells the reviewer."
     */
    public function test_a_disabled_notification_does_not_spend_the_claim(): void
    {
        $this->configFactory->withNotificationConfig(self::MAIL_NAME, ['active' => 'no']);

        $review = $this->createReview();
        $this->approve($review);
        $this->runApprovalJob($review->id);

        $this->assertNull($this->approvalNoticeState($review->id), 'nothing claimed while the notification is off');
        $this->assertSame([], $this->sentMail);

        // Switched on later: the next approval still tells the reviewer.
        $this->configFactory->reset();
        ProductReviewResource::update(['status' => Status::REVIEW_PENDING], $review->id);
        $this->approve(ProductReview::query()->find($review->id));
        $this->runApprovalJob($review->id);

        $this->assertCount(1, $this->sentMail);
        $this->assertSame('sent', $this->approvalNoticeState($review->id));
    }

    // ------------------------------------------------------------------
    // Who is told
    // ------------------------------------------------------------------

    /**
     * Spec: Cases/reviews.md [57]
     *   "The reviewer's own address is used first; failing that the account's, then the customer's; never the order's."
     */
    public function test_the_recipient_falls_back_from_reviewer_to_account_to_customer(): void
    {
        $userId = WPUserFactory::create(['user_email' => 'account@example.com']);
        $customer = $this->customerFactory->create(['email' => 'customer@example.com']);

        $withAddress = $this->createReview(['reviewer_email' => 'typed@example.com', 'user_id' => $userId, 'customer_id' => $customer->id]);
        $this->assertSame('typed@example.com', ProductReviewService::resolveNotificationRecipient($withAddress));

        $noAddress = $this->createReview(['reviewer_email' => '', 'user_id' => $userId, 'customer_id' => $customer->id]);
        $this->assertSame('account@example.com', ProductReviewService::resolveNotificationRecipient($noAddress));

        $onlyCustomer = $this->createReview(['reviewer_email' => 'not-an-address', 'user_id' => 0, 'customer_id' => $customer->id]);
        $this->assertSame('customer@example.com', ProductReviewService::resolveNotificationRecipient($onlyCustomer), 'a malformed typed address is skipped, not sent to');

        $nobody = $this->createReview(['reviewer_email' => '', 'user_id' => 0, 'customer_id' => 0]);
        $this->assertSame('', ProductReviewService::resolveNotificationRecipient($nobody));
    }

    /**
     * Spec: Cases/reviews.md [58]
     *   "With nobody to send to, nothing is sent and nothing claimed, so a corrected address can be told on the next approval."
     */
    public function test_no_recipient_sends_nothing_and_claims_nothing(): void
    {
        $review = $this->createReview(['reviewer_email' => '', 'user_id' => 0, 'customer_id' => 0]);
        $this->approve($review);

        $this->runApprovalJob($review->id);

        $this->assertSame([], $this->sentMail);
        $this->assertNull($this->approvalNoticeState($review->id), 'not claimed: a corrected address and a fresh approval can still send');

        ProductReviewResource::update(['reviewer_email' => 'fixed@example.com', 'status' => Status::REVIEW_PENDING], $review->id);
        $this->approve(ProductReview::query()->find($review->id));
        $this->runApprovalJob($review->id);

        $this->assertCount(1, $this->sentMail);
        $this->assertSame('fixed@example.com', $this->sentMail[0]['to']);
    }

    // ------------------------------------------------------------------
    // The smartcodes
    // ------------------------------------------------------------------

    /**
     * Spec: Cases/reviews.md [59]
     *   "{{review.*}} reads the review the notification is about, and recipient_email is the same address the resolver gives."
     */
    public function test_review_smartcodes_read_the_review(): void
    {
        $review = $this->createReview(['reviewer_name' => 'Ada', 'rating' => 3, 'title' => 'Fine']);
        $review->load('product');

        $parsed = ShortcodeTemplateBuilder::make(
            '{{review.reviewer_name}}|{{review.rating}}|{{review.title}}|{{review.product_title}}|{{review.recipient_email}}|{{review.rating_stars}}',
            ['review' => $review, 'product' => $review->product]
        );

        $this->assertSame(
            'Ada|3|Fine|' . get_the_title($review->post_id) . '|notice-reviewer@example.com|&#9733;&#9733;&#9733;&#9734;&#9734;',
            $parsed
        );

        // The array form — a stored custom body parsed against serialised
        // data — refuses a malformed address the same way the resolver does.
        $this->assertSame('', ShortcodeTemplateBuilder::make('{{review.recipient_email}}', ['review' => ['reviewer_email' => 'not-an-address']]));
        $this->assertSame('a@example.com', ShortcodeTemplateBuilder::make('{{review.recipient_email}}', ['review' => ['reviewer_email' => ' a@example.com ']]));
    }
}
