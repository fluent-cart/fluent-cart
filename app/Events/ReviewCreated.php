<?php

namespace FluentCart\App\Events;

use FluentCart\App\Models\ProductReview;

class ReviewCreated extends EventDispatcher
{
    public string $hook = 'fluent_cart/review_created';

    protected array $listeners = [];

    public ProductReview $review;

    public function __construct(ProductReview $review)
    {
        $this->review = $review;
    }

    public function toArray(): array
    {
        $this->review->load('product');

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
}
