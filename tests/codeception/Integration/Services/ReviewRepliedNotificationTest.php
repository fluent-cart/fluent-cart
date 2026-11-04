<?php

namespace Tests\Integration\Services;

use FluentCart\Api\Resource\ProductReviewResource;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Services\ProductReviewService;
use FluentCart\App\Services\ShortCodeParser\ShortcodeTemplateBuilder;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\EmailNotificationConfigFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\WPUserFactory;

/**
 * The reply notice to the reviewer: who gets it, that they get it once, and
 * that a moderator answering their own review is not told about it.
 *
 * Same shape as the approval notice. The event queues a job; the job re-reads
 * the reply and the review it answers and fires fluent_cart/review_replied_done
 * only if both are still published; the mailer sends on that hook behind a
 * once-per-reply claim.
 *
 * Spec: Cases/reviews.md — "Reply notice"
 */
class ReviewRepliedNotificationTest extends WPTestCase
{
    private const MAIL_NAME = 'review_replied_customer';

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

        // What wp_mail() does when the transport accepts the message: it
        // returns true and fires wp_mail_succeeded.
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
            'title'          => 'Reply notice spec',
            'content'        => 'What the customer wrote.',
            'status'         => Status::REVIEW_APPROVED,
            'reviewer_name'  => 'Notice Reviewer',
            'reviewer_email' => 'notice-reviewer@example.com',
        ], $overrides));
        $this->assertFalse(is_wp_error($review), 'fixture review should be created');

        return $review;
    }

    private function createStoreReply(ProductReview $review, array $overrides = []): ProductReview
    {
        $reply = ProductReviewResource::create(array_merge([
            'post_id'        => $review->post_id,
            'parent_id'      => $review->id,
            'content'        => 'What the store answered.',
            'status'         => Status::REVIEW_APPROVED,
            'is_admin_reply' => 1,
            'reviewer_name'  => 'Store Team',
            'reviewer_email' => 'store@example.com',
        ], $overrides));
        $this->assertFalse(is_wp_error($reply), 'fixture reply should be created');

        return $reply;
    }

    /** The queued job, run by hand — what Action Scheduler would do later. */
    private function runReplyJob(int $replyId): void
    {
        do_action('fluent_cart/review_replied_async_private_handle', ['reply_id' => $replyId]);
    }

    private function replyNoticeState(int $replyId)
    {
        $row = ProductReview::query()->find($replyId);

        return $row && is_array($row->other_info) ? ($row->other_info['reply_notice'] ?? null) : null;
    }

    /**
     * Spec: Cases/reviews.md [64]
     *   "The reply notice is claimed on the reply, once; a second reply to the same review is claimed on its own."
     */
    public function test_the_claim_is_per_reply(): void
    {
        $review = $this->createReview();
        $first = $this->createStoreReply($review);
        $second = $this->createStoreReply($review, ['content' => 'A second word.']);

        $this->assertNotNull(ProductReviewService::claimReplyNotice($first));
        $this->assertNull(ProductReviewService::claimReplyNotice($first), 'the same reply, never twice');
        $this->assertNotNull(ProductReviewService::claimReplyNotice($second), 'a different reply is its own notice');
        $this->assertSame('sending', $this->replyNoticeState($first->id), 'a lease, settled after the send');
        $this->assertNull(ProductReview::query()->find($review->id)->other_info, 'the review row itself is untouched');
    }

    /**
     * Spec: Cases/reviews.md [64a]
     *   "A reply's lease left at "sending" past the timeout may be taken over; a fresh one may not; a stale holder cannot settle the lease taken from it."
     */
    public function test_an_abandoned_reply_lease_is_taken_over_and_its_stale_holder_locked_out(): void
    {
        $reply = $this->createStoreReply($this->createReview());

        $staleToken = ProductReviewService::claimReplyNotice($reply);
        $this->assertNotNull($staleToken);
        $this->assertNull(ProductReviewService::claimReplyNotice($reply), 'fresh: still being sent by someone');

        // The holder stalls past the timeout.
        $staleAt = gmdate('Y-m-d H:i:s', time() - ProductReviewService::NOTICE_LEASE_SECONDS - 60);
        ProductReview::query()->where('id', $reply->id)->update([
            'other_info' => wp_json_encode(['reply_notice' => 'sending', 'reply_notice_at' => $staleAt, 'reply_notice_token' => $staleToken]),
        ]);

        $freshToken = ProductReviewService::claimReplyNotice($reply);
        $this->assertNotNull($freshToken, 'abandoned: the next job takes over');
        $this->assertNotSame($staleToken, $freshToken);

        ProductReviewService::settleReplyNotice($reply->id, $staleToken, false);
        $this->assertSame('sending', $this->replyNoticeState($reply->id), 'the stale holder cannot release what it no longer holds');
        ProductReviewService::settleReplyNotice($reply->id, $staleToken, true);
        $this->assertSame('sending', $this->replyNoticeState($reply->id), 'nor close it');

        ProductReviewService::settleReplyNotice($reply->id, $freshToken, true);
        $this->assertSame('sent', $this->replyNoticeState($reply->id));
        $this->assertNull(ProductReviewService::claimReplyNotice($reply), 'delivered is final');
    }

    /**
     * Spec: Cases/reviews.md [65]
     *   "The job sends to the author of the review, not to the author of the reply, and marks the reply's notice sent."
     */
    public function test_the_job_tells_the_reviewer_not_the_replier(): void
    {
        $review = $this->createReview(['reviewer_email' => 'the-author@example.com']);
        $reply = $this->createStoreReply($review, ['reviewer_email' => 'moderator@example.com']);

        $this->runReplyJob($reply->id);

        $this->assertCount(1, $this->sentMail);
        $mail = $this->sentMail[0];
        $this->assertSame('the-author@example.com', $mail['to']);
        $this->assertStringContainsString(get_the_title($review->post_id), $mail['subject']);
        $this->assertStringContainsString('What the store answered.', $mail['message'], 'the reply is in the email');
        $this->assertStringContainsString('What the customer wrote.', $mail['message'], 'so is what it answers');
        $this->assertStringNotContainsString('Order Date', $mail['message'], 'a review is not about an order; no order header');
        $this->assertSame('sent', $this->replyNoticeState($reply->id));
    }

    /**
     * Spec: Cases/reviews.md [66]
     *   "A job that runs twice for the same reply sends once."
     */
    public function test_a_second_job_for_the_same_reply_does_not_send_again(): void
    {
        $reply = $this->createStoreReply($this->createReview());

        $this->runReplyJob($reply->id);
        $this->runReplyJob($reply->id);

        $this->assertCount(1, $this->sentMail);
    }

    /**
     * Spec: Cases/reviews.md [66a]
     *   "A delivery the transport refuses releases the reply's lease; the next job sends."
     */
    public function test_a_refused_delivery_releases_the_lease_for_the_next_job(): void
    {
        $reply = $this->createStoreReply($this->createReview());

        remove_filter('pre_wp_mail', $this->mailCapture, 10);
        $refuse = function () {
            do_action('wp_mail_failed', new \WP_Error('wp_mail_failed', 'transport refused'));
            return false;
        };
        add_filter('pre_wp_mail', $refuse, 10, 2);

        try {
            $this->runReplyJob($reply->id);
            $this->assertNull($this->replyNoticeState($reply->id), 'released, not recorded as sent');
        } finally {
            remove_filter('pre_wp_mail', $refuse, 10);
            add_filter('pre_wp_mail', $this->mailCapture, 10, 2);
        }

        $this->runReplyJob($reply->id);
        $this->assertCount(1, $this->sentMail, 'delivery works again: the next job sends');
        $this->assertSame('sent', $this->replyNoticeState($reply->id));
    }

    /**
     * Spec: Cases/reviews.md [66b]
     *   "With two notifications on the event, a failure on the second does not send the first again on retry."
     */
    public function test_a_failure_on_the_second_notification_does_not_repeat_the_first(): void
    {
        $addCopy = function ($notifications) {
            $copy = $notifications['review_replied_customer'];
            $copy['settings']['subject'] = 'COPY for the store owner';
            $notifications['review_replied_customer_copy'] = $copy;
            return $notifications;
        };
        add_filter('fluent_cart/email_notifications', $addCopy, 10, 1);

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
            $reply = $this->createStoreReply($this->createReview());

            $this->runReplyJob($reply->id);
            $this->assertCount(1, $this->sentMail, 'the first went out, the copy did not');
            $this->assertNull($this->replyNoticeState($reply->id), 'released for a retry');
            $this->assertSame(['review_replied_customer'], ProductReviewService::deliveredReplyNotices($reply->id));

            remove_filter('pre_wp_mail', $refuseCopy, 10);
            add_filter('pre_wp_mail', $this->mailCapture, 10, 2);

            $this->runReplyJob($reply->id);
            $this->assertCount(2, $this->sentMail, 'exactly one more');
            $this->assertStringContainsString('COPY', $this->sentMail[1]['subject'], 'and it is the copy, not the first again');
            $this->assertSame('sent', $this->replyNoticeState($reply->id));
            $this->assertSame([], ProductReviewService::deliveredReplyNotices($reply->id));
        } finally {
            remove_filter('pre_wp_mail', $refuseCopy, 10);
            remove_filter('fluent_cart/email_notifications', $addCopy, 10);
            remove_filter('pre_wp_mail', $this->mailCapture, 10);
            add_filter('pre_wp_mail', $this->mailCapture, 10, 2);
        }
    }

    /**
     * Spec: Cases/reviews.md [67]
     *   "A moderator answering their own review is not told: the reply's author is compared by account, then by address — never by role."
     */
    public function test_the_replier_is_not_told_about_their_own_reply(): void
    {
        $userId = WPUserFactory::create(['user_email' => 'owner@example.com', 'role' => 'administrator']);

        $ownByAccount = $this->createReview(['user_id' => $userId, 'reviewer_email' => 'typed@example.com']);
        $replyByAccount = $this->createStoreReply($ownByAccount, ['user_id' => $userId, 'reviewer_email' => 'owner@example.com']);
        $this->runReplyJob($replyByAccount->id);
        $this->assertSame([], $this->sentMail, 'same account as the reviewer');
        $this->assertNull($this->replyNoticeState($replyByAccount->id), 'and nothing claimed');

        $ownByAddress = $this->createReview(['user_id' => 0, 'reviewer_email' => 'Same@Example.com']);
        $replyByAddress = $this->createStoreReply($ownByAddress, ['user_id' => 0, 'reviewer_email' => 'same@example.com']);
        $this->runReplyJob($replyByAddress->id);
        $this->assertSame([], $this->sentMail, 'same address as the reviewer, case aside');

        // An administrator who is merely a customer of the store is told.
        $customerWhoIsAdmin = $this->createReview(['user_id' => $userId, 'reviewer_email' => 'owner@example.com']);
        $replyByColleague = $this->createStoreReply($customerWhoIsAdmin, ['user_id' => WPUserFactory::create(['role' => 'administrator']), 'reviewer_email' => 'colleague@example.com']);
        $this->runReplyJob($replyByColleague->id);
        $this->assertCount(1, $this->sentMail, 'a different person replied, whatever their role');
        $this->assertSame('owner@example.com', $this->sentMail[0]['to']);
    }

    /**
     * Spec: Cases/reviews.md [68]
     *   "The done hook fires from the job only while both the reply and the review it answers are published store content."
     */
    public function test_the_done_hook_fires_only_while_both_are_published(): void
    {
        $done = 0;
        $listener = function () use (&$done) {
            $done++;
        };
        add_action('fluent_cart/review_replied_done', $listener, 10, 1);

        try {
            $review = $this->createReview();
            $reply = $this->createStoreReply($review);
            $this->runReplyJob($reply->id);
            $this->assertSame(1, $done, 'both published: announced');

            ProductReview::query()->where('id', $reply->id)->update(['status' => Status::REVIEW_PENDING]);
            $this->runReplyJob($reply->id);
            $this->assertSame(1, $done, 'reply held back: not announced');

            ProductReview::query()->where('id', $reply->id)->update(['status' => Status::REVIEW_APPROVED]);
            ProductReview::query()->where('id', $review->id)->update(['status' => Status::REVIEW_PENDING]);
            $this->runReplyJob($reply->id);
            $this->assertSame(1, $done, 'the review it answers held back: not announced');

            $customerFollowUp = $this->createStoreReply($this->createReview(), ['is_admin_reply' => 0]);
            $this->runReplyJob($customerFollowUp->id);
            $this->assertSame(1, $done, 'not a store reply: not announced');

            ProductReview::query()->where('id', $reply->id)->delete();
            $this->runReplyJob($reply->id);
            $this->assertSame(1, $done, 'deleted: not announced');
        } finally {
            remove_action('fluent_cart/review_replied_done', $listener, 10);
        }
    }

    /**
     * Spec: Cases/reviews.md [69]
     *   "A switched-off notification does not spend the claim."
     */
    public function test_a_disabled_notification_does_not_spend_the_claim(): void
    {
        $this->configFactory->withNotificationConfig(self::MAIL_NAME, ['active' => 'no']);
        $reply = $this->createStoreReply($this->createReview());

        $this->runReplyJob($reply->id);

        $this->assertSame([], $this->sentMail);
        $this->assertNull($this->replyNoticeState($reply->id));
    }

    /**
     * Spec: Cases/reviews.md [70]
     *   "With nobody to send to, nothing is sent and nothing claimed."
     */
    public function test_no_recipient_sends_nothing_and_claims_nothing(): void
    {
        $review = $this->createReview(['reviewer_email' => '', 'user_id' => 0, 'customer_id' => 0]);
        $reply = $this->createStoreReply($review);

        $this->runReplyJob($reply->id);

        $this->assertSame([], $this->sentMail);
        $this->assertNull($this->replyNoticeState($reply->id));
    }

    /**
     * Spec: Cases/reviews.md [71]
     *   "{{review.reply_content}} and {{review.replier_name}} read the reply; {{review.*}} otherwise reads the review it answers."
     */
    public function test_reply_smartcodes_read_the_reply_and_the_review(): void
    {
        $review = $this->createReview(['reviewer_name' => 'Ada']);
        $reply = $this->createStoreReply($review, ['reviewer_name' => 'Grace', 'content' => 'Glad it helped.']);
        $review->load('product');

        $parsed = ShortcodeTemplateBuilder::make(
            '{{review.reviewer_name}}|{{review.replier_name}}|{{review.reply_content}}|{{review.recipient_email}}',
            ['reply' => $reply, 'review' => $review, 'product' => $review->product]
        );

        $this->assertSame('Ada|Grace|Glad it helped.|notice-reviewer@example.com', $parsed);
    }
}
