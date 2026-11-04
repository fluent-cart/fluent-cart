<?php

namespace Tests\Integration\Services\Filter;

use FluentCart\Api\ModuleSettings;
use FluentCart\Api\Resource\ProductReviewResource;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\Filter\ProductFilter;
use FluentCart\App\Services\ProductReviewService;
use FluentCart\Framework\Support\Arr;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * The Reviews group in the products list's advanced filter: Reviews,
 * Written Reviews and Average Rating.
 *
 * BaseFilter::applyAdvancedFilter() returns early while Pro is inactive, and
 * this suite runs without Pro, so each test takes the option's callback from
 * advanceFilterOptions() and runs it against the products query — the same
 * call applyAdvancedFilter() makes.
 *
 * The average follows recalculateProductRatings(): approved top-level
 * reviews rated 1–5, rounded to two places, so a product matches exactly
 * when the stars the Reviews column shows say it should.
 */
class ProductReviewFilterTest extends WPTestCase
{
    private function setReviewsActive(string $value): void
    {
        $modules = ModuleSettings::getAllSettings();
        $modules['reviews']['active'] = $value;
        ModuleSettings::saveSettings($modules);
        ModuleSettings::getAllSettings(false);
        ProductReviewService::resetRuntimeCache();
    }

    public function tearDown(): void
    {
        $this->setReviewsActive('yes');
        parent::tearDown();
    }

    private function createReview(int $postId, array $overrides = []): void
    {
        $review = ProductReviewResource::create(array_merge([
            'post_id'        => $postId,
            'parent_id'      => 0,
            'rating'         => 5,
            'title'          => 'Filter spec',
            'content'        => 'Written words.',
            'status'         => Status::REVIEW_APPROVED,
            'is_verified'    => 0,
            'is_admin_reply' => 0,
            'user_id'        => 0,
            'reviewer_name'  => 'Filter Spec',
            'reviewer_email' => 'filter-spec@example.com',
        ], $overrides));

        $this->assertFalse(is_wp_error($review), 'fixture review should be created');
    }

    private function reviewOption(string $property): array
    {
        $options = ProductFilter::advanceFilterOptions();
        $this->assertArrayHasKey('reviews', $options, 'the Reviews group is offered while the module is on');

        foreach (Arr::get($options, 'reviews.children', []) as $child) {
            if ($child['value'] === $property) {
                return $child;
            }
        }

        $this->fail("no `{$property}` option in the Reviews group");
    }

    /**
     * IDs out of $candidates that the option matches with this operator and value.
     */
    private function matching(string $property, string $operator, string $value, array $candidates): array
    {
        $option = $this->reviewOption($property);

        $query = ProductFilter::make([])->query();
        $query->whereIn('ID', $candidates);
        $option['callback']($query, [
            'property'    => $property,
            'operator'    => $operator,
            'value'       => $value,
            'filter_type' => 'custom',
        ]);

        $ids = array_map('intval', $query->pluck('ID')->toArray());
        sort($ids);

        return $ids;
    }

    public function test_has_reviews_follows_the_chosen_status(): void
    {
        $approved = (new ProductFactory())->create()->ID;
        $pendingOnly = (new ProductFactory())->create()->ID;
        $none = (new ProductFactory())->create()->ID;

        $this->createReview($approved);
        $this->createReview($pendingOnly, ['status' => Status::REVIEW_PENDING]);

        $all = [$approved, $pendingOnly, $none];

        $this->assertSame([$approved], $this->matching('review_status', '=', 'approved', $all));
        $this->assertSame([$pendingOnly], $this->matching('review_status', '=', 'not_approved', $all));
        $this->assertSame([$approved, $pendingOnly], $this->matching('review_status', '=', 'any', $all));

        $this->assertSame(
            [$pendingOnly, $none],
            $this->matching('review_status', '!=', 'approved', $all),
            '"Has no approved reviews" includes the product that has none at all'
        );
        $this->assertSame([$none], $this->matching('review_status', '!=', 'any', $all));
    }

