<?php

namespace Tests\Integration\Services\Renderer;

use FluentCart\App\Services\Renderer\ProductReviewRenderer;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * The invitation to write a review, when it stands in for the score card.
 *
 * Photo Strip is the preset that asks for this: a band of photographs wants no
 * score card beside it, but it still wants the invitation. The composed section
 * builds that as a summary group holding the button, and the group is what
 * gives it its border and padding -- so a rendered section that dropped the
 * button straight into the column showed a bare button where the block editor
 * showed a card.
 */
class ReviewSummaryCtaTest extends WPTestCase
{
    /** @var int */
    protected $productId;

    public function setUp(): void
    {
        parent::setUp();

        $this->productId = (int) (new ProductFactory())->create(['post_title' => 'Strip Product'])->ID;
    }

    /**
     * @param array $options
     * @return string
     */
    protected function renderSection(array $options): string
    {
        ob_start();
        (new ProductReviewRenderer($this->productId, $options))->render();

        return (string) ob_get_clean();
    }

    public function test_the_cta_column_draws_the_summary_card(): void
    {
        $html = $this->renderSection(['summaryMode' => 'cta', 'showSummary' => true]);

        $this->assertStringContainsString(
            'fct-review-summary-group',
            $html,
            'the card the composed section builds around it'
        );
        $this->assertMatchesRegularExpression(
            '#fct-review-summary-group[^>]*>\s*<[^>]*fct-reviews-summary-write-btn#s',
            $html,
            'with the invitation inside it'
        );
    }

    /**
     * The standalone button is not a card. It is dropped into whatever an
     * editor put it in, so the wrapper belongs at the section's call site
     * rather than inside the method both of them share.
     */
    public function test_the_standalone_button_is_not_wrapped_in_a_card(): void
    {
        $html = do_blocks(
            '<!-- wp:fluent-cart/write-a-review-button '
            . wp_json_encode(['query_type' => 'custom', 'product_id' => (string) $this->productId])
            . ' /-->'
        );

        $this->assertStringContainsString('fct-reviews-summary-write-btn', $html, 'the button drew');
        $this->assertStringNotContainsString('fct-review-summary-group', $html, 'and nothing boxed it');
    }

    /**
     * The ordinary score card is unchanged: it is its own card already, and
     * wrapping it in a second one would double the border.
     */
    public function test_the_score_card_column_is_untouched(): void
    {
        $html = $this->renderSection(['summaryMode' => 'side', 'showSummary' => true]);

        $this->assertStringNotContainsString('fct-review-summary-group', $html);
    }
}
