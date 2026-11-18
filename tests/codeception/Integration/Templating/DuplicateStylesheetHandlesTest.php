<?php

namespace Tests\Integration\Templating;

use FluentCart\App\App;
use FluentCart\App\Hooks\Handlers\ShortCodes\Buttons\AddToCartShortcode;
use FluentCart\App\Hooks\Handlers\ShortCodes\Buttons\DirectCheckoutShortcode;
use FluentCart\App\Hooks\Handlers\ShortCodes\SingleProductShortCode;
use FluentCart\App\Modules\Templating\AssetLoader;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Storefront loaders queue a shared stylesheet under one handle, so WordPress prints it once.
 */
class DuplicateStylesheetHandlesTest extends WPTestCase
{
    private const SHARED = [
        'public/single-product/single-product.scss'       => 'fluentcart-single-product-css',
        'public/single-product/similar-product.scss'      => 'fluentcart-similar-product-css',
        'public/product-card/style/product-card.scss'     => 'fluentcart-product-card-page-css',
        'public/buttons/add-to-cart/style/style.scss'     => 'fluentcart-add-to-cart-btn-css',
        'public/buttons/direct-checkout/style/style.scss' => 'fluentcart-direct-checkout-btn-css',
        'public/single-product/xzoom/xzoom.css'           => 'fluentcart-zoom-css',
    ];

    private $previousEnv;

    public function setUp(): void
    {
        parent::setUp();
        // In dev mode a registered source is the file's path under resources/, the form SHARED lists.
        $this->previousEnv = App::config()->get('app.env');
        App::config()->set('app.env', 'dev');
    }

    public function tearDown(): void
    {
        App::config()->set('app.env', $this->previousEnv);
        parent::tearDown();
    }

    /** @return array<string, string[]> registered source (no query) => its handles */
    private function handlesBySource(): array
    {
        $bySource = [];
        foreach (wp_styles()->registered as $handle => $style) {
            if (is_string($style->src) && $style->src !== '') {
                $bySource[strtok($style->src, '?')][] = $handle;
            }
        }

        return $bySource;
    }

    private function handlesOf(string $file): array
    {
        $handles = [];
        foreach ($this->handlesBySource() as $src => $list) {
            if (substr($src, -strlen($file)) === $file) {
                $handles = array_merge($handles, $list);
            }
        }

        return $handles;
    }

    public function test_every_storefront_loader_uses_one_handle_per_shared_file(): void
    {
        // Static load flags persist between tests, so the queue is never reset here.
        AssetLoader::loadProductCardAssets();
        AssetLoader::loadProductArchiveAssets();
        AssetLoader::loadSingleProductAssets();
        AssetLoader::enqueueProductInfoFrontendStyles();
        AssetLoader::loadAddToCartCss();
        SingleProductShortCode::make()->enqueueStyles();
        AddToCartShortcode::make()->enqueueStyles();
        DirectCheckoutShortcode::make()->enqueueStyles();

        foreach (self::SHARED as $file => $handle) {
            // These names are what add-ons (Elementor widgets) list as style dependencies.
            $this->assertSame([$handle], $this->handlesOf($file), $file);
            $this->assertTrue(wp_style_is($handle, 'enqueued'), $handle);
        }
    }

    public function test_a_shop_grid_prints_each_stylesheet_once(): void
    {
        do_shortcode('[fluent_cart_products card_layout="compact" per_page="4"]');

        // do_items(), not wp_print_styles(): that fires WordPress's deprecated emoji styles hook.
        ob_start();
        wp_styles()->do_items();
        preg_match_all('/<link[^>]+href=[\'"]([^\'"]+)[\'"]/', (string) ob_get_clean(), $links);

        $this->assertNotEmpty($links[1]);
        $repeated = array_keys(array_filter(array_count_values(array_map(function ($href) {
            return strtok($href, '?');
        }, $links[1])), function ($count) {
            return $count > 1;
        }));
        $this->assertSame([], $repeated, 'no stylesheet is linked twice');
    }
}
