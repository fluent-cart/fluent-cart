<?php

namespace Tests\Integration\Templating;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\Templating\Bricks\MovingToBricksAddonNotice;
use FluentCart\App\Modules\Templating\Bricks\Elements\PriceRange;
use FluentCart\App\Modules\Templating\Bricks\Elements\ProductTitle;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * The core Bricks elements warn on the builder canvas that they move to the Bricks add-on;
 * the live page stays untouched.
 */
class BricksMovingToAddonNoticeTest extends WPTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/Support/Stubs/BricksElementStubs.php';
    }

    private function renderThroughInit($element, bool $isFrontend): string
    {
        $element->is_frontend = $isFrontend;
        ob_start();
        $element->init();

        return (string) ob_get_clean();
    }

    public function test_every_core_element_carries_the_notice(): void
    {
        $elements = ['ProductTitle', 'ProductShortDescription', 'ProductContent', 'ProductStock', 'PriceRange', 'BuySection', 'ProductGallery', 'ProductsCollection'];

        foreach ($elements as $name) {
            $this->assertContains(MovingToBricksAddonNotice::class, class_uses('FluentCart\\App\\Modules\\Templating\\Bricks\\Elements\\' . $name), $name);
        }
    }

    public function test_the_canvas_shows_the_notice_inside_the_root_and_the_live_page_does_not(): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'Bricks Notice Product']);

        $title = new ProductTitle();
        $title->post_id = (int) $product->ID;

        $canvas = $this->renderThroughInit($title, false);
        $this->assertMatchesRegularExpression('/^\s*<h1[^>]*><div class="fct-bricks-moving-to-addon-notice" role="note"/', $canvas);
        $this->assertStringContainsString('Product Title is moving to the Bricks add-on', $canvas);
        $this->assertStringContainsString('Bricks Notice Product', $canvas);
        $this->assertSame(1, substr_count($canvas, 'fct-bricks-moving-to-addon-notice'));

        $live = $this->renderThroughInit($title, true);
        $this->assertStringNotContainsString('fct-bricks-moving-to-addon-notice', $live);
        $this->assertStringContainsString('Bricks Notice Product', $live);
    }

    public function test_empty_output_gets_no_notice(): void
    {
        $title = new ProductTitle();
        $title->post_id = 0;

        $this->assertSame('', $this->renderThroughInit($title, false));
        $this->assertSame('', PriceRange::addMovingToBricksAddonNotice('', 'Price Range'));
        $this->assertSame('plain text', PriceRange::addMovingToBricksAddonNotice('plain text', 'Price Range'));
    }

    public function test_the_notice_sits_above_a_bricks_placeholder_not_inside_it(): void
    {
        $placeholder = '<div class="bricks-element-placeholder" data-type="info" style=""><i></i><div class="placeholder-inner">Select a product</div></div>';
        $html = PriceRange::addMovingToBricksAddonNotice($placeholder, 'Price Range');

        $this->assertMatchesRegularExpression('/^<div><div class="fct-bricks-moving-to-addon-notice"[^>]*>.*?<\/div><div class="bricks-element-placeholder"/', $html);
        $this->assertStringEndsWith($placeholder . '</div>', $html);
    }

    public function test_an_admin_gets_the_install_and_download_links_and_others_only_the_download(): void
    {
        $element = '<div class="brxe-x">Body</div>';

        wp_set_current_user(1);
        Helper::getCurrentUser(true);
        $admin = PriceRange::addMovingToBricksAddonNotice($element, 'Price Range');
        $this->assertStringContainsString('Price Range is moving to the Bricks add-on', $admin);
        $this->assertStringContainsString('href="' . esc_url(admin_url('admin.php?page=fluent-cart#/settings/addons#fluent-cart-bricks-blocks')) . '"', $admin);
        $this->assertStringContainsString('href="https://fluentcart.com/fluentcart-addons/"', $admin);
        // The Bricks canvas cancels link clicks, so each link opens its own tab.
        $this->assertSame(2, substr_count($admin, 'onclick="event.stopPropagation();window.open(this.href,&#039;_blank&#039;,&#039;noopener&#039;);return false;"'));

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        Helper::getCurrentUser(true);
        $editor = PriceRange::addMovingToBricksAddonNotice($element, 'Price Range');
        $this->assertStringNotContainsString('#/settings/addons', $editor);
        $this->assertStringContainsString('href="https://fluentcart.com/fluentcart-addons/"', $editor);
    }

    public function test_plugin_rights_without_fluentcart_admin_access_get_no_install_link(): void
    {
        // The addons screen and its install routes need FluentCart's is_super_admin (manage_options).
        add_role('fct_test_plugin_installer', 'Plugin installer', ['read' => true, 'install_plugins' => true, 'activate_plugins' => true]);
        wp_set_current_user(self::factory()->user->create(['role' => 'fct_test_plugin_installer']));
        Helper::getCurrentUser(true);

        $html = PriceRange::addMovingToBricksAddonNotice('<div class="brxe-x">Body</div>', 'Price Range');

        remove_role('fct_test_plugin_installer');
        $this->assertStringNotContainsString('#/settings/addons', $html);
        $this->assertStringContainsString('href="https://fluentcart.com/fluentcart-addons/"', $html);
    }

    public function test_the_install_link_needs_the_capability_for_the_addons_current_state(): void
    {
        // Installed needs activate_plugins, missing needs install_plugins; the other right alone gets a refused button.
        $isInstalled = file_exists(WP_PLUGIN_DIR . '/fluent-cart-bricks-blocks/fluent-cart-bricks-blocks.php');

        $linkFor = function (array $capabilities) {
            add_role('fct_test_store_admin', 'Store admin', array_merge(['read' => true, 'manage_options' => true], $capabilities));
            wp_set_current_user(self::factory()->user->create(['role' => 'fct_test_store_admin']));
            Helper::getCurrentUser(true);
            $html = PriceRange::addMovingToBricksAddonNotice('<div class="brxe-x">Body</div>', 'Price Range');
            remove_role('fct_test_store_admin');

            return strpos($html, '#/settings/addons') !== false;
        };

        $this->assertSame($isInstalled, $linkFor(['activate_plugins' => true]), 'activate_plugins only');
        $this->assertSame(!$isInstalled, $linkFor(['install_plugins' => true]), 'install_plugins only');
    }
}
