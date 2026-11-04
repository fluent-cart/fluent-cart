<?php

namespace Tests\Integration\Blocks;

use FluentCart\App\Services\ProductReviewService;
use FluentCart\App\Services\Renderer\ReviewListRenderer;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Flush and backdrop are Pro, and a site without it draws the plain gallery.
 *
 * The other half of the contract proved in IntegrationPro/Reviews/
 * ReviewMediaStylingTest, which cannot also run here: this suite loads core
 * alone by design, which is exactly what makes it the only lane that can prove
 * the refusal.
 *
 * It matters that the refusal happens at render rather than at save. A widget
 * or a block saved while Pro was active keeps its stored intent; what changes
 * when the subscription lapses is what the page draws with it. Asserting the
 * option still travels and the modifier still does not is the difference
 * between a gate and a migration.
 *
 * The gate is a constant check and deliberately not filterable — see
 * tests/Cases/TESTABILITY-DEBT.md #12, where a runtime seam on a commercial
 * gate was built and reverted because any site snippet could then open it.
 */
class ReviewMediaStylingGateTest extends WPTestCase
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected function reviewWithPhoto(): array
    {
        return [[
            'id'            => 1,
            'reviewer_name' => 'Dana Buyer',
            'review'        => 'Fits well.',
            'rating'        => 5,
            'created_at'    => gmdate('Y-m-d H:i:s'),
            'media'         => [[
                'url'  => 'https://example.test/shoe.jpg',
                'full' => 'https://example.test/shoe.jpg',
                'type' => 'image',
            ]],
        ]];
    }

    public function test_photo_styling_is_closed_in_this_lane(): void
    {
        $this->assertFalse(
            ProductReviewService::isPhotoStylingAllowed(),
            'this suite runs Pro-absent, which is what makes the refusal provable'
        );
    }

    public function test_flush_is_refused_without_pro(): void
    {
        $html = (new ReviewListRenderer(['media_flush' => true]))
            ->renderReviewItems($this->reviewWithPhoto());

        $this->assertStringContainsString('fct-review-media-gallery', $html, 'the photograph still shows');
        $this->assertStringNotContainsString('--flush', $html, 'but not as the top of the card');
    }

    public function test_backdrop_is_refused_without_pro(): void
    {
        $html = (new ReviewListRenderer(['media_backdrop' => true]))
            ->renderReviewItems($this->reviewWithPhoto());

        $this->assertStringContainsString('fct-review-media-gallery', $html, 'the photograph still shows');
        $this->assertStringNotContainsString('--backdrop', $html, 'but not filling the card');
    }

    /**
     * Free settings are untouched by the gate: a lapsed site keeps the tile
     * size and the full-width arrangement it chose, and loses only the two
     * styles that were Pro.
     */
    public function test_the_free_media_settings_still_apply(): void
    {
        $html = (new ReviewListRenderer([
            'media_full_width' => true,
            'media_flush'      => true,
        ]))->renderReviewItems($this->reviewWithPhoto());

        $this->assertStringContainsString('--fct-review-thumb-w:100%', $html, 'full width is free');
        $this->assertStringNotContainsString('--flush', $html, 'flush is not');
    }
}
