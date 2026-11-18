<?php

namespace Tests\Integration\Blocks;

use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Services\Renderer\RenderHelper;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Traits\RendersProductQuickView;

/**
 * The Quick View block: the card's quick view trigger, placed beside the buy button or on the image.
 */
class ProductQuickViewBlockTest extends WPTestCase
{
    use RendersProductQuickView;

    private $product;

    public function setUp(): void
    {
        parent::setUp();
        // A product with options: quick view is for picking one, so simple products get none.
        $this->product = (new ProductFactory())->createWithVariants([
            ['variation_title' => 'Small'],
            ['variation_title' => 'Large'],
        ], ['post_title' => 'Quick View Coat']);
        ProductDetail::query()->where('post_id', $this->product->ID)->update(['variation_type' => 'simple_variations']);
    }

    public function test_simple_products_get_no_quick_view(): void
    {
        $simple = (new ProductFactory())->create(['post_title' => 'One Size Scarf']);

        // The first block of a request also prints the shared block-supports <style>; only the trigger matters.
        $html = $this->renderQuickView($simple);
        $this->assertStringNotContainsString('data-fct-card-quick-view', $html);
        $this->assertStringNotContainsString('data-fluent-cart-single-product-card-view-button', $html);
    }

    public function test_block_is_allowed_in_product_loops_only(): void
    {
        $ancestors = \WP_Block_Type_Registry::get_instance()->get_registered('fluent-cart/product-quick-view')->ancestor;

        foreach (['fluent-cart/products', 'fluent-cart/product-carousel', 'fluent-cart/related-product'] as $container) {
            $this->assertContains($container, $ancestors);
        }
    }

    public function test_renders_the_quick_view_trigger_for_the_loop_product(): void
    {
        $html = $this->renderQuickView($this->product);

        // The same trigger as View Options, so the existing quick view modal opens.
        $this->assertStringContainsString('data-fluent-cart-single-product-card-view-button', $html);
        $this->assertStringContainsString('data-product-id="' . $this->product->ID . '"', $html);
        $this->assertStringContainsString('aria-label="Quick view of Quick View Coat"', $html);
        // The eye comes from app/Views/icons/quick-view.svg; a missing file would print nothing.
        $this->assertStringContainsString('<circle cx="12" cy="12" r="3"/>', RenderHelper::quickViewIconSvg());
        $this->assertStringContainsString(RenderHelper::quickViewIconSvg(), $html);
    }

    public function test_defaults_to_an_icon_beside_the_button_shown_always(): void
    {
        $html = $this->renderQuickView($this->product);

        $this->assertStringContainsString('data-position="button"', $html);
        $this->assertStringContainsString('data-show="always"', $html);
        $this->assertStringContainsString('is-icon', $html);
    }

    public function test_settings_pick_the_image_on_hover_with_text(): void
    {
        $html = $this->renderQuickView($this->product, ['position' => 'image', 'show_on' => 'hover', 'display' => 'icon_text']);

        $this->assertStringContainsString('data-position="image"', $html);
        $this->assertStringContainsString('data-show="hover"', $html);
        $this->assertStringContainsString('is-icon-text', $html);
    }

    public function test_on_the_image_it_takes_a_corner_or_the_middle_like_the_badges(): void
    {
        $this->assertStringContainsString('data-image-position="bottom-center"', $this->renderQuickView($this->product, ['position' => 'image']));
        foreach (['top-left', 'top-center', 'top-right', 'bottom-left', 'bottom-right'] as $corner) {
            $html = $this->renderQuickView($this->product, ['position' => 'image', 'image_position' => $corner]);
            $this->assertStringContainsString('data-image-position="' . $corner . '"', $html);
        }
        $html = $this->renderQuickView($this->product, ['position' => 'image', 'image_position' => 'middle"><b>']);
        $this->assertStringContainsString('data-image-position="bottom-center"', $html);
        // Beside the button there is no image to place it on.
        $html = $this->renderQuickView($this->product, ['position' => 'button', 'image_position' => 'top-left']);
        $this->assertStringNotContainsString('data-image-position', $html);
    }

    public function test_beside_the_button_is_always_a_visible_icon(): void
    {
        $html = $this->renderQuickView($this->product, ['position' => 'button', 'show_on' => 'hover', 'display' => 'icon_text']);

        $this->assertStringContainsString('data-show="always"', $html);
        $this->assertStringContainsString('is-icon"', $html);
        $this->assertStringNotContainsString('fct-card-quick-view-text', $html);
    }

    public function test_unknown_setting_values_fall_back_to_the_defaults(): void
    {
        $html = $this->renderQuickView($this->product, ['position' => '"><script>', 'show_on' => 'never', 'display' => 'huge']);

        $this->assertStringContainsString('data-position="button"', $html);
        $this->assertStringContainsString('data-show="always"', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_hidden_when_the_card_actions_are_hidden(): void
    {
        add_filter('fluent_cart/product/show_actions', '__return_false');
        try {
            $html = $this->renderQuickView($this->product);
        } finally {
            remove_filter('fluent_cart/product/show_actions', '__return_false');
        }

        $this->assertSame('', trim($html));
    }
}
