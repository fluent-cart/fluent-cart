<?php

namespace Tests\Integration\Templating;

use FluentCart\App\CPT\Pages;
use FluentCart\App\Modules\Templating\BlockTemplates\ProductCategoryTemplate;
use FluentCart\App\Services\Renderer\ProductCardLayoutPresets;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * The block theme's category template holds the layout card for stores that created
 * their pages after layout cards shipped; every other store keeps the legacy card.
 */
class ProductCategoryTemplateCardTest extends WPTestCase
{
    public function tearDown(): void
    {
        delete_option(Pages::LAYOUT_CARD_OPTION);
        parent::tearDown();
    }

    public function test_a_store_that_created_its_pages_gets_the_sort_action_bar(): void
    {
        update_option(Pages::LAYOUT_CARD_OPTION, 'yes');
        $template = (new ProductCategoryTemplate())->getDefaultTemplate();

        $this->assertStringContainsString('wp:fluent-cart/shopapp-product-action-container', $template);
        $this->assertStringContainsString('wp:fluent-cart/shopapp-product-filter-sort-by', $template);
        $this->assertStringContainsString('wp:fluent-cart/shopapp-product-view-switcher', $template);
        $this->assertStringContainsString('"enable_filter":true', $template);
        $this->assertStringContainsString('{"colors":{},"enable_filter":true', $template, 'the block keeps valid attributes');
        $this->assertStringContainsString('"price_range"', $template);
        $this->assertStringContainsString('"product-brands"', $template);
        $this->assertStringNotContainsString('"product-categories"', $template, 'the page is already in one category');
    }

    public function test_the_shop_page_keeps_the_category_filter(): void
    {
        $this->assertStringContainsString('"product-categories"', Pages::getShopPageContent());
    }

    public function test_a_store_without_the_marker_keeps_the_switcher_alone(): void
    {
        delete_option(Pages::LAYOUT_CARD_OPTION);
        $template = (new ProductCategoryTemplate())->getDefaultTemplate();

        $this->assertStringContainsString('<!-- wp:fluent-cart/shopapp-product-view-switcher {"className":"fluent-product-view-switcher","metadata":{"name":"View Switcher"}} /-->', $template);
        $this->assertStringNotContainsString('wp:fluent-cart/shopapp-product-filter-sort-by', $template);
        $this->assertStringNotContainsString('wp:fluent-cart/shopapp-product-action-container', $template);
        $this->assertStringNotContainsString('enable_filter', $template);
    }

    private function findBlock(array $blocks, string $name): ?array
    {
        foreach ($blocks as $block) {
            if ($block['blockName'] === $name) {
                return $block;
            }
            $found = $this->findBlock($block['innerBlocks'], $name);
            if ($found) {
                return $found;
            }
        }
        return null;
    }

    public function test_a_store_without_the_marker_keeps_the_legacy_card(): void
    {
        delete_option(Pages::LAYOUT_CARD_OPTION);
        $template = (new ProductCategoryTemplate())->getDefaultTemplate();

        $this->assertStringContainsString('wp:fluent-cart/shopapp-product-title', $template);
        $this->assertStringNotContainsString('wp:fluent-cart/product-card', $template);
    }

    public function test_a_store_that_created_its_pages_gets_the_classic_layout_card(): void
    {
        update_option(Pages::LAYOUT_CARD_OPTION, 'yes');
        $template = (new ProductCategoryTemplate())->getDefaultTemplate();

        $this->assertStringContainsString('{"template_version":1,"card_layout":"classic","card_width":"100%"}', $template);
        $this->assertStringContainsString('wp:fluent-cart/product-swatches', $template);
        $this->assertStringNotContainsString('wp:fluent-cart/shopapp-product-image -->', $template, 'the legacy card is gone');

        $loop = $this->findBlock(parse_blocks($template), 'fluent-cart/shopapp-product-loop');
        $this->assertNotNull($loop);
        $this->assertSame(['fluent-cart/product-card'], array_column($loop['innerBlocks'], 'blockName'));
    }

    public function test_the_card_is_complete_when_built_before_the_blocks_register(): void
    {
        // The template registers on after_setup_theme, before init registers the blocks.
        $registry = \WP_Block_Type_Registry::get_instance();
        $removed = [];
        foreach (['fluent-cart/product-image', 'fluent-cart/product-title', 'fluent-cart/shopapp-product-buttons', 'fluent-cart/product-swatches'] as $name) {
            $removed[] = $registry->get_registered($name);
            $registry->unregister($name);
        }
        try {
            $markup = ProductCardLayoutPresets::classicLoopMarkup();
        } finally {
            foreach ($removed as $type) {
                $registry->register($type);
            }
        }

        foreach (['product-image', 'product-title', 'shopapp-product-buttons', 'product-swatches'] as $name) {
            $this->assertStringContainsString('wp:fluent-cart/' . $name, $markup);
        }
    }

    public function test_creating_the_shop_page_sets_the_marker(): void
    {
        delete_option(Pages::LAYOUT_CARD_OPTION);
        $pages = new Pages();
        $notShop = array_values(array_diff(array_keys($pages->corePages()), ['shop']));

        $pages->createPages(array_merge($notShop, ['shop']));
        $this->assertFalse(get_option(Pages::LAYOUT_CARD_OPTION), 'nothing created, nothing marked');

        $pages->createPages($notShop);
        $this->assertSame('yes', get_option(Pages::LAYOUT_CARD_OPTION));
    }
}
