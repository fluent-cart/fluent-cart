<?php

namespace Tests\Integration\Renderer;

use FluentCart\App\Models\Product;
use FluentCart\App\Modules\FluentPlayer\FluentPlayerBridge;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Traits\RendersProductMarkup;

/**
 * Single product page markup for the FluentPlayer demo video.
 *
 * FluentPlayer is stubbed (tests/Support/Stubs/FluentPlayerStubs.php); the
 * assertions pin the DOM contract ImageGallery.js reads:
 *   - .fct-gallery-video-thumb[data-fct-video-media-id] inside the thumb strip
 *   - [data-fct-product-gallery-video] (hidden inline player) inside .fct-product-gallery-thumb
 */
class ProductVideoRenderTest extends WPTestCase
{
    use RendersProductMarkup;

    private ProductFactory $productFactory;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 2) . '/Support/Stubs/FluentPlayerStubs.php';
    }

    public function setUp(): void
    {
        parent::setUp();
        $this->productFactory = new ProductFactory();
        \FluentPlayer\App\Models\Media::reset();
        \FluentPlayer\App\Services\MediaRenderer::reset();
    }

    public function tearDown(): void
    {
        remove_all_filters('fluent_cart/fluent_player/is_active');
        remove_all_filters('wp_doing_ajax');
        remove_all_actions('fluent_cart/fluent_player/failed');
        \FluentPlayer\App\Models\Media::reset();
        \FluentPlayer\App\Services\MediaRenderer::reset();
        $this->resetProductGates();
        wp_cache_flush();
        parent::tearDown();
    }

    private function makeVisibleMedia(int $id, string $title = 'Demo video', string $poster = 'https://cdn.example.com/poster.jpg', string $provider = 'youtube', string $src = 'https://youtu.be/dQw4w9WgXcQ'): void
    {
        \FluentPlayer\App\Models\Media::$visible[$id] = (object) [
            'ID'          => $id,
            'post_title'  => $title,
            'post_status' => 'publish',
            'settings'    => ['posterSrc' => $poster, 'provider' => $provider, 'src' => $src],
        ];
    }

    private function productWithVideo(int ...$mediaIds): Product
    {
        $product = $this->productFactory
            ->withDetailAttributes(['other_info' => ['fluent_player_video' => ['media_ids' => $mediaIds]]])
            ->create();

        return Product::query()->with('detail')->find($product->ID);
    }

    private function productWithImageAndVideo(int ...$mediaIds): Product
    {
        $product = $this->productFactory
            ->withDetailAttributes(['other_info' => ['fluent_player_video' => ['media_ids' => $mediaIds]]])
            ->create();
        update_post_meta($product->ID, 'fluent-products-gallery-image', [['id' => 0, 'url' => 'https://cdn.example.com/product-photo.jpg', 'title' => 'Photo']]);

        return Product::query()->with('detail')->find($product->ID);
    }

    /**
     * @param array<int, int|null> $positions media id => images that precede it.
     */
    private function productWithPositionedVideos(int $imageCount, array $positions): Product
    {
        $product = $this->productFactory
            ->withDetailAttributes(['other_info' => ['fluent_player_video' => [
                'media_ids' => array_keys($positions),
                'positions' => array_values($positions),
            ]]])
            ->create();

        $images = [];
        for ($i = 1; $i <= $imageCount; $i++) {
            $images[] = ['id' => 0, 'url' => 'https://cdn.example.com/photo-' . $i . '.jpg', 'title' => 'Photo ' . $i];
        }
        update_post_meta($product->ID, 'fluent-products-gallery-image', $images);

        return Product::query()->with('detail')->find($product->ID);
    }

    /**
     * @param array<int, array{0:string,1:int,2:\Throwable}> $captured filled with (stage, mediaId, exception)
     */
    private function captureFailures(array &$captured): void
    {
        add_action('fluent_cart/fluent_player/failed', function ($stage, $mediaId, $e) use (&$captured) {
            $captured[] = [$stage, $mediaId, $e];
        }, 10, 3);
    }

    public function test_stubs_make_the_bridge_active(): void
    {
        $this->assertTrue(FluentPlayerBridge::isActive());
    }

    public function test_renders_video_thumb_and_hidden_inline_player(): void
    {
        $this->makeVisibleMedia(41, 'Air Max walkthrough');
        $product = $this->productWithImageAndVideo(41);

        $html = $this->renderSingleProduct($product);

        $this->assertStringContainsString('class="fct-gallery-thumb-control-button fct-gallery-video-thumb"', $html);
        $this->assertStringContainsString('data-fct-video-media-id="41"', $html);
        $this->assertStringContainsString('src="https://cdn.example.com/poster.jpg"', $html, 'video thumb uses the FluentPlayer poster');
        $this->assertStringContainsString('aria-label="Play video: Air Max walkthrough"', $html);
        $this->assertStringContainsString('data-fct-product-gallery-video', $html);
        $this->assertStringContainsString('fp-stub fct-product-video-player', $html, 'the player markup comes from FluentPlayer');
        $this->assertStringContainsString('fct-gallery-thumb-controls', $html, 'one image plus a video needs the thumb strip');
        $this->assertStringNotContainsString('is-video-active', $html, 'with an image the video stays hidden until its thumb is clicked');
        $this->assertStringNotContainsString('data-fct-video-default', $html);
        $this->assertDoesNotMatchRegularExpression('#fct-gallery-video-thumb active#', $html);
        $this->assertCount(1, \FluentPlayer\App\Services\MediaRenderer::$rendered, 'the player is rendered exactly once');
    }

    public function test_video_only_product_shows_the_first_video_in_the_gallery_on_load(): void
    {
        $this->makeVisibleMedia(81, 'First');
        $this->makeVisibleMedia(82, 'Second');
        $product = $this->productWithVideo(81, 82);

        $html = $this->renderSingleProduct($product);

        $this->assertMatchesRegularExpression('#<div class="fct-product-gallery-thumb is-video-active"[^>]*data-fct-video-default="81"#s', $html, 'the region opens in video mode on the first video');
        $this->assertMatchesRegularExpression('#<div class="fct-product-gallery-video is-active"[^>]*data-fct-video-media-id="81"#s', $html, 'the first player is shown');
        $this->assertMatchesRegularExpression('#<div class="fct-product-gallery-video"[^>]*data-fct-video-media-id="82"#s', $html, 'the second player stays hidden');
        $this->assertMatchesRegularExpression('#fct-gallery-video-thumb active"[^>]*data-fct-video-media-id="81"[^>]*aria-pressed="true"[^>]*tabindex="0"#s', $html, 'its thumb is the selected, focusable control');
        $this->assertMatchesRegularExpression('#data-fct-video-media-id="82"[^>]*aria-pressed="false"[^>]*tabindex="-1"#s', $html);
        $this->assertStringContainsString('fct-gallery-thumb-controls', $html, 'two videos still need the strip to switch');
    }

    public function test_a_single_video_without_images_needs_no_thumb_strip(): void
    {
        $this->makeVisibleMedia(83, 'Only video');
        $product = $this->productWithVideo(83);

        $html = $this->renderSingleProduct($product);

        $this->assertMatchesRegularExpression('#<div class="fct-product-gallery-thumb is-video-active"[^>]*data-fct-video-default="83"#s', $html);
        $this->assertMatchesRegularExpression('#<div class="fct-product-gallery-video is-active"[^>]*data-fct-video-media-id="83"#s', $html);
        $this->assertStringNotContainsString('fct-gallery-thumb-controls', $html, 'nothing to switch to: the video is already showing');
        $this->assertStringNotContainsString('fct-gallery-video-thumb', $html);
    }

    public function test_multiple_videos_render_one_thumb_and_one_player_each_in_order(): void
    {
        $this->makeVisibleMedia(51, 'First');
        $this->makeVisibleMedia(52, 'Second');
        $this->makeVisibleMedia(53, 'Third');
        $product = $this->productWithVideo(52, 51, 53);

        $html = $this->renderSingleProduct($product);

        $this->assertSame(3, preg_match_all('#class="fct-gallery-thumb-control-button fct-gallery-video-thumb( active)?"#', $html), 'one thumb per video');
        $this->assertSame(3, substr_count($html, 'data-fct-product-gallery-video'), 'one hidden player per video');
        $this->assertMatchesRegularExpression(
            '#data-fct-video-media-id="52".*?data-fct-video-media-id="51".*?data-fct-video-media-id="53"#s',
            $html,
            'thumbs follow the configured order'
        );
        $this->assertStringContainsString('aria-label="Play video: Second"', $html);
        $this->assertCount(3, \FluentPlayer\App\Services\MediaRenderer::$rendered);
    }

    public function test_a_hidden_media_in_the_list_is_skipped_but_the_others_render(): void
    {
        $this->makeVisibleMedia(54, 'Visible');
        $product = $this->productWithImageAndVideo(99, 54);

        $html = $this->renderSingleProduct($product);

        $this->assertSame(1, substr_count($html, 'class="fct-gallery-thumb-control-button fct-gallery-video-thumb"'));
        $this->assertStringContainsString('data-fct-video-media-id="54"', $html);
        $this->assertStringNotContainsString('data-fct-video-media-id="99"', $html);
        $this->assertSame([[54, 'fct-product-video-player']], \FluentPlayer\App\Services\MediaRenderer::$rendered);
    }

    public function test_thumb_strip_is_kept_for_a_single_image_when_a_video_exists(): void
    {
        $this->makeVisibleMedia(43);
        $product = $this->productWithImageAndVideo(43);

        $html = $this->renderSingleProduct($product);

        $this->assertStringContainsString('src="https://cdn.example.com/product-photo.jpg"', $html, 'precondition: the single gallery image renders');
        $this->assertStringContainsString('fct-gallery-thumb-controls', $html, 'one image alone would drop the strip; the video keeps it');
        $this->assertMatchesRegularExpression(
            '#<div class="fct-gallery-thumb-controls".*?data-url="https://cdn.example.com/product-photo.jpg".*?fct-gallery-video-thumb#s',
            $html,
            'the video thumb sits inside the thumb strip after the image thumb'
        );
    }

    public function test_video_thumbs_stay_out_of_the_tab_order_when_an_image_holds_the_roving_slot(): void
    {
        $this->makeVisibleMedia(71);
        $this->makeVisibleMedia(72);
        $product = $this->productWithImageAndVideo(71, 72);

        $html = $this->renderSingleProduct($product);

        $this->assertMatchesRegularExpression('#data-url="https://cdn.example.com/product-photo.jpg"[^>]*tabindex="0"#s', $html, 'the image thumb is the focusable control');
        $this->assertMatchesRegularExpression('#data-fct-video-media-id="71"[^>]*tabindex="-1"#s', $html);
        $this->assertMatchesRegularExpression('#data-fct-video-media-id="72"[^>]*tabindex="-1"#s', $html);
    }

    public function test_video_only_product_makes_the_first_video_thumb_focusable(): void
    {
        $this->makeVisibleMedia(73);
        $this->makeVisibleMedia(74);
        $product = $this->productWithVideo(73, 74);

        $html = $this->renderSingleProduct($product);

        $this->assertMatchesRegularExpression('#data-fct-video-media-id="73"[^>]*tabindex="0"#s', $html, 'without image thumbs the strip would be unreachable by keyboard');
        $this->assertMatchesRegularExpression('#data-fct-video-media-id="74"[^>]*tabindex="-1"#s', $html, 'only one control holds the roving tabindex');
        $this->assertSame(1, preg_match_all('#data-fluent-cart-thumb-control-button[^>]*tabindex="0"#s', $html), 'exactly one thumb control is in the tab order');
    }

    public function test_video_thumb_renders_at_the_position_the_admin_dragged_it_to(): void
    {
        $this->makeVisibleMedia(61, 'Middle');
        $product = $this->productWithPositionedVideos(3, [61 => 2]);

        $html = $this->renderSingleProduct($product);

        $this->assertMatchesRegularExpression(
            '#photo-1\.jpg.*?photo-2\.jpg.*?data-fct-video-media-id="61".*?photo-3\.jpg#s',
            $html,
            'a video placed after two images sits between the second and third image thumb'
        );
    }

    public function test_a_video_without_a_position_still_renders_after_every_image(): void
    {
        $this->makeVisibleMedia(62, 'Legacy');
        $product = $this->productWithPositionedVideos(2, [62 => null]);

        $html = $this->renderSingleProduct($product);

        $this->assertMatchesRegularExpression(
            '#photo-1\.jpg.*?photo-2\.jpg.*?data-fct-video-media-id="62"#s',
            $html,
            'videos saved before ordering existed keep their place at the end of the strip'
        );
    }

    public function test_a_video_dragged_in_front_of_every_image_leads_the_strip_and_opens_the_gallery(): void
    {
        $this->makeVisibleMedia(63, 'Leading');
        $product = $this->productWithPositionedVideos(2, [63 => 0]);

        $html = $this->renderSingleProduct($product);

        $this->assertMatchesRegularExpression('#data-fct-video-media-id="63".*?photo-1\.jpg#s', $html, 'the video thumb comes before the first image thumb');
        $this->assertStringContainsString('data-fct-video-default="63"', $html, 'the page opens on the video');
        $this->assertStringContainsString('is-video-active', $html);
        $this->assertMatchesRegularExpression('#fct-gallery-video-thumb active#', $html);
        $this->assertMatchesRegularExpression('#data-fct-video-media-id="63"[^>]*aria-pressed="true"#s', $html);
        $this->assertMatchesRegularExpression('#data-fct-video-media-id="63"[^>]*tabindex="0"#s', $html);
        $this->assertSame(1, preg_match_all('#data-fluent-cart-thumb-control-button[^>]*tabindex="0"#s', $html), 'exactly one thumb control is in the tab order');
        $this->assertSame(0, preg_match_all('#fct-gallery-thumb-control-button active[^>]*data-url=#s', $html), 'no image thumb takes the selected slot');
    }

    public function test_the_featured_image_stays_the_first_image_when_a_video_leads_the_gallery(): void
    {
        $this->makeVisibleMedia(64, 'Leading');
        $product = $this->productWithPositionedVideos(2, [64 => 0]);

        $html = $this->renderSingleProduct($product);

        $this->assertMatchesRegularExpression(
            '#data-fluent-cart-single-product-page-product-thumbnail[^>]*data-default-image-url="https://cdn\.example\.com/photo-1\.jpg"#s',
            $html,
            'the main image element still falls back to the first gallery image'
        );
    }

    public function test_two_videos_can_share_the_leading_slot(): void
    {
        $this->makeVisibleMedia(65, 'One');
        $this->makeVisibleMedia(66, 'Two');
        $product = $this->productWithPositionedVideos(1, [65 => 0, 66 => 0]);

        $html = $this->renderSingleProduct($product);

        $this->assertMatchesRegularExpression('#data-fct-video-media-id="65".*?data-fct-video-media-id="66".*?photo-1\.jpg#s', $html);
        $this->assertStringContainsString('data-fct-video-default="65"', $html, 'the first of them opens the gallery');
        $this->assertMatchesRegularExpression('#data-fct-video-media-id="66"[^>]*aria-pressed="false"#s', $html);
    }

    public function test_video_thumb_uses_the_youtube_thumbnail_when_fluent_player_has_no_poster(): void
    {
        $this->makeVisibleMedia(44, 'No poster', '', 'youtube', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ');
        $product = $this->productWithImageAndVideo(44);

        $html = $this->renderSingleProduct($product);

        $this->assertMatchesRegularExpression(
            '#fct-gallery-video-thumb.*?<img\s+class="fct-gallery-control-thumb"\s+src="https://i\.ytimg\.com/vi/dQw4w9WgXcQ/hqdefault\.jpg"#s',
            $html,
            'a YouTube media without a saved poster shows the same YouTube thumbnail FluentPlayer falls back to'
        );
    }

    public function test_video_thumb_uses_a_placeholder_not_the_product_image_without_any_poster(): void
    {
        $this->makeVisibleMedia(49, 'No poster', '', 'external', 'https://cdn.example.com/demo.mp4');
        $product = $this->productFactory
            ->withDetailAttributes(['other_info' => ['fluent_player_video' => ['media_ids' => [49]]]])
            ->create();
        update_post_meta($product->ID, 'fluent-products-gallery-image', [['id' => 0, 'url' => 'https://cdn.example.com/product-photo.jpg', 'title' => 'Photo']]);
        $product = Product::query()->with('detail')->find($product->ID);

        $html = $this->renderSingleProduct($product);

        $this->assertMatchesRegularExpression(
            '#fct-gallery-video-thumb.*?<img\s+class="fct-gallery-control-thumb"\s+src="[^"]*placeholder\.svg"#s',
            $html,
            'without any FluentPlayer poster the thumb shows a neutral placeholder'
        );
        $this->assertDoesNotMatchRegularExpression(
            '#fct-gallery-video-thumb.*?<img\s+class="fct-gallery-control-thumb"\s+src="https://cdn.example.com/product-photo.jpg"#s',
            $html,
            'the product photo is never used as the video poster'
        );
    }

    public function test_hidden_or_deleted_media_renders_nothing(): void
    {
        $product = $this->productWithVideo(45);

        $html = $this->renderSingleProduct($product);

        $this->assertStringNotContainsString('fct-gallery-video-thumb', $html);
        $this->assertStringNotContainsString('data-fct-product-gallery-video', $html);
        $this->assertSame([], \FluentPlayer\App\Services\MediaRenderer::$rendered, 'never asks FluentPlayer to render an invisible media');
    }

    public function test_a_media_whose_player_fails_is_dropped_from_thumbs_and_players_alike(): void
    {
        $this->makeVisibleMedia(61, 'Works');
        $this->makeVisibleMedia(62, 'Broken');
        \FluentPlayer\App\Services\MediaRenderer::$throwFor = [62];
        $failures = [];
        $this->captureFailures($failures);
        $product = $this->productWithImageAndVideo(61, 62);

        $html = $this->renderSingleProduct($product);

        $this->assertStringContainsString('fct-product-gallery-thumb', $html, 'the gallery itself still renders');
        $this->assertStringContainsString('data-fct-video-media-id="61"', $html);
        $this->assertStringNotContainsString('data-fct-video-media-id="62"', $html, 'a thumb without a player would leave the gallery blank when clicked');
        $this->assertSame(1, substr_count($html, 'class="fct-gallery-thumb-control-button fct-gallery-video-thumb"'));
        $this->assertSame(1, substr_count($html, 'data-fct-product-gallery-video'));
        $this->assertCount(1, $failures, 'the failure is reported through fluent_cart/fluent_player/failed');
        $this->assertSame('render', $failures[0][0]);
        $this->assertSame(62, $failures[0][1]);
        $this->assertInstanceOf(\RuntimeException::class, $failures[0][2]);
    }

    public function test_a_media_whose_lookup_fails_is_skipped_and_reported(): void
    {
        $this->makeVisibleMedia(63, 'Works');
        \FluentPlayer\App\Models\Media::$throwFor = [64];
        $failures = [];
        $this->captureFailures($failures);
        $product = $this->productWithVideo(64, 63);

        $html = $this->renderSingleProduct($product);

        $this->assertStringContainsString('data-fct-video-media-id="63"', $html);
        $this->assertStringNotContainsString('data-fct-video-media-id="64"', $html);
        $this->assertSame([[63, 'fct-product-video-player']], \FluentPlayer\App\Services\MediaRenderer::$rendered, 'FluentPlayer is never asked to render a media it could not load');
        $this->assertSame([['find', 64]], array_map(function ($f) { return [$f[0], $f[1]]; }, $failures));
    }

    public function test_product_without_config_renders_nothing(): void
    {
        $this->makeVisibleMedia(46);
        $product = Product::query()->with('detail')->find($this->productFactory->create()->ID);

        $html = $this->renderSingleProduct($product);

        $this->assertStringNotContainsString('fct-gallery-video-thumb', $html);
        $this->assertSame([], \FluentPlayer\App\Services\MediaRenderer::$rendered);
    }

    public function test_integration_switched_off_renders_nothing_even_with_config(): void
    {
        add_filter('fluent_cart/fluent_player/is_active', '__return_false');
        $this->makeVisibleMedia(47);
        $product = $this->productWithVideo(47);

        $html = $this->renderSingleProduct($product);

        $this->assertFalse(FluentPlayerBridge::isActive());
        $this->assertStringNotContainsString('fct-gallery-video-thumb', $html);
        $this->assertSame([], \FluentPlayer\App\Services\MediaRenderer::$rendered);
    }

    public function test_quick_view_ajax_render_skips_the_video(): void
    {
        add_filter('wp_doing_ajax', '__return_true');
        $this->makeVisibleMedia(48);
        $product = $this->productWithVideo(48);

        $html = $this->renderSingleProduct($product);

        $this->assertStringContainsString('fct-product-gallery-thumb', $html, 'the gallery itself still renders');
        $this->assertStringNotContainsString('fct-gallery-video-thumb', $html);
        $this->assertStringNotContainsString('data-fct-product-gallery-video', $html);
        $this->assertSame([], \FluentPlayer\App\Services\MediaRenderer::$rendered, 'no player is rendered into an AJAX fragment');
    }

    public function test_admin_app_config_is_populated_when_active(): void
    {
        wp_set_current_user(1);

        $config = FluentPlayerBridge::adminAppConfig();

        $this->assertTrue($config['active']);
        $this->assertSame('course', $config['defaultPresetSlug']);
        $this->assertStringEndsWith('/fluent-player/v2', $config['restUrl']);
        $this->assertStringContainsString('post.php?action=edit&post=', $config['editUrlBase']);
        $this->assertTrue($config['canAuthor'], 'an administrator has edit_others_posts');
        $this->assertFalse($config['hasPro'], 'FLUENT_PLAYER_PRO_VERSION is not defined in the test install');

        wp_set_current_user(0);
        $this->assertFalse(FluentPlayerBridge::adminAppConfig()['canAuthor'], 'a visitor cannot author');
    }
}
