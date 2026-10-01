<?php

namespace FluentCart\App\Services\ShortCodeParser\Parsers;

use FluentCart\App\Models\ProductReview;
use FluentCart\App\Services\ProductReviewService;
use FluentCart\Framework\Support\Arr;

/**
 * {{review.*}} — the review a notification is about.
 *
 * The other parsers all reach their subject through an order. A review has no
 * order to reach through: it can be written before one, after one, or without
 * one at all, so its own row is the only thing the email may read from. That
 * is also why review notifications declare their own `to` — the built-in
 * customer recipient resolves to {{order.customer.email}}, which is nothing
 * here — and why `recipient_email` goes through the same resolver every
 * review notification uses, so no two emails can disagree about who wrote it.
 *
 * Handed either the model or its array form: the mailer passes models through
 * to the template parser, but a stored custom body can be parsed against
 * whatever a caller serialised.
 */
class ReviewParser extends BaseParser
{
    /** @var ProductReview|array|null */
    private $review;

    /** @var mixed */
    private $product;

    /** @var ProductReview|array|null the store's reply, when the email is about one */
    private $reply;

    public function __construct($data)
    {
        $this->review = Arr::get($data, 'review');
        $this->product = Arr::get($data, 'product');
        $this->reply = Arr::get($data, 'reply');
        parent::__construct($data);
    }

    public function parse($accessor = null, $code = null, $transformer = null): ?string
    {
        $review = $this->review;

        if (!$review) {
            return '';
        }

        switch ($accessor) {
            case 'reviewer_name':
                return esc_html($this->attributeOf($review, 'reviewer_name'));
            case 'reviewer_email':
                return esc_html($this->attributeOf($review, 'reviewer_email'));
            case 'recipient_email':
                // Raw, not escaped: this is an address for the To header. A
                // model goes through the resolver, which refuses anything
                // is_email() would; an array form gets the same refusal.
                if ($review instanceof ProductReview) {
                    return ProductReviewService::resolveNotificationRecipient($review);
                }
                $address = trim($this->attributeOf($review, 'reviewer_email'));
                return $address !== '' && is_email($address) ? $address : '';
            case 'title':
                return esc_html($this->attributeOf($review, 'title'));
            case 'content':
                // `review` is the column and is hidden from the array form;
                // `content` is the accessor and is appended to it. Read the
                // one that is present in both shapes.
                return wp_kses_post($this->attributeOf($review, 'content'));
            case 'rating':
                return (string) (int) $this->attributeOf($review, 'rating');
            case 'rating_stars':
                return $this->ratingStars((int) $this->attributeOf($review, 'rating'));
            case 'status':
                return esc_html($this->attributeOf($review, 'status'));
            case 'product_title':
                return esc_html($this->productTitle());
            case 'product_url':
                return esc_url($this->productUrl());
            case 'reply_content':
                return $this->reply ? wp_kses_post($this->attributeOf($this->reply, 'content')) : '';
            case 'replier_name':
                return $this->reply ? esc_html($this->attributeOf($this->reply, 'reviewer_name')) : '';
            default:
                // Unknown accessor: null hands the code to the fallback filter
                // rather than printing an empty string over a typo.
                return null;
        }
    }

    /**
     * One field, off a model or an array.
     *
     * @param ProductReview|array $source
     * @param string $key
     * @return string
     */
    private function attributeOf($source, string $key): string
    {
        if (is_object($source)) {
            return (string) ($source->{$key} ?? '');
        }

        return (string) Arr::get((array) $source, $key, '');
    }

    private function productTitle(): string
    {
        $title = $this->attributeOf($this->product ?: [], 'post_title');

        if ($title === '') {
            $postId = (int) $this->attributeOf($this->review, 'post_id');
            $title = $postId ? (string) get_post_field('post_title', $postId, 'raw') : '';
        }

        return $title;
    }

    private function productUrl(): string
    {
        $postId = (int) ($this->attributeOf($this->product ?: [], 'ID') ?: $this->attributeOf($this->review, 'post_id'));

        return $postId ? (string) get_permalink($postId) : '';
    }

    /**
     * Five stars, the given number filled. Plain text entities rather than an
     * image, so it survives every mail client's image blocking.
     */
    private function ratingStars(int $rating): string
    {
        $rating = max(0, min(5, $rating));

        return str_repeat('&#9733;', $rating) . str_repeat('&#9734;', 5 - $rating);
    }
}
