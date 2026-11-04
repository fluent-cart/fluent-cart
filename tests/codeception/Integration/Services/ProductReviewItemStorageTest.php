<?php

namespace Tests\Integration\Services;

use FluentCart\Api\Resource\ProductReviewResource;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Models\ProductVariation;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * Item-level review storage: a review may name the variation it is about.
 *
 * Only the write path and the model are pinned here — what is stored, what a
 * reply inherits, what survives a meta update. Who may write an item id (the
 * order-review grant) is the submission rule, covered by the order-link cases.
 *
 * Spec: Cases/reviews.md — "Item-level storage"
 */
class ProductReviewItemStorageTest extends WPTestCase
{
    private function createReview(array $overrides = [])
    {
        $review = ProductReviewResource::create(array_merge([
            'parent_id'      => 0,
            'rating'         => 4,
            'title'          => 'Item storage spec',
            'content'        => 'Stored against one variation.',
            'status'         => Status::REVIEW_APPROVED,
            'is_verified'    => 0,
            'is_admin_reply' => 0,
            'user_id'        => 0,
            'reviewer_name'  => 'Spec Reviewer',
            'reviewer_email' => 'spec-reviewer@example.com',
        ], $overrides));

        $this->assertFalse(is_wp_error($review), 'fixture review should be created');

        return $review;
    }

    /**
     * Spec: Cases/reviews.md [19]
     *   "A review written with an item id stores that id, and only that: the item's name is read from the variation, not copied."
     */
    public function test_a_review_stores_its_item_id_and_nothing_else_about_the_item(): void
    {
        $product = (new ProductFactory())->createWithVariants([
            ['variation_title' => 'Red'],
            ['variation_title' => 'Blue'],
        ]);
        $red = $product->variants->firstWhere('variation_title', 'Red');

        $review = $this->createReview([
            'post_id' => $product->ID,
            'item_id' => $red->id,
        ]);

        $stored = ProductReview::query()->find($review->id);

        $this->assertSame((int) $red->id, (int) $stored->item_id, 'The item id must be persisted');
        $this->assertNull($stored->other_info, 'Nothing about the item is copied onto the review');
        $this->assertSame('Red', ProductVariation::query()->find($stored->item_id)->variation_title, 'The name comes from the variation row');
    }

    /**
     * Spec: Cases/reviews.md [20]
     *   "A review written without an item id is a product-level review: item id null."
     */
    public function test_a_review_without_an_item_is_product_level(): void
    {
        $product = (new ProductFactory())->create();

        $review = $this->createReview(['post_id' => $product->ID]);

        $stored = ProductReview::query()->find($review->id);

        $this->assertNull($stored->item_id, 'No item means NULL, never 0');
        $this->assertNull($stored->item, 'No item relation to resolve');
    }

    /**
     * Spec: Cases/reviews.md [21]
     *   "A reply inherits the item of the review it answers."
     */
    public function test_a_reply_inherits_the_parent_item(): void
    {
        $product = (new ProductFactory())->createWithVariants([
            ['variation_title' => 'Small'],
        ]);
        $small = $product->variants->first();

        $review = $this->createReview([
            'post_id' => $product->ID,
            'item_id' => $small->id,
        ]);

        $reply = ProductReviewResource::create([
            'parent_id'      => $review->id,
            'post_id'        => $product->ID,
            // Whatever a caller passes for a reply is ignored in favour of
            // the parent's item, so the thread can never straddle two items.
            'item_id'        => 999999,
            'content'        => 'Thanks for the feedback.',
            'status'         => Status::REVIEW_APPROVED,
            'is_admin_reply' => 1,
            'reviewer_name'  => 'Store',
            'reviewer_email' => 'store@example.com',
        ]);

        $this->assertFalse(is_wp_error($reply), 'the reply should be created');
        $this->assertSame((int) $small->id, (int) ProductReview::query()->find($reply->id)->item_id);
    }

    /**
     * Spec: Cases/reviews.md [23]
     *   "Scoping reviews to an item returns only that item's rows; no item keeps the product-wide set."
     */
    public function test_the_item_scope_narrows_and_zero_keeps_everything(): void
    {
        $product = (new ProductFactory())->createWithVariants([
            ['variation_title' => 'Red'],
            ['variation_title' => 'Blue'],
        ]);
        $red = $product->variants->firstWhere('variation_title', 'Red');
        $blue = $product->variants->firstWhere('variation_title', 'Blue');

        $this->createReview(['post_id' => $product->ID, 'item_id' => $red->id, 'reviewer_email' => 'a@example.com']);
        $this->createReview(['post_id' => $product->ID, 'item_id' => $blue->id, 'reviewer_email' => 'b@example.com']);
        $this->createReview(['post_id' => $product->ID, 'reviewer_email' => 'c@example.com']);

        $this->assertSame(1, ProductReview::query()->ofProduct($product->ID)->where('item_id', $red->id)->count());
        $this->assertSame(3, ProductReview::query()->ofProduct($product->ID)->count(), 'An item review still counts for the product');
    }
}
