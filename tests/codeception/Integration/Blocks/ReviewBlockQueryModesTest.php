<?php

namespace Tests\Integration\Blocks;

use FluentCart\Api\ModuleSettings;
use FluentCart\Api\Resource\ProductReviewResource;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Services\ProductReviewService;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * The All products and Multiple products query types on the Product Reviews and
 * Review List blocks, beside the existing Current product and Specific product.
 */
class ReviewBlockQueryModesTest extends WPTestCase
{
    /** @var int */
    private $shoeProductId;

    /** @var int */
    private $hatProductId;

    /** @var int */
    private $sockProductId;

    /** @var int */
    private $draftProductId;

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
        $this->sockProductId = (int) (new ProductFactory())->create(['post_title' => 'Wool Sock'])->ID;
        $this->draftProductId = (int) (new ProductFactory())->create(['post_title' => 'Draft Coat', 'post_status' => 'draft'])->ID;

        $this->createReview($this->shoeProductId, 5, 'Shoe five stars.');
        $this->createReview($this->shoeProductId, 3, 'Shoe three stars.');
        $this->createReview($this->hatProductId, 4, 'Hat four stars.');
        $this->createReview($this->sockProductId, 5, 'Sock five stars.');
        $this->createReview($this->draftProductId, 5, 'Draft coat review.');

