<?php

namespace FluentCart\App\Events;

use FluentCart\App\Models\ProductReview;

/**
 * The store replied to a review — fluent_cart/review_replied.
 *
 * Fired once per store reply, from the two places that write one: a moderator
 * answering a single review, and a bulk reply. Only after the reply's
 * transaction has committed — a listener that sends mail inside a transaction
 * that later rolls back announces a reply that does not exist. Never for a
 * customer's own follow-up: that is not the store speaking.
 *
 * As with approval, this is the event as it happened. The job it queues
 * re-reads the reply and its review and fires fluent_cart/review_replied_done
 * only if both are still published — the hook the reviewer's email, and any
 * integration, should hang on.
 */
class ReviewReplied extends EventDispatcher
{
    public string $hook = 'fluent_cart/review_replied';

    protected array $listeners = [];

    public ProductReview $reply;

    /** @var ProductReview|null the review being answered, when the caller already holds it */
    protected ?ProductReview $review;

    /** @var mixed the product, when the caller already holds it */
    protected $product;

    /**
     * @param ProductReview $reply the store's reply
     * @param ProductReview|null $review the review it answers, if already loaded
     * @param mixed $product the product, if already loaded
     */
    public function __construct(ProductReview $reply, ?ProductReview $review = null, $product = null)
    {
        $this->reply = $reply;
        $this->review = $review;
        $this->product = $product;
    }

    /**
     * Dispatch for a row that has just been written, if it is a store reply.
     * Every dispatch site asks the same question, so it is asked here.
     *
     * The writers of a reply hold its review and product already — the
     * single reply loaded the parent to answer it, the bulk reply loaded
     * every parent it was given. Passed in, so a batch of fifty does not
     * make a hundred queries to look up what the caller just had.
     *
     * @param ProductReview $reply the persisted row, as saved and committed
     * @param ProductReview|null $review the review it answers, if already loaded
     * @param mixed $product the product, if already loaded
     */
    public static function dispatchIfStoreReply(ProductReview $reply, ?ProductReview $review = null, $product = null): void
    {
        if (!$reply->parent_id || !$reply->is_admin_reply) {
            return;
        }

        (new static($reply, $review, $product))->dispatch();
    }

    public function toArray(): array
    {
        // What the caller handed over is used as is; only what it did not is
        // looked up, and once.
        if (!$this->review) {
            $this->review = ProductReview::query()->find((int) $this->reply->parent_id);
        }

        if (!$this->product) {
            $this->product = $this->review ? $this->review->product : null;
        }

        return [
            'reply'   => $this->reply,
            // The review being answered — whose author is the one to tell.
            'review'  => $this->review,
            'product' => $this->product,
        ];
    }

    public function getActivityEventModel()
    {
        return $this->reply;
    }

    public function shouldCreateActivity(): bool
    {
        return false;
    }

    /**
     * The settled half, off the request — see ReviewApproved::afterDispatch()
     * for the shape, and fluent_cart/review_replied_done for the hook that
     * consumers should use.
     */
    public function afterDispatch()
    {
        if (!function_exists('as_enqueue_async_action')) {
            return;
        }

        as_enqueue_async_action('fluent_cart/review_replied_async_private_handle', [
            [
                'reply_id' => (int) $this->reply->id,
            ],
        ], 'fluent-cart');
    }
}
