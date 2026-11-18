<?php

namespace Tests\Integration\Blocks;

use FluentCart\App\CPT\Pages;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * A newly created Shop page holds what the Gutenberg Products block inserts:
 * a layout card (Classic) with swatches, not the legacy loop card.
 */
class DefaultShopPageContentTest extends WPTestCase
{
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

    public function test_the_loop_holds_the_gutenberg_default_card(): void
    {
        $blocks = parse_blocks(Pages::getShopPageContent());
        $loop = $this->findBlock($blocks, 'fluent-cart/shopapp-product-loop');

        $this->assertNotNull($loop);
        $this->assertCount(1, $loop['innerBlocks']);
        $card = $loop['innerBlocks'][0];
        $this->assertSame('fluent-cart/product-card', $card['blockName']);
        $this->assertSame(1, $card['attrs']['template_version']);
        $this->assertSame('100%', $card['attrs']['card_width']);
        $this->assertNotNull($this->findBlock([$card], 'fluent-cart/product-swatches'));
        $this->assertNotNull($this->findBlock($blocks, 'fluent-cart/shopapp-product-filter-sort-by'), 'the action bar has Sort, as in the block');
        $this->assertNotNull($this->findBlock($blocks, 'fluent-cart/shopapp-product-loader'));
    }

    public function test_the_page_keeps_its_filters(): void
    {
        $products = $this->findBlock(parse_blocks(Pages::getShopPageContent()), 'fluent-cart/products');

        $this->assertTrue($products['attrs']['enable_filter']);
        $this->assertTrue($products['attrs']['filters']['price_range']['enabled']);
    }

    public function test_the_page_renders_layout_cards_with_swatches(): void
    {
        (new ProductFactory())->withDetailAttributes(['variation_type' => 'simple_variations'])
            ->createWithVariants([['variation_title' => 'Small'], ['variation_title' => 'Large']], ['post_title' => 'Default Shop Swatch', 'post_status' => 'publish']);

        $html = do_blocks(Pages::getShopPageContent());

        $this->assertStringContainsString('fct-product-card-template', $html);
        $this->assertStringContainsString('Default Shop Swatch', $html);
        $this->assertStringContainsString('fct-card-swatches', $html);
    }
}
