<?php

namespace Tests\Integration\Templating;

use FluentCart\App\Hooks\Handlers\ShortCodes\ProductCardShortCode;
use FluentCart\App\Hooks\Handlers\ShortCodes\SingleProductShortCode;
use FluentCart\App\Modules\Templating\AssetLoader;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * FluentCart's shared storefront styles queue after the theme's, so a theme's button reset
 * (same specificity as our component rules) never outranks them.
 */
class StylesAfterThemeTest extends WPTestCase
{
    private const THEME_HANDLE = 'fct-test-theme-reset';

    public function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_styles'] = new \WP_Styles();
        $GLOBALS['wp_scripts'] = new \WP_Scripts();
        // A theme queues its styles at the default priority, from functions.php: after the plugin's
        // own hooks, before AssetLoader's, which hooks in on init.
        add_action('wp_enqueue_scripts', [$this, 'enqueueThemeStyles'], 10);
        remove_action('wp_enqueue_scripts', [AssetLoader::class, 'enqueueAssets']);
        add_action('wp_enqueue_scripts', [AssetLoader::class, 'enqueueAssets']);
    }

    public function enqueueThemeStyles(): void
    {
        wp_enqueue_style(self::THEME_HANDLE, 'https://example.test/reset.css');
    }

    private function assertQueuedAfterTheme(array $handles): void
    {
        $queue = wp_styles()->queue;
        $theme = array_search(self::THEME_HANDLE, $queue, true);
        $this->assertNotFalse($theme, 'the theme styles are queued');
        foreach ($handles as $handle) {
            $position = array_search($handle, $queue, true);
            $this->assertNotFalse($position, $handle . ' is queued');
            $this->assertGreaterThan($theme, $position, $handle . ' is queued after the theme');
        }
    }

    public function test_a_product_page_queues_its_styles_after_the_theme(): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'Styles after theme product', 'post_status' => 'publish']);
        $this->go_to(get_permalink($product->ID));
        do_action('wp_enqueue_scripts');

        $this->assertQueuedAfterTheme(['fluentcart-single-product-css', 'fluentcart-similar-product-css', 'fluentcart-product-card-page-css', 'fluentcart-zoom-css']);
    }

    public function test_the_shortcode_preloads_run_after_a_theme_at_the_default_priority(): void
    {
        // Checked on the hook itself: on develop the bundle's static load flag outlives a reset queue.
        $priorities = [];
        foreach ($GLOBALS['wp_filter']['wp_enqueue_scripts']->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                if ($callback['function'] instanceof \Closure) {
                    $scope = (new \ReflectionFunction($callback['function']))->getClosureScopeClass();
                    if ($scope) {
                        $priorities[$scope->getName()][] = $priority;
                    }
                }
            }
        }

        foreach ([SingleProductShortCode::class, ProductCardShortCode::class] as $class) {
            $this->assertNotEmpty($priorities[$class] ?? [], $class . ' preloads on wp_enqueue_scripts');
            $this->assertGreaterThan(10, min($priorities[$class]), $class . ' runs after a theme at the default priority');
        }
    }
}
