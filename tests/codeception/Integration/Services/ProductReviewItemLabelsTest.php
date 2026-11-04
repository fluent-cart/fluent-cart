<?php

namespace Tests\Integration\Services;

use FluentCart\Api\Resource\ProductReviewResource;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Services\ProductReviewService;
use Tests\Support\Factory\CustomerFactory;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * The label a review's item is shown under, resolved for a page of reviews
 * at once by ProductReviewService::attachItemLabels().
 *
 * Spec: Cases/reviews.md — "Item labels on display"
 */
class ProductReviewItemLabelsTest extends WPTestCase
{
    private function createReview(array $overrides = [])
    {
        $review = ProductReviewResource::create(array_merge([
            'parent_id'      => 0,
            'rating'         => 4,
            'title'          => 'Label spec',
            'content'        => 'Which item is this about?',
            'status'         => Status::REVIEW_APPROVED,
            'is_verified'    => 0,
            'is_admin_reply' => 0,
            'user_id'        => 0,
            'reviewer_name'  => 'Spec Reviewer',
            'reviewer_email' => 'label-spec@example.com',
        ], $overrides));

        $this->assertFalse(is_wp_error($review), 'fixture review should be created');

        return ProductReview::query()->find($review->id);
    }

    /**
     * Spec: Cases/reviews.md [34]
     *   "A review's item name is the variation's current name: a rename shows the new name, a deleted variation shows nothing."
     */
    public function test_the_item_name_follows_the_variation_like_the_product_name_follows_the_post(): void
    {
        $product = (new ProductFactory())->createWithVariants([['variation_title' => 'Red']]);
        $red = $product->variants->first();

        $review = $this->createReview(['post_id' => $product->ID, 'item_id' => $red->id]);

        ProductReviewService::attachItemLabels([$review]);
        $this->assertSame('Red', $review->getAttribute('item_label'));

        ProductVariation::query()->where('id', $red->id)->update(['variation_title' => 'Crimson']);
        ProductReviewService::attachItemLabels([$review]);
        $this->assertSame('Crimson', $review->getAttribute('item_label'), 'A rename shows the new name, as a product rename would');

        ProductVariation::query()->where('id', $red->id)->delete();
        ProductReviewService::attachItemLabels([$review]);
        $this->assertSame('', $review->getAttribute('item_label'), 'A deleted variation has no name to show');
    }

    /**
     * Spec: Cases/reviews.md [35]
     *   "One variation query serves a whole page of reviews, however many rows name an item."
     */
    public function test_one_query_names_every_item_on_a_page(): void
    {
        global $wpdb;

        $product = (new ProductFactory())->createWithVariants([
            ['variation_title' => 'Small'],
            ['variation_title' => 'Large'],
        ]);
        $small = $product->variants->firstWhere('variation_title', 'Small');
        $large = $product->variants->firstWhere('variation_title', 'Large');

        $reviews = [
            $this->createReview(['post_id' => $product->ID, 'item_id' => $small->id, 'reviewer_email' => 'a@example.com']),
            $this->createReview(['post_id' => $product->ID, 'item_id' => $large->id, 'reviewer_email' => 'b@example.com']),
            $this->createReview(['post_id' => $product->ID, 'item_id' => $small->id, 'reviewer_email' => 'c@example.com']),
        ];

        $before = (int) $wpdb->num_queries;
        ProductReviewService::attachItemLabels($reviews);
        $this->assertSame(1, (int) $wpdb->num_queries - $before, 'One variation lookup for the whole page, however many rows');

        $this->assertSame('Small', $reviews[0]->getAttribute('item_label'));
        $this->assertSame('Large', $reviews[1]->getAttribute('item_label'));
        $this->assertSame('Small', $reviews[2]->getAttribute('item_label'));
    }

    /**
     * Spec: Cases/reviews.md [36]
     *   "A product-level review carries an empty item label, never a dash or a stray title."
     */
    public function test_a_product_level_review_has_an_empty_label_and_costs_no_query(): void
    {
        global $wpdb;

        $product = (new ProductFactory())->create();
        $review = $this->createReview(['post_id' => $product->ID]);

        $before = (int) $wpdb->num_queries;
        ProductReviewService::attachItemLabels([$review]);

        $this->assertSame('', $review->getAttribute('item_label'));
        $this->assertSame($before, (int) $wpdb->num_queries, 'Nothing to resolve, nothing queried');
        $this->assertArrayHasKey('item_label', $review->toArray(), 'The key is always present so templates test one thing');
    }

    /**
     * Spec: Cases/reviews.md [37]
     *   "A customer's own review history names the item each review is about, from the variation row."
     */
    public function test_the_customer_history_carries_the_item_label(): void
    {
        $product = (new ProductFactory())->createWithVariants([['variation_title' => 'Blue']]);
        $blue = $product->variants->first();
        $customer = (new CustomerFactory())->create(['email' => 'history@example.com']);

        $this->createReview([
            'post_id'        => $product->ID,
            'item_id'        => $blue->id,
            'user_id'        => (int) $customer->user_id,
            'customer_id'    => (int) $customer->id,
            'reviewer_email' => 'history@example.com',
        ]);
        $this->createReview(['post_id' => $product->ID, 'reviewer_email' => 'history@example.com']);

        $payload = ProductReviewService::getCustomerReviewsPayload($customer, ['page' => 1, 'per_page' => 10]);
        $rows = $payload['reviews']['data'] ?? [];

        $labels = [];
        foreach ($rows as $row) {
            $labels[(int) $row['item_id']] = $row['item_label'];
        }

        $this->assertArrayHasKey((int) $blue->id, $labels);
        $this->assertSame('Blue', $labels[(int) $blue->id], 'The history row names the item, read from the variation');
        $this->assertArrayHasKey(0, $labels);
        $this->assertSame('', $labels[0], 'A product-level review carries an empty label');
    }
}
