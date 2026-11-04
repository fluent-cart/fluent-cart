<?php

namespace Tests\Integration\Blocks;

use FluentCart\App\Services\Renderer\ReviewListRenderer;
use FluentCart\App\Services\Reviews\LayoutPresets;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * How a row the renderer draws itself is arranged.
 *
 * LayoutPresets::row() has built four arrangements since the presets were
 * written — photographs first, stars as the headline, the badge as a sign-off,
 * and the byline dropped altogether — but only the Gutenberg template could
 * express them. Every other caller (the shortcode, the product page, the
 * Elementor widget) went through ReviewListRenderer, which emitted one fixed
 * order, so a photo strip drew its header and a testimonial kept its badge
 * beside the name.
 *
 * Free, unlike the styles in ReviewMediaStylingGateTest: the arrangement is
 * ordinary layout, and it is the presets that choose it which are Pro.
 */
class ReviewItemArrangementTest extends WPTestCase
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected function review(): array
    {
        return [[
            'id'            => 7,
            'reviewer_name' => 'Dana Buyer',
            'title'         => 'Great shoes',
            'content'       => 'Fits well.',
            'rating'        => 5,
            'is_verified'   => 1,
            'created_at'    => gmdate('Y-m-d H:i:s'),
            'media'         => [[
                'url'  => 'https://example.test/shoe.jpg',
                'full' => 'https://example.test/shoe.jpg',
                'type' => 'image',
            ]],
        ]];
    }

    /**
     * The order of the landmarks a row drew, so a test can say "photograph
     * before byline" without pinning the markup between them.
     *
     * @return array<int, string>
     */
    protected function orderIn(string $html): array
    {
        preg_match_all(
            '/class="(fct-review-media-gallery|fct-review-item-header|fct-review-item-stars|fct-review-item-signoff|fct-review-item-title)[ "]/',
            $html,
            $matches
        );

        return $matches[1];
    }

    public function test_the_default_row_is_unchanged(): void
    {
        $order = $this->orderIn((new ReviewListRenderer([]))->renderReviewItems($this->review()));

        $this->assertSame(
            ['fct-review-item-header', 'fct-review-item-stars', 'fct-review-item-title', 'fct-review-media-gallery'],
            $order,
            'a caller that asks for no arrangement gets the row it always got'
        );
    }

    public function test_photos_first_puts_the_photograph_above_the_words(): void
    {
        $order = $this->orderIn(
            (new ReviewListRenderer(['photos_first' => true]))->renderReviewItems($this->review())
        );

        $this->assertSame('fct-review-media-gallery', $order[0], 'the picture sells the card');
        $this->assertContains('fct-review-item-header', $order, 'the byline is still there');
        $this->assertGreaterThan(
            array_search('fct-review-media-gallery', $order, true),
            array_search('fct-review-item-header', $order, true),
            'and it comes after'
        );
    }

    /**
     * Stars as the headline, not beside the name — a wall of cards is read by
     * its stars long before any of its sentences.
     */
    public function test_rating_first_lifts_the_stars_out_of_the_name_line(): void
    {
        $html = (new ReviewListRenderer(['rating_first' => true]))->renderReviewItems($this->review());

        $this->assertStringContainsString('<div class="fct-review-item-stars">', $html, 'block level, as its own row');
        $this->assertStringNotContainsString(
            '<span class="fct-review-item-stars">',
            $html,
            'and not also inside the name line'
        );
        // Where it lands is the other flag's business: on its own the stars
        // still follow the byline, exactly as LayoutPresets::row() orders
        // them. Only photographs-first moves the byline below both.
        $this->assertSame(
            ['fct-review-item-header', 'fct-review-item-stars', 'fct-review-item-title', 'fct-review-media-gallery'],
            $this->orderIn($html)
        );
    }

    /**
     * Photo Wall asks for both, and the pair is what puts the stars over the
     * photograph with the byline underneath.
     */
    public function test_photos_first_and_rating_first_compose(): void
    {
        $html = (new ReviewListRenderer(['photos_first' => true, 'rating_first' => true]))
            ->renderReviewItems($this->review());

        $this->assertSame(
            ['fct-review-media-gallery', 'fct-review-item-stars', 'fct-review-item-title', 'fct-review-item-header'],
            $this->orderIn($html),
            'picture, headline, words, byline'
        );
    }

    /**
     * On a card the eye reads downwards, so the badge is the last word rather
     * than part of the greeting.
     */
    public function test_badge_last_signs_the_card_off(): void
    {
        $html = (new ReviewListRenderer(['badge_last' => true]))->renderReviewItems($this->review());

        $this->assertStringContainsString('fct-review-item-signoff', $html);
        $order = $this->orderIn($html);
        $this->assertSame('fct-review-item-signoff', end($order), 'and it is the last thing in the card');
    }

    public function test_the_badge_stays_on_the_name_line_by_default(): void
    {
        $html = (new ReviewListRenderer([]))->renderReviewItems($this->review());

        $this->assertStringContainsString('fct-review-verified', $html, 'the badge still shows');
        $this->assertStringNotContainsString('fct-review-item-signoff', $html, 'just not as a sign-off');
    }

    /**
     * A photo strip wants the photograph and nothing else. Dropped whole
     * rather than field by field, because an empty header still draws its box.
     */
    public function test_show_meta_off_drops_the_whole_byline(): void
    {
        $html = (new ReviewListRenderer(['show_meta' => false]))->renderReviewItems($this->review());

        $this->assertStringNotContainsString('fct-review-item-header', $html);
        $this->assertStringNotContainsString('Dana Buyer', $html);
        $this->assertStringContainsString('fct-review-item-title', $html, 'the rest of the card is untouched');
    }

    public function test_a_preset_class_lands_on_the_card(): void
    {
        $html = (new ReviewListRenderer(['item_class' => 'fct-review-testimonial']))
            ->renderReviewItems($this->review());

        $this->assertStringContainsString('class="fct-review-item fct-review-testimonial"', $html);
    }

    /**
     * The class makes the round trip through the public reviews endpoint, so
     * by the second page it is request input. Anything unrecognised becomes
     * nothing rather than markup.
     */
    public function test_an_unknown_card_class_is_refused(): void
    {
        foreach (['evil" onload="x', 'fct-not-a-preset', '<script>', ' '] as $candidate) {
            $this->assertSame('', ReviewListRenderer::itemClass($candidate), $candidate);
        }

        $html = (new ReviewListRenderer(['item_class' => 'evil" onload="x']))
            ->renderReviewItems($this->review());

        $this->assertStringContainsString('class="fct-review-item"', $html);
        $this->assertStringNotContainsString('onload', $html);
    }

    public function test_the_known_card_classes_are_accepted(): void
    {
        foreach (ReviewListRenderer::itemClasses() as $class) {
            $this->assertSame($class, ReviewListRenderer::itemClass($class));
        }
    }

    /**
     * The footer is drawn whenever the row has one, with or without a reply in
     * it.
     *
     * PRO appends the Helpful buttons to this element from the browser, and it
     * looks the element up rather than creating one. A row that only drew the
     * footer when the review happened to carry a reply sent the buttons to the
     * card instead, outside the reach of the footer's own button styling -- so
     * two reviews in the same list wore different Helpful buttons, and neither
     * matched the composed row, whose votes sit in a footer group the template
     * always builds.
     */
    public function test_the_footer_is_drawn_whether_or_not_there_is_a_reply(): void
    {
        foreach ([0, 2] as $replyCount) {
            $review = $this->review();
            $review[0]['reply_count'] = $replyCount;

            $this->assertStringContainsString(
                'fct-review-footer',
                (new ReviewListRenderer([]))->renderReviewItems($review),
                'reply_count ' . $replyCount
            );
        }
    }

    /**
     * And it is empty when there is nothing to put in it, which is what
     * reviews.scss hides with :empty -- so a row without replies or votes does
     * not carry a blank band.
     */
    public function test_an_empty_footer_stays_empty(): void
    {
        $review = $this->review();
        $review[0]['reply_count'] = 0;

        $html = (new ReviewListRenderer([]))->renderReviewItems($review);

        $this->assertStringContainsString(
            '<div class="fct-review-footer"></div>',
            $html,
            'nothing inside it for the :empty rule to miss'
        );
    }

    /**
     * A row told to draw no footer still draws none.
     */
    public function test_no_footer_when_the_row_asks_for_none(): void
    {
        $html = (new ReviewListRenderer(['show_footer' => false]))
            ->renderReviewItems($this->review());

        $this->assertStringNotContainsString('fct-review-footer', $html);
    }

    /**
     * Photo Grid puts its byline last, and reviews.scss gives it air above by
     * hanging a rule on the card's class.
     *
     * Pinned because the rule is invisible from the stylesheet's side: it
     * depends on the preset declaring the class, the whitelist accepting it and
     * the renderer printing it, and any one of those going quiet takes the
     * spacing with it without failing anything else. The same class is what
     * carries the rule into Elementor, which draws its own markup but loads
     * this stylesheet.
     */
    public function test_the_photo_grid_card_carries_its_class(): void
    {
        $class = (string) (((array) (LayoutPresets::builderSettings('photo-grid')['item'] ?? []))['class'] ?? '');

        $this->assertSame('fct-review-photo-grid', $class, 'the preset declares it');
        $this->assertSame($class, ReviewListRenderer::itemClass($class), 'the whitelist accepts it');

        $html = (new ReviewListRenderer(['item_class' => $class, 'photos_first' => true]))
            ->renderReviewItems($this->review());

        $this->assertStringContainsString(
            'class="fct-review-item fct-review-photo-grid"',
            $html,
            'and the card wears it'
        );
    }

    /**
     * The rendered row wraps its byline in a header element.
     *
     * Pinned opposite the composed row, which has no such wrapper: its byline
     * group is the card's own child. reviews.scss has to name both spellings to
     * space the byline in Photo Grid, and naming only this one styled the
     * widget while leaving the block editor untouched -- which is exactly the
     * shape of bug this pair of assertions exists to catch.
     */
    public function test_a_rendered_row_wraps_its_byline_in_a_header(): void
    {
        $html = (new ReviewListRenderer([]))->renderReviewItems($this->review());

        $this->assertStringContainsString(
            '<div class="fct-review-item-header">',
            $html,
            'the wrapper the stylesheet targets on this path'
        );
        $this->assertStringContainsString(
            'fct-review-item-header-left',
            $html,
            'with the byline group inside it, not beside it'
        );
    }
}
