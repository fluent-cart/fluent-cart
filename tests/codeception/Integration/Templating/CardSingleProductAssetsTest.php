<?php

namespace Tests\Integration\Templating;

use FluentCart\App\App;
use FluentCart\App\Modules\Templating\AssetLoader;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * A product card loads what its Quick View needs from the single product bundle, without the
 * reviews section's scripts and styles; a product page still loads all of it.
 */
class CardSingleProductAssetsTest extends WPTestCase
{
    private $previousEnv;

    public function setUp(): void
    {
        parent::setUp();
        // In dev mode a queued source is the file's path under resources/, the form these tests match.
        $this->previousEnv = App::config()->get('app.env');
        App::config()->set('app.env', 'dev');
        $GLOBALS['wp_styles'] = new \WP_Styles();
        $GLOBALS['wp_scripts'] = new \WP_Scripts();
    }

    public function tearDown(): void
    {
        App::config()->set('app.env', $this->previousEnv);
        parent::tearDown();
    }

    private function queuedSources(): array
    {
        // Styles and scripts share handle names, so each is keyed by its kind.
        $sources = [];
        foreach (['style' => wp_styles(), 'script' => wp_scripts()] as $kind => $deps) {
            foreach ($deps->queue as $handle) {
                $sources[$kind . ':' . $handle] = (string) ($deps->registered[$handle]->src ?? '');
            }
        }

        return $sources;
    }

    private function queues(string $file): bool
    {
        foreach ($this->queuedSources() as $src) {
            if (strpos($src, $file) !== false) {
                return true;
            }
        }

        return false;
    }

    public function test_a_shop_grid_loads_the_quick_view_assets_without_the_reviews_section(): void
    {
        (new ProductFactory())->create(['post_title' => 'Card assets product', 'post_status' => 'publish']);
        do_shortcode('[fluent_cart_products card_layout="compact" per_page="4"]');

        foreach (['single-product/SingleProduct.js', 'single-product/single-product.scss', 'buttons/add-to-cart/style/style.scss'] as $file) {
            $this->assertTrue($this->queues($file), $file . ' is what the Quick View modal needs');
        }
        foreach (['single-product/reviews.scss', 'single-product/Reviews.js', 'single-product/ReviewForm.js'] as $file) {
            $this->assertFalse($this->queues($file), $file . ' belongs to the product page\'s reviews section');
        }
    }

    public function test_core_classic_card_blocks_inside_a_card_do_not_load_the_reviews_section(): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'Classic card assets product', 'post_status' => 'publish']);
        ob_start();
        (new \FluentCart\App\Services\Renderer\ProductCardRender($product, ['card_layout' => 'classic']))->render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Classic card assets product', $html);
        $this->assertFalse($this->queues('single-product/reviews.scss'), 'its Product Image, Title and Price blocks load the card set');
        // A request for the whole bundle after the card is drawn is a product page's, and gets it.
        AssetLoader::loadSingleProductAssets();
        $this->assertTrue($this->queues('single-product/reviews.scss'));
        $this->assertTrue($this->queues('single-product/Reviews.js'));
    }

    public function test_a_reviews_block_inside_a_product_card_loads_the_reviews_section(): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'Card with reviews product', 'post_status' => 'publish']);
        do_blocks('<!-- wp:fluent-cart/product-card {"template_version":1,"query_type":"custom","product_id":' . $product->ID . '} -->'
            . '<!-- wp:fluent-cart/product-reviews /-->'
            . '<!-- /wp:fluent-cart/product-card -->');

        foreach (['single-product/reviews.scss', 'single-product/Reviews.js', 'single-product/ReviewForm.js'] as $file) {
            $this->assertTrue($this->queues($file), $file . ': a block that shows reviews loads them, in a card too');
        }
    }

    public function test_a_page_with_a_product_card_preloads_the_card_set(): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'Preloaded card product', 'post_status' => 'publish']);
        $contents = [
            'shortcode' => '[fluent_cart_product_card product_id="' . $product->ID . '"]',
            'block'     => '<!-- wp:fluent-cart/product-card /-->',
        ];
        foreach ($contents as $kind => $content) {
            $GLOBALS['wp_styles'] = new \WP_Styles();
            $GLOBALS['wp_scripts'] = new \WP_Scripts();
            $page = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish', 'post_content' => $content]);
            $this->go_to(get_permalink($page));
            $GLOBALS['post'] = get_post($page);
            // Before the card renders: the page's assets are queued on wp_enqueue_scripts.
            do_action('wp_enqueue_scripts');

            $this->assertTrue($this->queues('single-product/SingleProduct.js'), $kind . ': the Quick View set is preloaded');
            foreach (['single-product/reviews.scss', 'single-product/Reviews.js', 'single-product/ReviewForm.js'] as $file) {
                $this->assertFalse($this->queues($file), $kind . ': ' . $file);
            }
        }
    }

    public function test_a_product_page_still_loads_the_whole_bundle_once(): void
    {
        AssetLoader::loadSingleProductAssets();
        AssetLoader::loadSingleProductAssetsWithoutReviews();
        AssetLoader::loadSingleProductAssets();
        $files = ['single-product/SingleProduct.js', 'single-product/xzoom/xzoom.js', 'single-product/Reviews.js', 'single-product/ReviewForm.js', 'single-product/reviews.scss'];
        foreach ($files as $file) {
            $queued = array_filter($this->queuedSources(), function ($src) use ($file) {
                return strpos($src, $file) !== false;
            });
            $this->assertCount(1, $queued, $file . ' is queued once');
        }
        $localized = (string) wp_scripts()->get_data('fluent-cart-single-product-page', 'data');
        $this->assertSame(1, substr_count($localized, 'var fluentcart_single_product_vars'), 'the product data is localized once');
    }
}
