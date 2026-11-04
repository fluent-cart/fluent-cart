<?php

namespace Tests\Integration\Http;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\App;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Services\ProductReviewService;
use FluentCart\App\Services\Renderer\ProductCardRender;
use FluentCart\App\Services\Renderer\ProductReviewRenderer;
use FluentCart\Framework\Http\Request\Request;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

class ReviewThresholdsTest extends WPTestCase
{
    private $product;
    private $lastResponse;

    public function setUp(): void
    {
        parent::setUp();
        ModuleSettings::saveSettings(['reviews' => ['active' => 'yes', 'reviews_enabled' => 'yes', 'reviews_per_page' => 1]]);
        ModuleSettings::getAllSettings(false);
        ProductReviewService::resetRuntimeCache();
        $this->product = (new ProductFactory())->withDetailAttributes([
            'other_info' => ['review_count' => 5, 'average_rating' => 3.0],
        ])->create();
        foreach (range(1, 5) as $rating) {
            $review = new ProductReview([
                'post_id' => $this->product->ID,
                'reviewer_name' => 'Threshold Buyer',
                'review' => 'Threshold review ' . $rating,
                'rating' => $rating,
            ]);
            $review->status = 'approved';
            $review->save();
        }
        // Module activation is test-local, after normal registration has run.
        \FluentCart\App\Hooks\Handlers\ShortCodes\ProductReviewsShortCode::register();
    }

    public function tearDown(): void
    {
        ProductReviewService::resetRuntimeCache();
        parent::tearDown();
    }

    private function token(string $html): string
    {
        $this->assertSame(1, preg_match('/data-rating-token="([^"]+)"/', $html, $matches));
        return html_entity_decode($matches[1], ENT_QUOTES);
    }

    private function page(string $token, array $params = []): array
    {
        // The framework request is a process singleton that merges every REST
        // call's params; the 'request' alias must be reset too or rating=5 from
        // an earlier call sticks.
        $app = App::getInstance();
        $fresh = new Request($app, [], []);
        $app->instance(Request::class, $fresh);
        $app->instance('request', $fresh);
        if ($app->bound('wprestrequest')) {
            $app->forgetInstance('wprestrequest');
        }
        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/public/reviews/' . $this->product->ID);
        foreach (array_merge(['rating_token' => $token, 'per_page' => 1], $params) as $key => $value) {
            $request->set_param($key, $value);
        }
        $response = rest_do_request($request);
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $this->lastResponse = $response->get_data();
        return $this->lastResponse['reviews'];
    }

    public function test_shortcode_floor_survives_sort_filter_and_every_page(): void
    {
        $html = do_shortcode('[fluent_cart_product_reviews id="' . $this->product->ID . '" min_rating="3"]');
        $this->assertStringContainsString('data-min-rating="3"', $html);
        $token = $this->token($html);
        foreach (['ASC', 'DESC'] as $direction) {
            $ratings = [];
            foreach ([1, 2, 3] as $page) {
                $reviews = $this->page($token, ['page' => $page, 'sort_by' => 'rating', 'sort_order' => $direction]);
                $this->assertSame(3, (int) $reviews['total']);
                $this->assertSame(3, (int) $reviews['last_page']);
                $ratings[] = (int) $reviews['data'][0]['rating'];
            }
            $this->assertSame($direction === 'ASC' ? [3, 4, 5] : [5, 4, 3], $ratings);
        }
        $this->assertSame(0, (int) $this->page($token, ['rating' => 1])['total']);
        $this->assertSame(1, (int) $this->page($token, ['rating' => 5])['total']);
    }

    public function test_addon_renderer_instances_keep_independent_floors(): void
    {
        $tokens = [];
        foreach ([2, 5] as $minimum) {
            ob_start();
            (new ProductReviewRenderer($this->product->ID, ['minRating' => $minimum]))->render();
            $tokens[$minimum] = $this->token(ob_get_clean());
        }
        $this->assertNotSame($tokens[2], $tokens[5]);
        $this->assertSame(4, (int) $this->page($tokens[2])['total']);
        $this->assertSame(1, (int) $this->page($tokens[5])['total']);
        $this->assertSame(4, (int) $this->page($tokens[2], ['page' => 2])['total']);
    }

