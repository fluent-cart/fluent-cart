<?php

namespace Tests\Integration\Services\Renderer;

use FluentCart\App\Services\Renderer\ReviewThreadMarkup;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * The review photo thumbnail is what Reviews.js opens in the product
 * gallery's lightbox. It has to be reachable from the keyboard as a
 * button, and carry the full-size URL and type the script reads.
 */
class ReviewMediaThumbMarkupTest extends WPTestCase
{
    public function test_an_image_thumb_is_a_keyboard_button_carrying_its_url(): void
    {
        $html = ReviewThreadMarkup::mediaItemHtml(['url' => 'https://example.test/photo.png', 'type' => 'image'], 'list');

        $this->assertStringContainsString('class="fct-review-media-thumb"', $html);
        $this->assertStringContainsString('role="button"', $html);
        $this->assertStringContainsString('tabindex="0"', $html);
        $this->assertStringContainsString('aria-label="View photo"', $html);
        $this->assertStringContainsString('data-media-url="https://example.test/photo.png"', $html);
        $this->assertStringContainsString('data-media-type="image"', $html);
    }

    public function test_a_video_thumb_is_a_plain_box_with_its_own_play_button(): void
    {
        $html = ReviewThreadMarkup::mediaItemHtml(['url' => 'https://example.test/clip.mp4', 'type' => 'video'], 'list');

        // The box, not the video, carries the thumb class; the play overlay is
        // the one pressable thing, so the video never sits inside a button.
        $this->assertStringStartsWith('<span class="fct-review-media-thumb fct-review-media-video"', $html);
        $this->assertStringContainsString('<video src="https://example.test/clip.mp4"', $html);
        $this->assertStringContainsString('class="fct-review-media-play"', $html);
        $this->assertStringContainsString('data-media-type="video"', $html);
        $this->assertStringNotContainsString('role="button"', $html);
    }

    public function test_an_item_without_a_url_renders_nothing(): void
    {
        $this->assertSame('', ReviewThreadMarkup::mediaItemHtml(['type' => 'image'], 'list'));
    }
}
