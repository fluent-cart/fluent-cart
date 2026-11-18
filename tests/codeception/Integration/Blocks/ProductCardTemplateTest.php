<?php

namespace Tests\Integration\Blocks;

use FluentCart\App\Hooks\Handlers\BlockEditors\ProductCardBlockEditor;
use FluentCart\App\Hooks\Handlers\BlockEditors\ShopApp\InnerBlocks\InnerBlocks;
use FluentCart\App\Services\Renderer\ProductCardLayoutPresets;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Traits\BuildsProductCardPresets;

class ProductCardTemplateTest extends WPTestCase
{
    use BuildsProductCardPresets;

    private function cardMarkup(string $layout, array $attributes = []): string
    {
        $template = ProductCardLayoutPresets::all()[$layout]['template'];
        if ($layout !== 'classic') {
            $template = $template[0][2];
        }
        return get_comment_delimited_block_content('fluent-cart/product-card', array_merge([
            'template_version' => 1,
            'card_layout' => $layout,
        ], $attributes), $this->cardPresetMarkup($template));
    }

    public function test_new_cards_render_each_loop_product_and_survive_cached_pagination(): void
    {
        $products = [
            (new ProductFactory())->create(['post_title' => 'Card coffee', 'post_status' => 'publish']),
            (new ProductFactory())->create(['post_title' => 'Card kettle', 'post_status' => 'publish']),
        ];
        foreach (array_keys(ProductCardLayoutPresets::all()) as $layout) {
            $attributes = ['wp_client_id' => 'card-template-' . $layout];
            $markup = get_comment_delimited_block_content('fluent-cart/shopapp-product-loop', $attributes, $this->cardMarkup($layout));
            $block = new \WP_Block(parse_blocks($markup)[0], ['fluent-cart/per_page' => 100]);
            $html = (new InnerBlocks())->renderProductLoop($attributes, '', $block);
            $cached = get_transient('fct_product_loop_client_' . $attributes['wp_client_id']);
            foreach ([$html, do_blocks($cached['markup'])] as $rendered) {
                $document = new \DOMDocument();
                @$document->loadHTML('<?xml encoding="utf-8" ?>' . $rendered);
                $xpath = new \DOMXPath($document);
                foreach ($products as $product) {
                    $cards = $xpath->query('//div[@data-fct-product-card][.//*[contains(text(), "' . $product->post_title . '")]]');
                    $this->assertSame(1, $cards->length, $layout . ' renders one wrapper for each product');
                    $this->assertSame(1, $xpath->query('.//*[@data-cart-id="' . $product->variants->first()->id . '"]', $cards->item(0))->length);
                }
            }
        }
    }

    public function test_standalone_cards_restore_the_surrounding_product_and_reject_private_products(): void
    {
        $outer = (new ProductFactory())->create(['post_title' => 'Surrounding product', 'post_status' => 'publish']);
        $inner = (new ProductFactory())->create(['post_title' => 'Selected card product', 'post_status' => 'publish']);
        $GLOBALS['post'] = get_post($outer->ID);
        setup_postdata($GLOBALS['post']);
        $before = fluent_cart_get_current_product();
        foreach (array_keys(ProductCardLayoutPresets::all()) as $layout) {
            $html = do_blocks($this->cardMarkup($layout, ['query_type' => 'custom', 'product_id' => $inner->ID]));
            $this->assertStringContainsString($inner->post_title, $html);
            $this->assertStringNotContainsString($outer->post_title, $html);
            $this->assertSame($outer->ID, $GLOBALS['post']->ID);
            $this->assertSame($before, fluent_cart_get_current_product());
        }
        wp_update_post(['ID' => $inner->ID, 'post_status' => 'private']);
        $this->assertSame('', do_blocks($this->cardMarkup('compact', ['query_type' => 'custom', 'product_id' => $inner->ID])));
    }

