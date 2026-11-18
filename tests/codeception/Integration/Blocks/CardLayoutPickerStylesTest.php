<?php

namespace Tests\Integration\Blocks;

use FluentCart\App\Hooks\Handlers\BlockEditors\ShopApp\ShopAppBlockEditor;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * The block editor's Card Layout picker styles load in the editor only, not on the storefront.
 */
class CardLayoutPickerStylesTest extends WPTestCase
{
    private function source(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 4) . '/resources/' . $path);
    }

    public function test_the_storefront_card_layout_stylesheet_has_no_picker_rules(): void
    {
        $this->assertStringNotContainsString('.fct-card-preset', $this->source('public/product-card/style/card-layouts.scss'));
    }

    public function test_the_block_editor_loads_the_picker_rules(): void
    {
        $styles = (new ShopAppBlockEditor())->getStyles();
        $this->assertContains('public/product-card/style/card-layout-picker.scss', $styles);
        $this->assertContains('public/product-card/style/card-layouts.scss', $styles, 'the editor still draws the layouts');
        $picker = $this->source('public/product-card/style/card-layout-picker.scss');
        foreach (['.fct-card-style', '.fct-card-preset-picker', '.fct-card-preset-choice', '.fct-card-preset-undo', '.fct-card-preset-thumb', '.fct-card-masonry-toggle'] as $selector) {
            $this->assertStringContainsString($selector, $picker);
        }
        // A production build only ships files listed as Vite inputs.
        $this->assertStringContainsString('"resources/public/product-card/style/card-layout-picker.scss"', (string) file_get_contents(dirname(__DIR__, 4) . '/vite.config.mjs'));
    }
}
