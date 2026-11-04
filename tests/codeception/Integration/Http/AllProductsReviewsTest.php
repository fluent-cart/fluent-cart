<?php

namespace Tests\Integration\Http;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Hooks\Handlers\ShortCodes\ProductReviewsShortCode;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Services\ProductReviewService;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * The store-wide review list: GET /public/reviews/all and [fluent_cart_product_reviews scope="all"].
 *
 * Reviews from every published product that takes reviews, optionally narrowed by
 * product or category.
 */
class AllProductsReviewsTest extends WPTestCase
{
    /** @var int */
    private $shoeProductId;

    /** @var int */
    private $hatProductId;

    /** @var int */
    private $footwearCategoryId;

    /** @var int[] every product this test made, published or not */
    private $productIds = [];

    /** @var array<string, int> review ids by label */
    private $reviewIdsByLabel = [];

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

        $this->shoeProductId = (int) (new ProductFactory())->create(['post_title' => 'Trail Shoe'])->ID;
        $this->hatProductId = (int) (new ProductFactory())->create(['post_title' => 'Sun Hat'])->ID;
        $draftProductId = (int) (new ProductFactory())->create(['post_title' => 'Draft Coat', 'post_status' => 'draft'])->ID;
        $reviewsOffProductId = (int) (new ProductFactory())
            ->withDetailAttributes(['other_info' => ['reviews_enabled' => 'no']])
            ->create(['post_title' => 'Closed Scarf'])->ID;

        $this->productIds = [$this->shoeProductId, $this->hatProductId, $draftProductId, $reviewsOffProductId];

        $term = wp_insert_term('Footwear', 'product-categories');
        $this->footwearCategoryId = (int) $term['term_id'];
        wp_set_object_terms($this->shoeProductId, [$this->footwearCategoryId], 'product-categories');

