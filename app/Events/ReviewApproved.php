<?php

namespace FluentCart\App\Events;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\ProductReview;

/**
 * A review became approved — fluent_cart/review_approved.
 *
 * Fired once per transition into the approved status, on every path that can
 * make one: a moderator approving a single review, a bulk approve, a review
 * born approved because the store auto-approves, and a moderator writing one
 * by hand. Never for a reply: a reply is the store's own words and has no
 * moderation state worth announcing.
 *
 * It is the transition that fires, not the state. A review approved, held
 * back and approved again fires twice, and that is correct for an event —
 * the notification built on it is what has to send once, and it keeps its
 * own claim for that (see ProductReviewService::claimApprovalNotice()).
 */
class ReviewApproved extends EventDispatcher
{
    public string $hook = 'fluent_cart/review_approved';

    protected array $listeners = [];

    public ProductReview $review;

    public function __construct(ProductReview $review)
    {
        $this->review = $review;
    }

    /**
     * Dispatch for a review that has just been written or transitioned, if it
     * is one this event is about. Every dispatch site asks the same question,
     * so the question is asked here.
     *
     * @param ProductReview $review the persisted row, as saved
     * @param string|null $previousStatus the status before this write; null for a new row
     */
    public static function dispatchIfApproved(ProductReview $review, $previousStatus = null): void
    {
        if ($review->parent_id) {
            return;
        }

        if ($review->status !== Status::REVIEW_APPROVED) {
            return;
        }

        if ($previousStatus === Status::REVIEW_APPROVED) {
            return;
        }

        (new static($review))->dispatch();
    }

    public function toArray(): array
    {
        // Only if the caller did not bring it: a bulk approve eager-loads the
        // product for the whole batch, and fifty dispatches must not turn
        // that into fifty lookups.
        $this->review->loadMissing('product');

        return [
            'review'  => $this->review,
            'product' => $this->review->product,
        ];
    }

    public function getActivityEventModel()
    {
        return $this->review;
    }

    public function shouldCreateActivity(): bool
    {
        return false;
    }

    /**
     * The settled half of the approval, off the request.
     *
     * The hook this event fires is the transition, as it happened. What most
     * consumers want is the transition once the dust has settled — the media
     * hooks done, the row re-read, the review still approved — and that is
     * fluent_cart/review_approved_done, fired by the job queued here. The
     * same split as order_paid and order_paid_done: the event says it
     * happened, the job says it stuck.
     *
     * 3rd party devs: hang integrations on fluent_cart/review_approved_done,
     * not on this event's own hook.
     */
    public function afterDispatch()
    {
        if (!function_exists('as_enqueue_async_action')) {
            return;
        }

        as_enqueue_async_action('fluent_cart/review_approved_async_private_handle', [
            [
                'review_id' => (int) $this->review->id,
            ],
        ], 'fluent-cart');
    }
}
