<?php

namespace Tests\Integration\Hooks;

use FluentCart\Api\ModuleSettings;
use FluentCart\Api\Resource\ProductReviewResource;
use FluentCart\App\Hooks\Handlers\ShortCodes\ProductReviewsShortCode;
use FluentCart\App\Http\Controllers\FrontendControllers\ProductReviewFrontendController;
use FluentCart\App\Services\ProductReviewService;
use FluentCart\App\Services\Renderer\ProductReviewRenderer;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * [fluent_cart_product_reviews] — the attributes, as the renderer reads them.
 *
 * A shortcode is typed by hand into a post, so every value arrives as a string
 * and any of them can be wrong: a misspelt view, a pager that does not exist, a
 * column count past what the page has styles for, a page length larger than the
 * endpoint that serves page two will ever return. None of those may reach
 * ProductReviewRenderer as typed — an unrecognised view would render a list
 * with no layout class, and an over-long first page would silently shrink on
 * the first click.
 *
 * Covers attribute mapping and rendered regressions for control visibility,
 * store badge defaults, and photo-only review counts.
 *
 * Grid and slider need Pro (resolveViewMode answers 'list' without it), so
 * those two attribute cases live in IntegrationPro/Reviews/ProductReviewsShortCodeProTest.
 */
class ProductReviewsShortCodeTest extends WPTestCase
{
    public function tearDown(): void
    {
        parent::tearDown();
        ProductReviewService::resetRuntimeCache();
    }

    private function productWithBadgeSetting(string $badge): int
    {
        $settings = ModuleSettings::getAllSettings();
        $settings['reviews']['active'] = 'yes';
        $settings['reviews']['show_verified_badge'] = $badge;
        ModuleSettings::saveSettings($settings);
        ProductReviewService::resetRuntimeCache();

        return (int) (new ProductFactory())->create()->ID;
    }