        // The rating summary reads each product's cached figures.
        ProductReviewResource::recalculateProductRatings([
            $this->shoeProductId, $this->hatProductId, $this->sockProductId, $this->draftProductId,
        ]);
    }

    public function tearDown(): void
    {
        unset($GLOBALS['post']);
        ProductReviewService::resetRuntimeCache();
        parent::tearDown();
    }

    private function createReview(int $postId, int $rating, string $text): void
    {
        $review = new ProductReview([
            'post_id'       => $postId,
            'reviewer_name' => 'Reviewer ' . $rating,
            'review'        => $text,
            'rating'        => $rating,
        ]);
        $review->status = Status::REVIEW_APPROVED;
        $review->save();
    }

    private function renderBlocks(string $markup): string
    {
        return (string) preg_replace('#<style.*?</style>#s', '', do_blocks($markup));
    }

    private function reviewList(array $attributes, string $children = ''): string
    {
        return '<!-- wp:fluent-cart/product-review-list ' . wp_json_encode((object) $attributes) . ' -->'
            . $children
            . '<!-- /wp:fluent-cart/product-review-list -->';
    }

    private function composedRow(array $rowAttributes = []): string
    {
        $rowAttributes = array_merge(['wp_client_id' => 'query-modes-row'], $rowAttributes);

        return '<!-- wp:fluent-cart/review-item ' . wp_json_encode($rowAttributes) . ' -->'
            . '<!-- wp:fluent-cart/review-item-content /-->'
            . '<!-- /wp:fluent-cart/review-item -->';
    }

    /**
     * A container holding every single-product child beside the list, the way
     * the classic preset lays it out.
     */
    private function reviewsContainer(array $attributes, string $list = ''): string
    {
        $list = $list !== '' ? $list : $this->reviewList([]);

        return '<!-- wp:fluent-cart/product-reviews ' . wp_json_encode((object) $attributes) . ' -->'
            . '<!-- wp:fluent-cart/product-review-summary-group -->'
            . '<!-- wp:fluent-cart/product-review-summary /-->'
            . '<!-- wp:fluent-cart/write-a-review-button /-->'
            . '<!-- /wp:fluent-cart/product-review-summary-group -->'
            . '<!-- wp:fluent-cart/write-a-review-button /-->'
            . '<!-- wp:fluent-cart/product-review-form /-->'
            . $list
            . '<!-- /wp:fluent-cart/product-reviews -->';
    }

    /**
     * Several products: the summary stays (combined), but nothing that writes a
     * review for one product.
     */
    private function assertNoSingleProductChildren(string $html): void
    {
        foreach (['write-a-review-button', 'product-review-form'] as $blockName) {
            $this->assertDoesNotMatchRegularExpression(
                '#wp-block-fluent-cart-' . $blockName . '["\\s]#',
                $html,
                $blockName . ' needs a single product, so it must not render'
            );
        }
    }

    /**
     * @return array{0: int, 1: array}
     */
    private function fetchAllProductsReviews(array $params): array
    {
        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/public/reviews/all');

        // Every param is sent: the framework's request object outlives one rest_do_request().
        $params = array_merge([
            'ratings' => '', 'rating' => 0, 'has_media' => 0, 'verified_only' => 0, 'rating_token' => '',
            'product_ids' => '', 'category_ids' => '', 'per_page' => 0, 'page' => 1,
            'sort_by' => 'created_at', 'sort_order' => 'DESC', 'client_id' => '',
        ], $params);

        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        $response = rest_do_request($request);

        return [$response->get_status(), (array) $response->get_data()];
    }

    private function attributeIn(string $html, string $attribute): string
    {
        preg_match('#' . preg_quote($attribute, '#') . '="([^"]*)"#', $html, $matches);

        return isset($matches[1]) ? html_entity_decode($matches[1]) : '';
    }

    public function test_specific_product_mode_is_unchanged(): void
    {
        $html = $this->renderBlocks($this->reviewList([
            'query_type' => 'custom',
            'product_id' => (string) $this->shoeProductId,
        ]));

        $this->assertStringContainsString('Shoe five stars.', $html);
        $this->assertStringNotContainsString('Hat four stars.', $html);
        $this->assertSame((string) $this->shoeProductId, $this->attributeIn($html, 'data-post-id'));
        $this->assertStringNotContainsString('data-scope="all"', $html);
    }

    public function test_all_products_lists_every_eligible_product_and_ignores_saved_selections(): void
    {
        $html = $this->renderBlocks($this->reviewList([
            'query_type'  => 'all',
            // Left over from earlier modes; only the active mode's values count.
            'product_id'  => (string) $this->shoeProductId,
            'product_ids' => [$this->shoeProductId],
        ]));

        $this->assertStringContainsString('data-scope="all"', $html);
        $this->assertSame('0', $this->attributeIn($html, 'data-post-id'));
        foreach (['Shoe five stars.', 'Hat four stars.', 'Sock five stars.'] as $reviewText) {
            $this->assertStringContainsString($reviewText, $html);
        }
        $this->assertStringNotContainsString('Draft coat review.', $html, 'unpublished products stay out');
        $this->assertSame([], json_decode($this->attributeIn($html, 'data-product-filters'), true)['product_ids']);
    }

    public function test_multiple_products_lists_only_the_selection(): void
    {
        $html = $this->renderBlocks($this->reviewList([
            'query_type'  => 'multiple',
            'product_id'  => (string) $this->hatProductId,
            'product_ids' => [$this->shoeProductId, (string) $this->sockProductId],
        ]));

        $this->assertStringContainsString('Shoe five stars.', $html);
        $this->assertStringContainsString('Sock five stars.', $html);
        $this->assertStringNotContainsString('Hat four stars.', $html);
        $this->assertSame(
            [$this->shoeProductId, $this->sockProductId],
            json_decode($this->attributeIn($html, 'data-product-filters'), true)['product_ids'],
            'the selection travels to the endpoint for later pages'
        );
    }

    public function test_multiple_products_with_one_selection(): void
    {
        $html = $this->renderBlocks($this->reviewList([
            'query_type'  => 'multiple',
            'product_ids' => [$this->hatProductId],
        ]));

        $this->assertStringContainsString('Hat four stars.', $html);
        $this->assertStringNotContainsString('Shoe five stars.', $html);
        $this->assertStringContainsString('data-scope="all"', $html);
    }

    /**
     * @dataProvider emptySelections
     */
    public function test_multiple_products_without_a_valid_selection_renders_nothing($productIds): void
    {
        $attributes = ['query_type' => 'multiple'];
        if ($productIds !== null) {
            $attributes['product_ids'] = $productIds;
        }

        $this->assertSame('', trim($this->renderBlocks($this->reviewList($attributes))), 'never widens to every review');
        $this->assertSame('', trim($this->renderBlocks($this->reviewsContainer($attributes))));
    }

    public function emptySelections(): array
    {
        return [
            'missing'     => [null],
            'empty'       => [[]],
            'all invalid' => [['abc', 0, -3]],
        ];
    }

    public function test_a_selection_of_unavailable_products_does_not_widen(): void
    {
        $html = $this->renderBlocks($this->reviewList([
            'query_type'  => 'multiple',
            'product_ids' => [$this->draftProductId, 999999999],
        ]));

        foreach (['Shoe five stars.', 'Hat four stars.', 'Sock five stars.', 'Draft coat review.'] as $reviewText) {
            $this->assertStringNotContainsString($reviewText, $html);
        }
    }

    public function test_the_container_passes_its_mode_and_selection_to_the_list(): void
    {
        $html = $this->renderBlocks($this->reviewsContainer(
            ['query_type' => 'multiple', 'product_ids' => [$this->shoeProductId, $this->hatProductId]],
            // The list's own saved query must not override the container's.
            $this->reviewList(['query_type' => 'custom', 'product_id' => (string) $this->sockProductId])
        ));

        $this->assertStringContainsString('Shoe five stars.', $html);
        $this->assertStringContainsString('Hat four stars.', $html);
        $this->assertStringNotContainsString('Sock five stars.', $html);
        $this->assertNoSingleProductChildren($html);
    }

    public function test_an_all_products_container_keeps_a_combined_summary_without_review_writing(): void
    {
        $html = $this->renderBlocks($this->reviewsContainer(['query_type' => 'all']));

        $this->assertStringContainsString('Hat four stars.', $html);
        $this->assertStringContainsString('Sock five stars.', $html);
        $this->assertMatchesRegularExpression('#wp-block-fluent-cart-product-review-summary-group["\\s]#', $html);
        $this->assertGreaterThanOrEqual(4, $this->summaryIn($html)['total'], 'the summary counts every eligible product');
        $this->assertNoSingleProductChildren($html);
    }

    /**
     * The summary card's figures: average, total and bar counts from 5 down to 1.
     *
     * @return array{average: string, total: int, bars: int[]}
     */
    private function summaryIn(string $html): array
    {
        preg_match('#data-reviews-average>([^<]*)<#', $html, $average);
        preg_match('#data-reviews-total>\s*Based on ([\d,]+) reviews#', $html, $total);
        preg_match_all('#data-reviews-bar-count>([\d,]+)<#', $html, $bars);

        return [
            'average' => isset($average[1]) ? trim($average[1]) : '',
            'total'   => isset($total[1]) ? (int) str_replace(',', '', $total[1]) : -1,
            'bars'    => array_map(function ($count) {
                return (int) str_replace(',', '', $count);
            }, $bars[1]),
        ];
    }

    /**
     * A standalone Summary Group, the way the editor builds one: the Rating
     * Summary only exists inside it.
     */
    private function ratingSummary(array $attributes): string
    {
        return '<!-- wp:fluent-cart/product-review-summary-group ' . wp_json_encode((object) $attributes) . ' -->'
            . '<!-- wp:fluent-cart/product-review-summary /-->'
            . '<!-- wp:fluent-cart/write-a-review-button /-->'
            . '<!-- /wp:fluent-cart/product-review-summary-group -->';
    }

    public function test_the_rating_summary_combines_the_selected_products(): void
    {
        $html = $this->renderBlocks($this->ratingSummary([
            'query_type'  => 'multiple',
            'product_ids' => [$this->shoeProductId, $this->hatProductId],
        ]));

        $summary = $this->summaryIn($html);
        $this->assertSame(3, $summary['total']);
        $this->assertSame('4', $summary['average'], '(5 + 3 + 4) / 3, weighted by review, not averaged per product');
        $this->assertSame([1, 1, 1, 0, 0], $summary['bars']);
        $this->assertSame('0', $this->attributeIn($html, 'data-post-id'));
        $this->assertSame('all', $this->attributeIn($html, 'data-scope'), 'The summary refresh uses the combined endpoint.');
        $this->assertStringContainsString('data-product-filters=', $html, 'The summary retains its selected products for refreshes.');
        $this->assertNoSingleProductChildren($html);
    }

    public function test_combined_summary_includes_products_missing_cached_breakdowns(): void
    {
        $detail = \FluentCart\App\Models\ProductDetail::where('post_id', $this->shoeProductId)->first();
        $info = $detail->other_info;
        unset($info['rating_breakdown']);
        $detail->other_info = $info;
        $detail->save();

        $summary = ProductReviewService::getProductsRatingSummary([
            'product_ids' => [$this->shoeProductId, $this->hatProductId],
        ]);

        $this->assertSame(3, $summary['total'], 'All selected reviews contribute to the total.');
        $this->assertEquals(4, $summary['average'], 'Legacy reviews contribute to the weighted average.');
        $this->assertSame([5 => 1, 4 => 1, 3 => 1, 2 => 0, 1 => 0], $summary['breakdown'], 'The combined bars include the missing cache.');
    }

    public function test_combined_summary_leaves_never_reviewed_products_alone(): void
    {
        $unreviewedId = (int) (new ProductFactory())->create(['post_title' => 'Quiet Scarf'])->ID;
        $detail = \FluentCart\App\Models\ProductDetail::where('post_id', $unreviewedId)->first();
        $info = (array) $detail->other_info;
        unset($info['rating_breakdown'], $info['review_count'], $info['average_rating']);
        $detail->other_info = $info;
        $detail->save();

        $summary = ProductReviewService::getProductsRatingSummary([]);

        $this->assertSame(4, $summary['total'], 'A product without reviews adds nothing.');
        $after = (array) \FluentCart\App\Models\ProductDetail::where('post_id', $unreviewedId)->first()->other_info;
        $this->assertArrayNotHasKey('rating_breakdown', $after, 'A product without reviews has nothing to repair, so it is not rewritten.');
    }

    public function test_combined_summary_counts_the_same_categories_as_the_list(): void
    {
        $term = wp_insert_term('Summary Footwear', 'product-categories');
        $categoryId = (int) $term['term_id'];
        wp_set_object_terms($this->shoeProductId, [$categoryId], 'product-categories');

        $byCategory = ProductReviewService::getProductsRatingSummary(['category_ids' => [$categoryId]]);
        $this->assertSame(2, $byCategory['total'], 'only the shoe is in the category');
        $this->assertSame([5 => 1, 4 => 0, 3 => 1, 2 => 0, 1 => 0], $byCategory['breakdown']);

        $both = ProductReviewService::getProductsRatingSummary([
            'product_ids'  => [$this->shoeProductId, $this->hatProductId],
            'category_ids' => [$categoryId],
        ]);
        $this->assertSame(2, $both['total'], 'a selected product outside the category is not counted');
    }

    public function test_one_summary_request_repairs_a_bounded_number_of_caches(): void
    {
        $limit = ProductReviewService::MAX_SUMMARY_CACHE_REPAIRS;
        $productIds = [];
        for ($i = 0; $i <= $limit; $i++) {
            $productId = (int) (new ProductFactory())->create(['post_title' => 'Legacy ' . $i])->ID;
            $this->createReview($productId, 4, 'Legacy review ' . $i);
            $productIds[] = $productId;
        }
        // Reviewed, but cached without a breakdown.
        \FluentCart\App\Models\ProductDetail::query()->whereIn('post_id', $productIds)->update(['other_info' => '{}']);

        $missingBreakdowns = function () use ($productIds) {
            return \FluentCart\App\Models\ProductDetail::query()
                ->whereIn('post_id', $productIds)
                ->whereRaw("JSON_EXTRACT(other_info, '$.rating_breakdown') IS NULL")
                ->count();
        };

        ProductReviewService::getProductsRatingSummary(['product_ids' => $productIds]);
        $this->assertSame(1, $missingBreakdowns(), 'one request repairs at most the limit');

        $summary = ProductReviewService::getProductsRatingSummary(['product_ids' => $productIds]);
        $this->assertSame(0, $missingBreakdowns(), 'the next request repairs the rest');
        $this->assertSame($limit + 1, $summary['total'], 'once repaired, every review counts');
    }

    /**
     * The whole section in one call, as the builder widgets and the shortcode render it.
     */
    private function renderSection(array $options): string
    {
        ob_start();
        (new \FluentCart\App\Services\Renderer\ProductReviewRenderer(0, $options))->render();

        return (string) preg_replace('#<style.*?</style>#s', '', ob_get_clean());
    }

    public function test_a_full_section_for_several_products_shows_the_combined_summary(): void
    {
        $html = $this->renderSection(['productFilters' => ['product_ids' => [$this->shoeProductId, $this->hatProductId]]]);

        $summary = $this->summaryIn($html);
        $this->assertSame(3, $summary['total']);
        $this->assertSame('4', $summary['average']);
        $this->assertSame([1, 1, 1, 0, 0], $summary['bars']);
        $this->assertStringContainsString('fct-reviews-layout--summary-side', $html);
        $this->assertStringNotContainsString('fct-reviews-summary-write-btn', $html, 'no single product to review');
        $this->assertStringNotContainsString('data-review-drawer', $html, 'no review drawer either');
        $this->assertStringContainsString('Shoe five stars.', $html);
    }

    public function test_a_full_section_for_several_products_keeps_its_summary_options(): void
    {
        $filters = ['productFilters' => ['product_ids' => [$this->shoeProductId, $this->hatProductId]]];

        $hidden = $this->renderSection($filters + ['showSummary' => false]);
        $this->assertSame(-1, $this->summaryIn($hidden)['total'], 'summary off stays off');
        $this->assertStringContainsString('fct-reviews-layout--single', $hidden);

        $top = $this->renderSection($filters + ['summaryMode' => 'top']);
        $this->assertSame(3, $this->summaryIn($top)['total']);
        $this->assertStringContainsString('fct-reviews-layout--summary-top', $top);

        // Write a Review alone has nothing to invite a review for.
        $ctaOnly = $this->renderSection($filters + ['summaryMode' => 'cta']);
        $this->assertStringContainsString('fct-reviews-layout--single', $ctaOnly);
        $this->assertStringNotContainsString('fct-reviews-summary-write-btn', $ctaOnly);
    }

    public function test_the_all_products_shortcode_shows_the_combined_summary(): void
    {
        $html = (new \FluentCart\App\Hooks\Handlers\ShortCodes\ProductReviewsShortCode([
            'scope'       => 'all',
            'product_ids' => $this->shoeProductId . ',' . $this->hatProductId,
        ]))->renderShortcode();

        $this->assertSame(3, $this->summaryIn($html)['total']);
        $this->assertStringNotContainsString('fct-reviews-summary-write-btn', $html);
    }

    public function test_the_rating_summary_for_one_selected_product_is_unchanged(): void
    {
        $html = $this->renderBlocks($this->ratingSummary([
            'query_type' => 'custom',
            'product_id' => (string) $this->shoeProductId,
        ]));

        $summary = $this->summaryIn($html);
        $this->assertSame(2, $summary['total']);
        $this->assertSame('4', $summary['average']);
        $this->assertSame((string) $this->shoeProductId, $this->attributeIn($html, 'data-post-id'));
        $this->assertMatchesRegularExpression('#wp-block-fluent-cart-write-a-review-button["\\s]#', $html, 'one product keeps Write a Review');
    }

    public function test_the_rating_summary_for_all_products_skips_unpublished_ones(): void
    {
        $all = $this->summaryIn($this->renderBlocks($this->ratingSummary(['query_type' => 'all'])));
        $this->assertGreaterThanOrEqual(4, $all['total']);

        $draftOnly = $this->summaryIn($this->renderBlocks($this->ratingSummary([
            'query_type'  => 'multiple',
            'product_ids' => [$this->draftProductId],
        ])));
        $this->assertSame(0, $draftOnly['total'], 'a draft product contributes nothing');
    }

    public function test_the_rating_summary_skips_products_with_reviews_switched_off(): void
    {
        $closedProductId = (int) (new ProductFactory())
            ->withDetailAttributes(['other_info' => ['reviews_enabled' => 'no']])
            ->create(['post_title' => 'Closed Scarf'])->ID;
        $this->createReview($closedProductId, 1, 'Closed scarf review.');
        ProductReviewResource::recalculateProductRatings([$closedProductId]);

        $summary = $this->summaryIn($this->renderBlocks($this->ratingSummary([
            'query_type'  => 'multiple',
            'product_ids' => [$this->hatProductId, $closedProductId],
        ])));

        $this->assertSame(1, $summary['total'], 'only the hat counts');
        $this->assertSame([0, 1, 0, 0, 0], $summary['bars']);
    }

    public function test_the_rating_summary_without_a_valid_selection_renders_nothing(): void
    {
        $html = $this->renderBlocks($this->ratingSummary(['query_type' => 'multiple', 'product_ids' => []]));

        $this->assertSame('', trim($html));
    }

    public function test_a_summary_inside_the_group_follows_the_container_selection(): void
    {
        $html = $this->renderBlocks($this->reviewsContainer([
            'query_type'  => 'multiple',
            'product_ids' => [$this->shoeProductId, $this->sockProductId],
        ]));

        $summary = $this->summaryIn($html);
        $this->assertSame(3, $summary['total'], 'the container\'s selection, through the Summary Group');
        $this->assertSame([2, 0, 1, 0, 0], $summary['bars']);
        $this->assertNoSingleProductChildren($html);
    }

    public function test_a_specific_product_container_still_renders_its_children(): void
    {
        $html = $this->renderBlocks($this->reviewsContainer([
            'query_type' => 'custom',
            'product_id' => (string) $this->shoeProductId,
        ]));

        $this->assertMatchesRegularExpression('#wp-block-fluent-cart-product-review-summary-group["\\s]#', $html);
        $this->assertStringContainsString('Shoe five stars.', $html);
        $this->assertStringNotContainsString('Hat four stars.', $html);
    }

    public function test_the_new_modes_ignore_the_product_page_being_viewed(): void
    {
        $GLOBALS['post'] = get_post($this->shoeProductId);

        $html = $this->renderBlocks($this->reviewsContainer(['query_type' => 'all']));

        $this->assertStringContainsString('Hat four stars.', $html);
        $this->assertStringContainsString('Sock five stars.', $html);
        $this->assertNoSingleProductChildren($html);

        $html = $this->renderBlocks($this->reviewList([
            'query_type'  => 'multiple',
            'product_ids' => [$this->sockProductId],
        ]));

        $this->assertStringContainsString('Sock five stars.', $html);
        $this->assertStringNotContainsString('Shoe five stars.', $html);
    }

    public function test_composed_rows_and_their_product_survive_the_all_products_endpoint(): void
    {
        $html = $this->renderBlocks($this->reviewList(
            ['query_type' => 'multiple', 'product_ids' => [$this->shoeProductId, $this->hatProductId], 'perPage' => 1],
            $this->composedRow()
        ));

        $this->assertMatchesRegularExpression(
            '#data-composed-row[^>]*data-review-post-id="(' . $this->shoeProductId . '|' . $this->hatProductId . ')"#',
            $html,
            'the first page tells the popup which product a row belongs to'
        );

        [$status, $body] = $this->fetchAllProductsReviews([
            'page'        => 2,
            'per_page'    => 1,
            'product_ids' => $this->shoeProductId . ',' . $this->hatProductId,
            'client_id'   => $this->attributeIn($html, 'data-client-id'),
        ]);

        $this->assertSame(200, $status);
        $rows = (string) ($body['reviews_html'] ?? '');
        $this->assertStringContainsString('wp-block-fluent-cart-review-item-content', $rows, 'page two keeps the composed row');
        $this->assertStringNotContainsString('fct-review-item-header"', $rows, 'and not the fixed row');
        $this->assertMatchesRegularExpression(
            '#data-composed-row[^>]*data-review-post-id="(' . $this->shoeProductId . '|' . $this->hatProductId . ')"#',
            $rows
        );
    }

    public function test_an_unknown_composition_token_keeps_the_fixed_row(): void
    {
        [$status, $body] = $this->fetchAllProductsReviews([
            'product_ids' => (string) $this->hatProductId,
            'client_id'   => 'no-such-row-abc123',
        ]);

        $this->assertSame(200, $status);
        $this->assertStringContainsString('fct-review-item-header', (string) ($body['reviews_html'] ?? ''));
    }

    public function test_the_selection_and_rating_floor_hold_on_later_pages(): void
    {
        $html = $this->renderBlocks($this->reviewList(
            ['query_type' => 'multiple', 'product_ids' => [$this->shoeProductId, $this->hatProductId]],
            $this->composedRow(['minRating' => 4])
        ));

        $this->assertStringNotContainsString('Shoe three stars.', $html);

        [, $body] = $this->fetchAllProductsReviews([
            'product_ids'  => implode(',', json_decode($this->attributeIn($html, 'data-product-filters'), true)['product_ids']),
            'rating_token' => $this->attributeIn($html, 'data-rating-token'),
            'client_id'    => $this->attributeIn($html, 'data-client-id'),
        ]);

        $ratings = array_map(function ($review) {
            return (int) $review['rating'];
        }, (array) ($body['reviews']['data'] ?? []));
        sort($ratings);

        $this->assertSame([4, 5], $ratings, 'only the selected products, at or above the floor');
    }
}
