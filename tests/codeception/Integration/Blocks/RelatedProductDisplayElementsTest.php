<?php

namespace Tests\Integration\Blocks;

use FluentCart\App\Hooks\Handlers\BlockEditors\RelatedProduct\InnerBlocks\InnerBlocks;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

class RelatedProductDisplayElementsTest extends WPTestCase
{
    private $source;
    private $related;

    public function setUp(): void
    {
        parent::setUp();
        $this->source = (new ProductFactory())->create(['post_title' => 'Display source product', 'post_status' => 'publish']);
        $this->related = (new ProductFactory())->create(['post_title' => 'Display related product', 'post_status' => 'publish']);
        wp_set_object_terms($this->source->ID, 'Display elements category', 'product-categories');
        wp_set_object_terms($this->related->ID, 'Display elements category', 'product-categories');
    }

    private function render(string $children, array $context): string
    {
        $markup = get_comment_delimited_block_content('fluent-cart/product-template', [], $children);
        $block = new \WP_Block(parse_blocks($markup)[0], array_merge([
            'fluent-cart/query_type'            => 'custom',
            'fluent-cart/product_id'            => $this->source->ID,
            'fluent-cart/related_by_categories' => true,
        ], $context));

        return (new InnerBlocks())->renderProductTemplate([], '', $block);
    }

    public function test_display_elements_still_hide_an_older_related_cards_blocks(): void
    {
        // The title element, not the name: the buttons' aria-label also carries the product name.
        $children = '<!-- wp:fluent-cart/shopapp-product-title /--><!-- wp:fluent-cart/shopapp-product-buttons /-->';
        $this->assertGreaterThan(0, substr_count($this->render($children, []), 'fct-product-card-title'));

        $html = $this->render($children, ['fluent-cart/show_title' => false]);
        $this->assertSame(0, substr_count($html, 'fct-product-card-title'), 'Older related cards keep honouring the Title toggle');
        $this->assertStringContainsString('fluent-cart-add-to-cart-button', $html, 'Only the toggled block is hidden');
    }

    public function test_display_elements_do_not_reach_into_a_product_card(): void
    {
        // A card's own blocks decide what it shows; the toggles only ever matched the loop's direct children.
        $card = '<!-- wp:fluent-cart/product-card {"template_version":1,"card_width":"100%"} --><!-- wp:fluent-cart/product-title /--><!-- /wp:fluent-cart/product-card -->';
        $html = $this->render($card, ['fluent-cart/show_title' => false, 'fluent-cart/show_image' => false]);
        $this->assertGreaterThan(0, substr_count($html, 'fct-product-card-title'));
    }

    public function test_the_loop_is_named_like_the_other_product_loops(): void
    {
        // Only the title changed; saved pages keep the fluent-cart/product-template name.
        $this->assertSame('Related Products Loop', \WP_Block_Type_Registry::get_instance()->get_registered('fluent-cart/product-template')->title);
    }
}
