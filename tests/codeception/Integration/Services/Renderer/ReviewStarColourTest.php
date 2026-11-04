<?php

namespace Tests\Integration\Services\Renderer;

use FluentCart\App\Services\Renderer\ProductReviewRenderer;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * The star colour a block asks for, and the one it gets when it asks for none.
 *
 * The blocks default their star colour to #f59e0b and the renderer treated that
 * value as "no override", printing nothing — while reviews.scss fell back to a
 * different gold, #F7A539. So a merchant who picked #f59e0b, the colour the
 * picker showed, got #F7A539 on the page. The stylesheet now defaults to the
 * renderer's constant, and any other colour — in any letter case — is printed.
 */
class ReviewStarColourTest extends WPTestCase
{
    /** @var int */
    protected $productId;

    public function setUp(): void
    {
        parent::setUp();

        $this->productId = (int) (new ProductFactory())->create(['post_title' => 'Star Colour Product'])->ID;
    }

    /**
     * @param string $method
     * @param array $options
     * @return string
     */
    protected function capture(string $method, array $options): string
    {
        ob_start();
        (new ProductReviewRenderer($this->productId, $options))->{$method}();

        return (string) ob_get_clean();
    }

    public function test_the_default_is_one_named_colour(): void
    {
        $this->assertSame('#f59e0b', ProductReviewRenderer::DEFAULT_STAR_COLOR);
    }

    public function test_the_default_prints_no_override_on_any_surface(): void
    {
        foreach (['render', 'renderForm', 'renderSummarySection'] as $method) {
            $html = $this->capture($method, ['starColor' => ProductReviewRenderer::DEFAULT_STAR_COLOR, 'container' => 'none']);

            $this->assertNotSame('', trim($html), $method . ' rendered');
            $this->assertStringNotContainsString('--fct-star-color', $html, $method . ' leaves the stylesheet default');
        }
    }

    public function test_the_default_in_capitals_is_still_the_default(): void
    {
        $html = $this->capture('render', ['starColor' => '#F59E0B']);

        $this->assertStringNotContainsString('--fct-star-color', $html);
    }

    public function test_any_other_colour_is_printed_on_every_surface(): void
    {
        foreach (['render', 'renderForm', 'renderSummarySection'] as $method) {
            $html = $this->capture($method, ['starColor' => '#F7A539', 'container' => 'none']);

            $this->assertStringContainsString('--fct-star-color: #F7A539', $html, $method . ' prints the chosen colour');
        }
    }
}
