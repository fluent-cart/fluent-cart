<?php

namespace Tests\Integration\Services\Renderer;

use FluentCart\App\Services\Renderer\ProductCardRender;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * The card rating names itself to assistive technology.
 *
 * The stars are aria-hidden and the rating is carried by an aria-label on the
 * wrapper — but the wrapper was a role-less <div>, and ARIA does not allow a
 * name on a generic element, so screen readers dropped it and the rating was
 * never announced. role="img" makes the wrapper one named graphic.
 */
class ProductCardRatingRoleTest extends WPTestCase
{
    /**
     * @return ProductCardRender
     */
    protected function ratedCard(): ProductCardRender
    {
        $product = (new ProductFactory())
            ->withDetailAttributes(['other_info' => ['average_rating' => 4.5, 'review_count' => 3]])
            ->create(['post_title' => 'Rated Card Product']);

        return new ProductCardRender($product);
    }

    /**
     * @param callable $render
     * @return string
     */
    protected function capture(callable $render): string
    {
        ob_start();
        $render();

        return (string) ob_get_clean();
    }

    public function test_the_rating_block_is_a_named_image(): void
    {
        $card = $this->ratedCard();
        $html = $this->capture(function () use ($card) {
            $card->renderStarRatingBlock('class="fct-product-card-rating"');
        });

        $this->assertMatchesRegularExpression('#<div [^>]*role="img"[^>]*aria-label="Rated 4.5 out of 5 based on 3 reviews"#', $html);
    }

    public function test_the_card_rating_is_a_named_image(): void
    {
        $card = $this->ratedCard();
        $html = $this->capture(function () use ($card) {
            $card->renderStarRating();
        });

        $this->assertNotSame('', trim($html), 'the card rating rendered (reviews module active)');
        $this->assertMatchesRegularExpression('#<div class="fct-product-card-rating" role="img" aria-label="Rated 4.5 out of 5 based on 3 reviews"#', $html);
    }
}