    public function test_list_filter_applies_initially_and_is_carried_into_refresh(): void
    {
        $calls = 0;
        $filter = function ($minimum, $context) use (&$calls) {
            $this->assertSame((int) $this->product->ID, $context['post_id']);
            $calls++;
            return 4;
        };
        add_filter('fluent_cart/review/list_min_rating', $filter, 10, 2);
        try {
            $html = do_shortcode('[fluent_cart_product_reviews id="' . $this->product->ID . '"]');
            $this->assertStringContainsString('data-min-rating="4"', $html);
            $this->assertSame(2, (int) $this->page($this->token($html))['total']);
            $this->assertSame(1, $calls, 'A signed setting must not apply a filter twice.');
        } finally {
            remove_filter('fluent_cart/review/list_min_rating', $filter, 10);
        }
    }

    public function test_tokens_reject_tampering_and_cross_product_reuse(): void
    {
        $id = (int) $this->product->ID;
        $token = ProductReviewService::listRatingToken($id, 4);
        $this->assertLessThanOrEqual(64, strlen($token));
        $this->assertNull(ProductReviewService::minRatingFromToken($id + 1, $token));
        $this->assertNull(ProductReviewService::minRatingFromToken($id, str_replace('fcr1-4-', 'fcr1-1-', $token)));
        $this->assertSame(5, (int) $this->page('invalid')['total']);
        $this->assertSame(2, (int) $this->page($token, ['min_rating' => 1])['total']);
    }

    public function test_block_composition_keeps_its_layout_and_filtered_pagination(): void
    {
        $handler = new \FluentCart\App\Hooks\Handlers\BlockEditors\ProductReviewList\ProductReviewListBlockEditor();
        if (!\WP_Block_Type_Registry::get_instance()->is_registered('fluent-cart/product-review-list')) {
            $handler->init();
        }
        $markup = '<!-- wp:fluent-cart/product-review-list {"query_type":"custom","product_id":' . $this->product->ID . '} -->'
            . '<!-- wp:fluent-cart/review-item {"minRating":4,"wp_client_id":"threshold-test"} -->'
            . '<!-- wp:group {"className":"threshold-layout"} --><div class="wp-block-group threshold-layout">'
            . '<!-- wp:fluent-cart/review-rating /--></div><!-- /wp:group -->'
            . '<!-- /wp:fluent-cart/review-item --><!-- /wp:fluent-cart/product-review-list -->';
        wp_update_post(['ID' => $this->product->ID, 'post_content' => $markup]);
        $previousPost = $GLOBALS['post'] ?? null;
        $GLOBALS['post'] = get_post($this->product->ID);
        try {
            $html = do_blocks($markup);
            $this->assertStringContainsString('data-min-rating="4"', $html);
            $this->assertSame(1, preg_match('/data-client-id="([^"]+)"/', $html, $matches));
            $reviews = $this->page($this->token($html), ['client_id' => $matches[1], 'page' => 2]);
            $this->assertStringContainsString('threshold-layout', $this->lastResponse['reviews_html']);
            $this->assertSame(2, (int) $reviews['total']);
            $this->assertCount(1, $reviews['data']);
            $this->assertGreaterThanOrEqual(4, (int) $reviews['data'][0]['rating']);
            $this->assertNotNull(\FluentCart\App\Hooks\Handlers\BlockEditors\ProductReviewList\ProductReviewListBlockEditor::savedRowRenderer($matches[1]));
        } finally {
            $GLOBALS['post'] = $previousPost;
        }
    }

    public function test_rating_block_and_addon_renderer_share_visibility_filters(): void
    {
        $render = function (array $attributes = []): string {
            return (new \FluentCart\App\Hooks\Handlers\BlockEditors\ProductRatingBlockEditor())->render(array_merge([
                'query_type' => 'custom', 'product_id' => $this->product->ID,
            ], $attributes));
        };
        $this->assertStringContainsString('fct-product-card-stars', $render());
        foreach (['min_review_count' => 6, 'min_average_rating' => 3.1] as $name => $minimum) {
            $filter = function ($value, $context) use ($minimum) {
                $this->assertSame((int) $this->product->ID, $context['post_id']);
                return $minimum;
            };
            add_filter('fluent_cart/review/' . $name, $filter, 10, 2);
            try {
                $this->assertSame('', (new \FluentCart\App\Hooks\Handlers\BlockEditors\ProductRatingBlockEditor())->render([
                    'query_type' => 'custom', 'product_id' => $this->product->ID,
                ]));
                ob_start();
                (new ProductCardRender($this->product))->renderStarRatingBlock();
                $this->assertSame('', ob_get_clean());
            } finally {
                remove_filter('fluent_cart/review/' . $name, $filter, 10);
            }
        }
        $this->assertSame('', $render(['minAverageRating' => 3.1]));
        $this->assertSame('', $render(['minReviewCount' => 6]));
        $this->assertStringContainsString('fct-product-card-stars', $render());
    }
}