    public function test_a_reply_is_not_a_review(): void
    {
        $product = (new ProductFactory())->create()->ID;

        $this->createReview($product, ['status' => Status::REVIEW_PENDING]);
        $parent = \FluentCart\App\Models\ProductReview::query()->where('post_id', $product)->first();

        // An approved store reply on a pending review: the product still has
        // no approved review, written or otherwise.
        $this->createReview($product, [
            'parent_id'      => $parent->id,
            'rating'         => 0,
            'content'        => 'Thanks for the feedback.',
            'is_admin_reply' => 1,
        ]);

        $this->assertSame(
            1,
            \FluentCart\App\Models\ProductReview::query()->where('post_id', $product)->whereNotNull('parent_id')->count(),
            'fixture: the reply is stored as a reply, not a second review'
        );

        $this->assertSame([], $this->matching('review_status', '=', 'approved', [$product]));
        $this->assertSame([], $this->matching('written_review', '=', 'approved', [$product]));
        $this->assertSame([], $this->matching('average_rating', '>=', '1', [$product]));
    }

    public function test_written_reviews_need_text(): void
    {
        $written = (new ProductFactory())->create()->ID;
        $starsOnly = (new ProductFactory())->create()->ID;
        $pendingWritten = (new ProductFactory())->create()->ID;

        $this->createReview($written);
        $this->createReview($starsOnly, ['content' => '', 'title' => '']);
        $this->createReview($pendingWritten, ['status' => Status::REVIEW_PENDING]);

        $all = [$written, $starsOnly, $pendingWritten];

        $this->assertSame([$written], $this->matching('written_review', '=', 'approved', $all));
        $this->assertSame([$pendingWritten], $this->matching('written_review', '=', 'not_approved', $all));
        $this->assertSame([$written, $pendingWritten], $this->matching('written_review', '=', 'any', $all));
        $this->assertSame([$starsOnly], $this->matching('written_review', '!=', 'any', $all));
    }

    public function test_average_rating_matches_the_cached_average(): void
    {
        $fourAndHalf = (new ProductFactory())->create()->ID;
        $two = (new ProductFactory())->create()->ID;
        $unrated = (new ProductFactory())->create()->ID;
        $pendingFive = (new ProductFactory())->create()->ID;

        $this->createReview($fourAndHalf, ['rating' => 5]);
        $this->createReview($fourAndHalf, ['rating' => 4]);
        // An unrated review counts toward review_count but not the average.
        $this->createReview($fourAndHalf, ['rating' => 0]);
        $this->createReview($two, ['rating' => 2]);
        $this->createReview($unrated, ['rating' => 0]);
        $this->createReview($pendingFive, ['rating' => 5, 'status' => Status::REVIEW_PENDING]);

        $all = [$fourAndHalf, $two, $unrated, $pendingFive];

        $this->assertSame(
            [$fourAndHalf, $two],
            $this->matching('average_rating', '>=', '1', $all),
            'the default, 1 star and up, is every product with a rating; unrated and pending-only products have none'
        );
        $this->assertSame([$fourAndHalf], $this->matching('average_rating', '>=', '4', $all));
        $this->assertSame([], $this->matching('average_rating', '>=', '5', $all), '4.5 is not 5');
        $this->assertSame([$two], $this->matching('average_rating', '<=', '2', $all));
    }

    public function test_an_unknown_value_or_operator_changes_nothing(): void
    {
        $reviewed = (new ProductFactory())->create()->ID;
        $plain = (new ProductFactory())->create()->ID;
        $this->createReview($reviewed);

        $all = [$reviewed, $plain];

        $this->assertSame($all, $this->matching('review_status', '=', 'bogus', $all));
        $this->assertSame($all, $this->matching('review_status', 'like', 'approved', $all));
        $this->assertSame($all, $this->matching('average_rating', '>=', '9', $all));
        $this->assertSame($all, $this->matching('average_rating', '=1; --', '1', $all));
    }

    public function test_the_options_carry_their_defaults_and_labels(): void
    {
        $average = $this->reviewOption('average_rating');
        $this->assertSame('1', $average['default_value'], 'Average Rating starts at 1 star');
        $this->assertSame(['>=', '<='], array_keys($average['operators']));
        $this->assertSame(['1', '2', '3', '4', '5'], array_map('strval', array_keys($average['options'])));

        foreach (['review_status', 'written_review'] as $property) {
            $option = $this->reviewOption($property);
            $this->assertSame('custom', $option['filter_type']);
            $this->assertSame(['=', '!='], array_keys($option['operators']));
            $this->assertSame(['approved', 'not_approved', 'any'], array_keys($option['options']));
            $this->assertSame('approved', $option['default_value']);
        }
    }

    public function test_the_group_leaves_while_the_module_is_off(): void
    {
        $this->setReviewsActive('no');

        $this->assertArrayNotHasKey('reviews', ProductFilter::advanceFilterOptions());
    }
}
