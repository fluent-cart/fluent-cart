<?php

namespace Tests\Integration\Http;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Services\ProductReviewService;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * Combined review filters.
 *
 * Route: GET /public/reviews/{postId}
 *
 * The storefront's star chips can be combined: ratings=5,4 returns fives and
 * fours. With Photos and Verified narrow on top. The single rating param keeps
 * working for older callers.
 */
class ReviewFilterChipsTest extends WPTestCase
{
    /** @var int */
    private $productId;

    public function setUp(): void
    {
        parent::setUp();

        ModuleSettings::saveSettings([
            'reviews' => [
                'active'           => 'yes',
                'reviews_enabled'  => 'yes',
                'reviews_per_page' => 20,
            ],
        ]);
        ModuleSettings::getAllSettings(false);
        ProductReviewService::resetRuntimeCache();

        $product = (new ProductFactory())->create(['post_title' => 'Filtered Widget']);
        $this->productId = (int) $product->ID;

        // rating, has a photo, verified buyer
        $rows = [
            [5, true, true],
            [5, false, false],
            [4, true, false],
            [4, false, true],
            [3, true, true],
            [1, false, false],
        ];

        foreach ($rows as $index => $row) {
            $review = new ProductReview([
                'post_id'       => $this->productId,
                'reviewer_name' => 'Reviewer ' . $index,
                'review'        => 'Review number ' . $index . '.',
                'rating'        => $row[0],
            ]);
            $review->status = Status::REVIEW_APPROVED;
            $review->media_count = $row[1] ? 1 : 0;
            $review->is_verified = $row[2] ? 1 : 0;
            $review->save();
        }
    }

    public function tearDown(): void
    {
        ProductReviewService::resetRuntimeCache();
        parent::tearDown();
    }

    /**
     * @param array $params
     * @return array{0: int, 1: int[]} status, the ratings returned, sorted high to low
     */
    private function fetch(array $params): array
    {
        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/public/reviews/' . $this->productId);

        // Every filter is sent: the framework's request object outlives one
        // rest_do_request(), so a param left out would keep the last call's value.
        $params = array_merge(['ratings' => '', 'rating' => 0, 'has_media' => 0, 'verified_only' => 0], $params);

        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        $response = rest_do_request($request);
        $body = (array) $response->get_data();
        $ratings = array_map(function ($review) {
            return (int) $review['rating'];
        }, (array) ($body['reviews']['data'] ?? []));
        rsort($ratings);

        return [$response->get_status(), $ratings];
    }

    public function test_twoStarChipsReturnBothRatings(): void
    {
        list($status, $ratings) = $this->fetch(['ratings' => '5,4']);

        $this->assertSame(200, $status);
        $this->assertSame([5, 5, 4, 4], $ratings);
    }

    public function test_photosNarrowTheChosenStars(): void
    {
        list(, $ratings) = $this->fetch(['ratings' => '5,4', 'has_media' => 1]);

        $this->assertSame([5, 4], $ratings);
    }

    public function test_starsPhotosAndVerifiedTogether(): void
    {
        list(, $ratings) = $this->fetch(['ratings' => '5,4,3', 'has_media' => 1, 'verified_only' => 1]);

        $this->assertSame([5, 3], $ratings);
    }

    public function test_theSingleRatingParamStillWorks(): void
    {
        list($status, $ratings) = $this->fetch(['rating' => 4]);

        $this->assertSame(200, $status);
        $this->assertSame([4, 4], $ratings);
    }

    public function test_aStarListWinsOverTheSingleRating(): void
    {
        list(, $ratings) = $this->fetch(['ratings' => '1', 'rating' => 5]);

        $this->assertSame([1], $ratings);
    }

    /**
     * Junk in the list is dropped, not turned into a filter that hides everything.
     */
    public function test_invalidStarsAreIgnored(): void
    {
        list($status, $ratings) = $this->fetch(['ratings' => '9,x,0']);

        $this->assertSame(200, $status);
        $this->assertSame([5, 5, 4, 4, 3, 1], $ratings);

        list(, $ratings) = $this->fetch(['ratings' => '9,3']);
        $this->assertSame([3], $ratings, 'the valid star in a mixed list still applies');
    }

    public function test_ratingListParsing(): void
    {
        $this->assertSame([5, 4], ProductReviewService::ratingList('5,4'));
        $this->assertSame([5, 4], ProductReviewService::ratingList('5, 4, 5'), 'duplicates collapse');
        $this->assertSame([3], ProductReviewService::ratingList([3, '9', '-1', '2.5']));
        $this->assertSame([], ProductReviewService::ratingList(''));
        $this->assertSame([], ProductReviewService::ratingList(null));
        $this->assertSame([1, 2, 3, 4, 5], ProductReviewService::ratingList('1,2,3,4,5,1,2,3'), 'never more than five');
    }
}
