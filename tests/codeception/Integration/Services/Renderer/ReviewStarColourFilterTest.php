<?php

namespace Tests\Integration\Services\Renderer;

use FluentCart\App\Modules\Reviews\ReviewModule;
use FluentCart\App\Services\Renderer\ProductReviewRenderer;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * The store-wide star colours come from a filter, never from a setting.
 *
 * Stars are not palette colours (gold by convention), so Appearance has no
 * picker for them. A developer sets them through
 * `fluent_cart/reviews/star_colors`; the result is printed as the two
 * variables every star already reads (--fct-review-star-color,
 * --fct-review-star-empty-color), and the review emails — which cannot read a
 * CSS variable — use the same resolved values.
 */
class ReviewStarColourFilterTest extends WPTestCase
{
    const FILTER = 'fluent_cart/reviews/star_colors';

    public function tearDown(): void
    {
        remove_all_filters(self::FILTER);
        parent::tearDown();
    }

    public function test_the_unfiltered_colours_are_the_stylesheet_defaults(): void
    {
        $this->assertSame(
            ['filled' => '#f59e0b', 'empty' => '#d1d5db'],
            ProductReviewRenderer::starColors()
        );
        $this->assertSame('', ProductReviewRenderer::starColorCss(), 'nothing is printed while the defaults stand');
    }

    public function test_a_filtered_colour_is_printed_as_the_star_variable(): void
    {
        add_filter(self::FILTER, function ($colors) {
            $colors['filled'] = '#E11D48';

            return $colors;
        });

        $this->assertSame('#e11d48', ProductReviewRenderer::starColors()['filled']);
        $this->assertSame(
            ':root:root{--fct-review-star-color:#e11d48;}',
            ProductReviewRenderer::starColorCss(),
            'only the changed variable, on a selector that outranks the stylesheets\' :root'
        );
    }

    public function test_the_empty_star_colour_can_be_filtered_too(): void
    {
        add_filter(self::FILTER, function ($colors) {
            return ['filled' => '#f59e0b', 'empty' => '#374151'];
        });

        $this->assertSame(':root:root{--fct-review-star-empty-color:#374151;}', ProductReviewRenderer::starColorCss());
    }

    public function test_an_invalid_value_keeps_its_default(): void
    {
        add_filter(self::FILTER, function () {
            return ['filled' => 'red; } body { display:none', 'empty' => 'var(--evil)'];
        });

        $this->assertSame(['filled' => '#f59e0b', 'empty' => '#d1d5db'], ProductReviewRenderer::starColors());
        $this->assertSame('', ProductReviewRenderer::starColorCss());
    }

    public function test_a_non_array_return_is_discarded(): void
    {
        add_filter(self::FILTER, function () {
            return 'not an array';
        });

        $this->assertSame(['filled' => '#f59e0b', 'empty' => '#d1d5db'], ProductReviewRenderer::starColors());
    }

    public function test_the_storefront_head_prints_the_filtered_colours(): void
    {
        $this->assertNotFalse(
            has_action('wp_head', [ReviewModule::class, 'printStarColors']),
            'the reviews module prints the star colours on the storefront'
        );

        ob_start();
        ReviewModule::printStarColors();
        $this->assertSame('', ob_get_clean(), 'no style element while the defaults stand');

        add_filter(self::FILTER, function ($colors) {
            $colors['filled'] = '#e11d48';

            return $colors;
        });

        ob_start();
        ReviewModule::printStarColors();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('<style id="fluent-cart-review-star-colors">', $html);
        $this->assertStringContainsString('--fct-review-star-color:#e11d48;', $html);
    }

    public function test_the_review_emails_use_the_resolved_colours(): void
    {
        foreach (['submitted/admin.php', 'replied/customer.php', 'approved/customer.php'] as $template) {
            $source = (string) file_get_contents(FLUENTCART_PLUGIN_PATH . 'app/Views/emails/review/' . $template);

            $this->assertStringNotContainsString('#f59e0b', $source, $template . ' no longer hard-codes the filled star');
            $this->assertStringNotContainsString('#d1d5db', $source, $template . ' no longer hard-codes the empty star');
            $this->assertStringContainsString('ProductReviewRenderer::starColors()', $source, $template . ' reads the resolved colours');
        }
    }
}