    public function test_legacy_hide_controls_still_hides_chips_unless_explicitly_enabled(): void
    {
        $productId = $this->productWithBadgeSetting('yes');
        foreach ([false, true] as $explicitChips) {
            $options = ['showSortControls' => false];
            if ($explicitChips) {
                $options['showFilterChips'] = true;
            }
            ob_start();
            try {
                (new ProductReviewRenderer($productId, $options))->render();
                $html = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            $this->assertStringContainsString('data-fluent-cart-reviews', $html);
            $this->assertStringNotContainsString('data-reviews-sort', $html);
            $this->assertSame($explicitChips, strpos($html, 'data-reviews-filter-chips') !== false);
        }
    }

    public function test_invalid_badge_attribute_inherits_store_setting_in_rendered_shortcode(): void
    {
        foreach (['no' => '0', 'yes' => '1'] as $setting => $expected) {
            $productId = $this->productWithBadgeSetting($setting);
            $html = (new ProductReviewsShortCode(['id' => $productId, 'verified_badge' => 'maybe']))->renderShortcode();
            $this->assertStringContainsString('data-show-verified="' . $expected . '"', $html);
        }
    }

    public function test_photo_only_shortcode_count_excludes_text_only_reviews(): void
    {
        $productId = $this->productWithBadgeSetting('yes');
        ProductReviewResource::create([
            'post_id' => $productId,
            'rating' => 5,
            'status' => 'approved',
            'reviewer_name' => 'Review Buyer',
            'reviewer_email' => 'shortcode@example.test',
            'content' => 'A review without photos.',
        ]);
        $all = (new ProductReviewsShortCode(['id' => $productId]))->renderShortcode();
        $photos = (new ProductReviewsShortCode(['id' => $productId, 'photos' => 'only']))->renderShortcode();
        $this->assertStringContainsString('data-reviews-total-count>1</span>', $all);
        $this->assertStringContainsString('data-reviews-total-count>0</span>', $photos);
    }

    /**
     * @param array $attributes
     * @return array
     */
    private function options(array $attributes): array
    {
        $shortcode = new ProductReviewsShortCode($attributes);

        $method = new \ReflectionMethod(ProductReviewsShortCode::class, 'renderOptions');
        $method->setAccessible(true);

        return $method->invoke($shortcode, $attributes);
    }

    public function test_no_attributes_render_the_default_section(): void
    {
        $options = $this->options([]);

        $this->assertSame('list', $options['viewMode']);
        $this->assertSame('numbers', $options['paginationType']);
        $this->assertSame(0, $options['perPage'], 'unset per_page must stay 0 — the renderer reads that as the store setting');
        $this->assertSame('created_at', $options['defaultSortBy']);
        $this->assertSame('DESC', $options['defaultSortOrder']);
        $this->assertTrue($options['showFilterChips']);
        $this->assertTrue($options['showSortControls']);
        $this->assertTrue($options['showSummary']);
        $this->assertFalse($options['hasMedia']);
        $this->assertArrayNotHasKey('sliderSettings', $options, 'a list has no slider settings to normalize');
    }

    public function test_unrecognised_view_and_pager_fall_back(): void
    {
        $options = $this->options([
            'view_mode'  => 'griid',
            'pagination' => 'carousel',
        ]);

        $this->assertSame('list', $options['viewMode'], 'a view the page has no styles for must not reach the renderer');
        $this->assertSame('numbers', $options['paginationType']);
    }

    public function test_columns_are_clamped_to_what_the_page_can_draw(): void
    {
        $this->assertSame(4, $this->options(['columns' => '8'])['gridColumns'], 'past four a review is too narrow to read');
        $this->assertSame(2, $this->options(['columns' => '1'])['gridColumns'], 'one column is the list view by another name');
        $this->assertSame(3, $this->options(['columns' => '3'])['gridColumns']);
    }

    public function test_per_page_is_capped_at_the_endpoint_that_serves_later_pages(): void
    {
        $cap = ProductReviewFrontendController::MAX_REVIEWS_PER_PAGE;

        $this->assertSame($cap, $this->options(['per_page' => '9999'])['perPage'], 'a first page longer than the endpoint would shrink on the first click');
        $this->assertSame(4, $this->options(['per_page' => '4'])['perPage']);
        $this->assertSame(0, $this->options(['per_page' => '-3'])['perPage'], 'a nonsense length falls back to the store setting, not to a broken query');
    }

    public function test_sort_is_confined_to_what_the_sort_control_offers(): void
    {
        $taken = $this->options(['sort_by' => 'rating', 'sort_order' => 'asc']);
        $this->assertSame('rating', $taken['defaultSortBy']);
        $this->assertSame('ASC', $taken['defaultSortOrder']);

        // The pair is also what the select opens showing, so one it has no
        // option for would leave the control and the list disagreeing.
        $rejected = $this->options(['sort_by' => 'helpful', 'sort_order' => 'sideways']);
        $this->assertSame('created_at', $rejected['defaultSortBy']);
        $this->assertSame('DESC', $rejected['defaultSortOrder']);
    }

    public function test_chips_and_sort_are_switched_independently(): void
    {
        $noChips = $this->options(['filter' => 'no']);
        $this->assertFalse($noChips['showFilterChips']);
        $this->assertTrue($noChips['showSortControls'], 'dropping the chips must leave the sort control standing');

        $noSort = $this->options(['sorting' => 'no']);
        $this->assertTrue($noSort['showFilterChips']);
        $this->assertFalse($noSort['showSortControls']);
    }

    public function test_an_unparseable_yes_no_keeps_the_default(): void
    {
        $options = $this->options(['filter' => 'ye', 'summary' => 'maybe']);

        $this->assertTrue($options['showFilterChips'], 'a typo must not silently remove the chips');
        $this->assertTrue($options['showSummary']);
    }

    public function test_an_omitted_verified_badge_leaves_the_store_setting_alone(): void
    {
        // ProductReviewRenderer merges with wp_parse_args($options, $defaults),
        // so a passed option wins over the default it builds from the store's
        // show_verified_badge. Sending one unasked would override that setting
        // on every page carrying the shortcode.
        $this->assertArrayNotHasKey('showVerifiedBadge', $this->options([]));

        $this->assertFalse($this->options(['verified_badge' => 'no'])['showVerifiedBadge'], 'asked for explicitly, it still wins');
        $this->assertTrue($this->options(['verified_badge' => 'yes'])['showVerifiedBadge']);
    }

    public function test_photos_only_narrows_the_query(): void
    {
        $this->assertTrue($this->options(['photos' => 'only'])['hasMedia']);

        // photos="yes" reads as "photos are welcome here", which is what a
        // list shows anyway — not as "only these".
        $this->assertFalse($this->options(['photos' => 'yes'])['hasMedia']);
        $this->assertFalse($this->options([])['hasMedia']);
    }

    public function test_star_colour_is_only_passed_when_it_is_a_colour(): void
    {
        $this->assertSame('#ff0000', $this->options(['star_color' => '#ff0000'])['starColor']);
        $this->assertArrayNotHasKey('starColor', $this->options(['star_color' => 'red();']), 'anything but a hex colour leaves the renderer on its own default');
    }
}
