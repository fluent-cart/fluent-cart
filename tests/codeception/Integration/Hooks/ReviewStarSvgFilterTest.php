<?php

namespace Tests\Integration\Hooks;

use FluentCart\App\Services\Renderer\ReviewThreadMarkup;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Every storefront rating star comes from ReviewThreadMarkup::starSvg(),
 * which reads app/Views/icons/review-star.svg — the same file the admin,
 * block editor and customer portal import — and passes it through
 * `fluent_cart/reviews/star_svg` so another plugin can swap the shape.
 */
class ReviewStarSvgFilterTest extends WPTestCase
{
    protected function tearDown(): void
    {
        remove_all_filters('fluent_cart/reviews/star_svg');
        parent::tearDown();
    }

    public function test_default_star_is_the_shared_svg_file()
    {
        $file = trim(file_get_contents(FLUENTCART_PLUGIN_PATH . 'app/Views/icons/review-star.svg'));

        $this->assertSame($file, ReviewThreadMarkup::starSvg());
        $this->assertStringContainsString('class="fct-star-svg"', $file);
    }

    public function test_filter_replaces_the_star()
    {
        $custom = '<svg class="fct-star-svg" data-test="custom-star"></svg>';

        add_filter('fluent_cart/reviews/star_svg', function () use ($custom) {
            return $custom;
        });

        $this->assertSame($custom, ReviewThreadMarkup::starSvg());
    }

    public function test_filter_receives_the_default_star()
    {
        $received = null;

        add_filter('fluent_cart/reviews/star_svg', function ($svg) use (&$received) {
            $received = $svg;
            return $svg;
        });

        ReviewThreadMarkup::starSvg();

        $this->assertStringContainsString('class="fct-star-svg"', (string) $received);
    }

    public function test_rendered_star_rows_use_the_filtered_star()
    {
        add_filter('fluent_cart/reviews/star_svg', function () {
            return '<svg class="fct-star-svg" data-test="custom-star"></svg>';
        });

        $this->assertSame(5, substr_count(ReviewThreadMarkup::starsHtml(3), 'data-test="custom-star"'));
    }
}