    public function test_a_saved_masonry_card_falls_back_to_classic_with_masonry_off(): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'Masonry card product', 'post_status' => 'publish']);
        $html = do_blocks($this->cardMarkup('masonry', ['query_type' => 'custom', 'product_id' => $product->ID]));

        // Masonry is a switch now; the layout of that name is kept for the page builders only.
        $this->assertStringContainsString('fct-product-card-template', $html);
        $this->assertStringNotContainsString('fct-card-layout--masonry', $html);
        $this->assertStringNotContainsString('fct-card-masonry', $html);
        $this->assertTrue(ProductCardLayoutPresets::all()['masonry']['builder_only']);
    }

    public function test_a_builders_masonry_card_keeps_its_layout_and_staggers(): void
    {
        // Elementor, Bricks and Divi render their layout cards through ProductCardRender and cardBlock().
        $product = (new ProductFactory())->create(['post_title' => 'Builder masonry product', 'post_status' => 'publish']);
        $product = \FluentCart\App\Models\Product::query()->with(['detail', 'variants'])->find($product->ID);
        ob_start();
        (new \FluentCart\App\Services\Renderer\ProductCardRender($product, ['card_layout' => 'masonry']))->render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('fct-card-layout fct-card-layout--masonry fct-card-masonry', $html, 'a builder Masonry card is not turned into Classic');
        $this->assertStringNotContainsString('fct-product-card-template', $html);
    }

    public function test_masonry_is_a_switch_on_every_layout(): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'Masonry switch product', 'post_status' => 'publish']);
        foreach (['compact', 'floating', 'framed'] as $layout) {
            $html = do_blocks($this->cardMarkup($layout, ['query_type' => 'custom', 'product_id' => $product->ID, 'masonry' => true]));
            $this->assertStringContainsString('fct-card-layout fct-card-layout--' . $layout . ' fct-card-masonry', $html, $layout . ' with Masonry on');
            $off = do_blocks($this->cardMarkup($layout, ['query_type' => 'custom', 'product_id' => $product->ID]));
            $this->assertStringNotContainsString('fct-card-masonry', $off, $layout . ' with Masonry off');
        }
        $classic = do_blocks($this->cardMarkup('classic', ['query_type' => 'custom', 'product_id' => $product->ID, 'masonry' => true]));
        $this->assertStringContainsString('fct-product-card-template fct-card-masonry', $classic, 'Classic with Masonry on');
    }

    public function test_the_card_can_consume_carousel_and_related_product_contexts(): void
    {
        $first = (new ProductFactory())->create(['post_title' => 'Shared card source', 'post_status' => 'publish']);
        $second = (new ProductFactory())->create(['post_title' => 'Shared related card', 'post_status' => 'publish']);
        wp_set_object_terms($first->ID, 'Shared card category', 'product-categories');
        wp_set_object_terms($second->ID, 'Shared card category', 'product-categories');

        $markup = get_comment_delimited_block_content('fluent-cart/product-carousel-loop', [], $this->cardMarkup('compact'));
        $block = new \WP_Block(parse_blocks($markup)[0], ['fluent-cart/product_ids' => [$first->ID, $second->ID]]);
        $carousel = new \FluentCart\App\Hooks\Handlers\BlockEditors\ProductCarousel\InnerBlocks\InnerBlocks();
        $html = $carousel->renderProductCarouselLoop([], '', $block);
        $this->assertStringContainsString($first->post_title, $html);
        $this->assertStringContainsString($second->post_title, $html);
        $this->assertSame(2, substr_count($html, 'fct-card-layout--compact'));
        $this->assertSame(2, substr_count($html, 'data-fct-product-card'));

        $markup = get_comment_delimited_block_content('fluent-cart/product-template', [], $this->cardMarkup('compact'));
        $block = new \WP_Block(parse_blocks($markup)[0], [
            'fluent-cart/query_type' => 'custom',
            'fluent-cart/product_id' => $first->ID,
            'fluent-cart/related_by_categories' => true,
        ]);
        $related = new \FluentCart\App\Hooks\Handlers\BlockEditors\RelatedProduct\InnerBlocks\InnerBlocks();
        $html = $related->renderProductTemplate([], '', $block);
        $this->assertStringContainsString($second->post_title, $html);
        $this->assertStringNotContainsString($first->post_title, $html);
        $this->assertSame(1, substr_count($html, 'fct-card-layout--compact'));
    }

    public function test_loop_cards_on_a_single_product_page_show_each_loop_product_not_the_page_product(): void
    {
        $page = (new ProductFactory())->create(['post_title' => 'Page product on show', 'post_status' => 'publish']);
        $other = (new ProductFactory())->create(['post_title' => 'Loop product in card', 'post_status' => 'publish']);
        wp_set_object_terms($page->ID, 'Page card category', 'product-categories');
        wp_set_object_terms($other->ID, 'Page card category', 'product-categories');
        // As on a single product page: the page's product is the current one before the loops run.
        $GLOBALS['post'] = get_post($page->ID);
        $GLOBALS['wp_query']->post = $GLOBALS['post'];
        setup_postdata($GLOBALS['post']);
        $this->assertSame((int) $page->ID, (int) fluent_cart_get_current_product()->ID);

        $markup = get_comment_delimited_block_content('fluent-cart/product-template', [], $this->cardMarkup('compact'));
        $block = new \WP_Block(parse_blocks($markup)[0], [
            'fluent-cart/query_type' => 'default',
            'fluent-cart/related_by_categories' => true,
        ]);
        $html = (new \FluentCart\App\Hooks\Handlers\BlockEditors\RelatedProduct\InnerBlocks\InnerBlocks())->renderProductTemplate([], '', $block);
        $this->assertStringContainsString($other->post_title, $html);
        $this->assertStringNotContainsString($page->post_title, $html);

        $markup = get_comment_delimited_block_content('fluent-cart/product-carousel-loop', [], $this->cardMarkup('compact'));
        $block = new \WP_Block(parse_blocks($markup)[0], ['fluent-cart/product_ids' => [$other->ID]]);
        $html = (new \FluentCart\App\Hooks\Handlers\BlockEditors\ProductCarousel\InnerBlocks\InnerBlocks())->renderProductCarouselLoop([], '', $block);
        $this->assertStringContainsString($other->post_title, $html);
        $this->assertStringNotContainsString($page->post_title, $html);

        // The loops hand the page's product back afterwards.
        $this->assertSame((int) $page->ID, (int) fluent_cart_get_current_product()->ID);
        wp_reset_postdata();
    }

    public function test_saved_leaf_cards_keep_their_existing_renderer(): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'Legacy card product', 'post_status' => 'publish']);
        $attributes = ['query_type' => 'custom', 'product_id' => $product->ID, 'card_width' => 300];
        $handler = new ProductCardBlockEditor();
        $html = $handler->render($attributes);
        $this->assertStringContainsString($product->post_title, $html);
        $this->assertStringContainsString('data-fluent-cart-shop-app-single-product', $html);
        $this->assertStringContainsString('width: 300px;', $html);
        $this->assertStringNotContainsString('fct-card-layout', $html);
        $this->assertSame($html, $handler->render(array_merge($attributes, ['template_version' => 0])));
    }
}