        $this->reviewIdsByLabel['shoe5'] = $this->createReview($this->shoeProductId, 5);
        $this->reviewIdsByLabel['shoe2'] = $this->createReview($this->shoeProductId, 2);
        $this->reviewIdsByLabel['hat4'] = $this->createReview($this->hatProductId, 4);
        $this->reviewIdsByLabel['hatPending'] = $this->createReview($this->hatProductId, 5, Status::REVIEW_PENDING);
        $this->reviewIdsByLabel['draft5'] = $this->createReview($draftProductId, 5);
        $this->reviewIdsByLabel['closed5'] = $this->createReview($reviewsOffProductId, 5);
    }

    public function tearDown(): void
    {
        ProductReviewService::resetRuntimeCache();
        parent::tearDown();
    }

    private function createReview(int $postId, int $rating, string $status = Status::REVIEW_APPROVED): int
    {
        $review = new ProductReview([
            'post_id'       => $postId,
            'reviewer_name' => 'Reviewer ' . $rating,
            'review'        => 'A ' . $rating . ' star review.',
            'rating'        => $rating,
        ]);
        $review->status = $status;
        $review->save();

        return (int) $review->id;
    }

    /**
     * @return array{0: int, 1: array}
     */
    private function fetchAllProductsReviews(array $params = []): array
    {
        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/public/reviews/all');

        // Every param is sent: the framework's request object outlives one rest_do_request().
        // Scoped to this test's products, so reviews other tests leave behind stay out.
        $params = array_merge([
            'ratings' => '', 'rating' => 0, 'has_media' => 0, 'verified_only' => 0, 'rating_token' => '',
            'product_ids' => implode(',', $this->productIds),
            'category_ids' => '', 'per_page' => 0, 'sort_by' => 'created_at', 'sort_order' => 'DESC',
        ], $params);

        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        $response = rest_do_request($request);

        return [$response->get_status(), (array) $response->get_data()];
    }

    private function reviewIdsIn(array $body): array
    {
        $reviewIds = array_map(function ($review) {
            return (int) $review['id'];
        }, (array) ($body['reviews']['data'] ?? []));
        sort($reviewIds);

        return $reviewIds;
    }

    private function reviewIdsFor(array $labels): array
    {
        $reviewIds = array_map(function ($label) {
            return $this->reviewIdsByLabel[$label];
        }, $labels);
        sort($reviewIds);

        return $reviewIds;
    }

    public function test_listsApprovedReviewsOfPublishedProductsThatTakeReviews(): void
    {
        list($status, $body) = $this->fetchAllProductsReviews();

        $this->assertSame(200, $status);
        $this->assertSame($this->reviewIdsFor(['shoe5', 'shoe2', 'hat4']), $this->reviewIdsIn($body));
    }

    /**
     * The review popup and reply form read the product from the row, since the list spans products.
     */
    public function test_eachRowCarriesItsProductId(): void
    {
        list(, $body) = $this->fetchAllProductsReviews(['product_ids' => (string) $this->hatProductId]);

        $this->assertStringContainsString('data-review-post-id="' . $this->hatProductId . '"', $body['reviews_html']);
    }

    public function test_draftAndReviewsOffProductsShowNoReviewsEvenWhenNamed(): void
    {
        list(, $body) = $this->fetchAllProductsReviews(['product_ids' => implode(',', array_slice($this->productIds, 2))]);

        $this->assertSame([], $this->reviewIdsIn($body));
    }

    public function test_narrowsByProductAndCategory(): void
    {
        list(, $byProduct) = $this->fetchAllProductsReviews(['product_ids' => (string) $this->hatProductId]);
        list(, $byCategory) = $this->fetchAllProductsReviews(['category_ids' => (string) $this->footwearCategoryId]);

        $this->assertSame($this->reviewIdsFor(['hat4']), $this->reviewIdsIn($byProduct));
        $this->assertSame($this->reviewIdsFor(['shoe5', 'shoe2']), $this->reviewIdsIn($byCategory));
    }

    public function test_ratingChipsAndRatingSort(): void
    {
        list(, $filtered) = $this->fetchAllProductsReviews(['ratings' => '5,4']);
        list(, $sorted) = $this->fetchAllProductsReviews(['sort_by' => 'rating', 'sort_order' => 'ASC']);

        $this->assertSame($this->reviewIdsFor(['shoe5', 'hat4']), $this->reviewIdsIn($filtered));
        $this->assertSame([2, 4, 5], array_map(function ($review) {
            return (int) $review['rating'];
        }, $sorted['reviews']['data']));
    }

    public function test_endpointCapsPerPage(): void
    {
        list(, $body) = $this->fetchAllProductsReviews(['per_page' => 500]);

        $this->assertSame(50, (int) $body['reviews']['per_page']);
    }

    public function test_endpointReturns404WhenReviewsAreOffStoreWide(): void
    {
        ModuleSettings::saveSettings(['reviews' => ['active' => 'no']]);
        ModuleSettings::getAllSettings(false);
        ProductReviewService::resetRuntimeCache();

        list($status) = $this->fetchAllProductsReviews();

        $this->assertSame(404, $status);
    }

    public function test_scopeAllRendersACombinedSummaryWithoutWriteButton(): void
    {
        $html = (new ProductReviewsShortCode(['scope' => 'all', 'categories' => 'footwear', 'product_ids' => implode(',', $this->productIds)]))->renderShortcode();

        $this->assertStringContainsString('data-scope="all"', $html);
        $this->assertStringContainsString('data-post-id="0"', $html);
        $this->assertStringContainsString('&quot;category_ids&quot;:[' . $this->footwearCategoryId . ']', $html);
        $this->assertSame(2, substr_count($html, 'data-review-post-id="' . $this->shoeProductId . '"'));
        // The summary adds up the same products the list shows: here, the shoe's two reviews.
        $this->assertStringContainsString('data-reviews-summary', $html);
        $this->assertMatchesRegularExpression('#data-reviews-total>\s*Based on 2 reviews#', $html);
        // A review belongs to one product, so there is nothing to write one for.
        $this->assertStringNotContainsString('data-open-review-drawer', $html);
        $this->assertStringNotContainsString('data-review-drawer', $html);
    }

    public function test_unmatchedProductOrCategoryFilterRendersNothing(): void
    {
        foreach (['categories' => 'no-such-category', 'product_ids' => 'abc'] as $attribute => $value) {
            $html = (new ProductReviewsShortCode(['scope' => 'all', $attribute => $value]))->renderShortcode();

            $this->assertSame('', trim($html), $attribute . ' must not widen to the whole store');
        }

        // A category that exists still draws, by slug or by id.
        $bySlug = (new ProductReviewsShortCode(['scope' => 'all', 'categories' => 'footwear', 'product_ids' => implode(',', $this->productIds)]))->renderShortcode();
        $byId = (new ProductReviewsShortCode(['scope' => 'all', 'categories' => (string) $this->footwearCategoryId, 'product_ids' => implode(',', $this->productIds)]))->renderShortcode();
        $this->assertSame(2, substr_count($bySlug, 'data-review-post-id="' . $this->shoeProductId . '"'));
        $this->assertSame(2, substr_count($byId, 'data-review-post-id="' . $this->shoeProductId . '"'));
    }

    public function test_withoutScopeAllTheShortcodeStaysOnItsProduct(): void
    {
        $html = (new ProductReviewsShortCode(['id' => $this->shoeProductId, 'categories' => 'no-such-category', 'product_ids' => (string) $this->hatProductId]))->renderShortcode();

        $this->assertStringContainsString('data-post-id="' . $this->shoeProductId . '"', $html);
        $this->assertStringNotContainsString('data-scope="all"', $html);
        $this->assertStringContainsString('fct-reviews-summary', $html);
    }

    /**
     * With no per_page the list falls back to the store setting, which has no ceiling.
     * Page one must still match what the endpoint serves for page two.
     */
    public function test_firstPageIsCappedWhenStoreSettingExceedsTheMaximum(): void
    {
        ModuleSettings::saveSettings([
            'reviews' => ['active' => 'yes', 'reviews_enabled' => 'yes', 'reviews_per_page' => 80],
        ]);
        ModuleSettings::getAllSettings(false);
        ProductReviewService::resetRuntimeCache();

        $html = (new ProductReviewsShortCode(['scope' => 'all']))->renderShortcode();

        $this->assertStringContainsString('data-per-page="50"', $html);
    }

    public function test_firstPageIsCappedWhenPerPageExceedsTheMaximum(): void
    {
        $html = (new ProductReviewsShortCode(['scope' => 'all', 'per_page' => '500']))->renderShortcode();

        $this->assertStringContainsString('data-per-page="50"', $html);
    }
}
