<?php

namespace Tests\Integration\Renderer;

use FluentCart\App\Hooks\Handlers\MenuHandler;
use FluentCart\App\Services\Renderer\ProductCategoriesListRenderer;
use Tests\Support\DatabaseTestCase;

/**
 * Markup touched by the Plugin Check escaping pass. Each case pins output
 * that must not change when the echo is routed through an escaper.
 */
class PluginCheckEscapingOutputTest extends DatabaseTestCase
{
    public function tearDown(): void
    {
        unset($_GET['page']);
        parent::tearDown();
    }

    public function test_category_dropdown_option_escapes_the_name_once_and_keeps_the_count(): void
    {
        $term = wp_insert_term('Tom & Jerry', 'product-categories');
        $stored = get_term((int) $term['term_id'], 'product-categories');
        $stored->count = 3;
        // A name a filter could hand back raw, which core's insert would have stripped.
        $raw = clone $stored;
        $raw->name = 'Bits <b>';

        ob_start();
        (new ProductCategoriesListRenderer())->renderDropdownOptions([$stored, $raw], [
            'show_product_count' => true,
            'show_empty'         => true,
            'show_hierarchy'     => false,
        ]);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('>Tom &amp; Jerry (3)</option>', $html);
        $this->assertStringContainsString('>Bits &lt;b&gt; (3)</option>', $html);
    }

    public function test_dark_mode_script_still_prints_inline_in_the_admin_head(): void
    {
        global $pagenow;
        $pagenow = 'admin.php';
        $_GET['page'] = 'fluent-cart';

        ob_start();
        (new MenuHandler())->outputFluentCartDarkModeScript();
        $html = (string) ob_get_clean();

        // wp_print_inline_script_tag() pads the body with newlines; the browser ignores them.
        $this->assertMatchesRegularExpression('/<script[^>]*>\s*\(function\(\)\{var k=\'fluent_theme_mode\'/', $html);
        $this->assertMatchesRegularExpression('/classList\.add\(c\);\}\)\(\);\s*<\/script>/', $html);
    }
}
