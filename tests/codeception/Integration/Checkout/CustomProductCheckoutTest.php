<?php

namespace Tests\Integration\Checkout;

use FluentCart\Api\Resource\FrontendResource\CartResource;
use FluentCart\App\App;
use FluentCart\App\Hooks\Handlers\BlockEditors\Checkout\CheckoutBlockEditor;
use FluentCart\App\Hooks\Handlers\ShortCodes\Checkout\CheckoutPageHandler;
use FluentCart\App\Services\Renderer\CheckoutRenderer;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CartFactory;

/**
 * A checkout configured with its own products is rendered by the add-on that
 * supplies them. Without that add-on it renders nothing — never the shop cart.
 */
class CustomProductCheckoutTest extends DatabaseTestCase
{
    const ACTION = 'fluent_cart/checkout/render_custom_product_checkout';

    public function setUp(): void
    {
        parent::setUp();

        remove_all_actions(self::ACTION);

        // The shopper's own cart, which a custom-product checkout must never show.
        $shopCart = (new CartFactory())
            ->withAttributes(['cart_group' => 'instant'])
            ->withCartItems([CartFactory::makeCartItem(['post_title' => 'SHOP CART ITEM'])])
            ->create();

        App::request()->merge(['fct_cart_hash' => $shopCart->cart_hash]);
        CartResource::resetCartCache();
    }

    public function tearDown(): void
    {
        App::request()->merge(['fct_cart_hash' => null]);
        CartResource::resetCartCache();
        remove_all_actions(self::ACTION);
        remove_all_filters('fluent_cart/checkout/render_cart');

        parent::tearDown();
    }

    private function renderShortcode(array $attributes): string
    {
        ob_start();
        $returned = (new CheckoutPageHandler())->render($attributes);

        return ob_get_clean() . (string)$returned;
    }

    public function test_shortcode_with_products_renders_nothing_without_a_listener(): void
    {
        $html = $this->renderShortcode(['variant_ids' => '999']);

        $this->assertStringNotContainsString('SHOP CART ITEM', $html);
        $this->assertSame('', trim($html));
    }

    public function test_block_with_products_renders_nothing_without_a_listener(): void
    {
        $html = (new CheckoutBlockEditor())->render([
            'checkout_mode' => 'custom',
            'variant_ids'   => [999],
        ]);

        $this->assertSame('', trim($html));
    }

    public function test_the_listener_renders_the_custom_checkout_with_the_render_context(): void
    {
        $seen = [];
        add_action(self::ACTION, function ($context) use (&$seen) {
            $seen = $context;
            echo 'CUSTOM CHECKOUT';
        });

        $html = (new CheckoutBlockEditor())->render(['variant_ids' => [999]]);

        $this->assertSame('CUSTOM CHECKOUT', $html);
        $this->assertSame('checkout-block', $seen['surface']);
        $this->assertSame([999], $seen['attributes']['variant_ids']);
        $this->assertArrayHasKey('block', $seen);
    }

    public function test_an_add_on_invokes_core_to_render_its_own_cart(): void
    {
        $offerCart = (new CartFactory())
            ->withCartItems([CartFactory::makeCartItem(['post_title' => 'OFFER ITEM'])])
            ->create();

        add_action(self::ACTION, function ($context) use ($offerCart) {
            CheckoutRenderer::renderCustomProductCart($offerCart, $context);
        });

        $html = $this->renderShortcode(['variant_ids' => '999']);

        $this->assertStringContainsString('data-fluent-cart-checkout-page', $html);
        $this->assertStringContainsString('OFFER ITEM', $html);
        $this->assertStringNotContainsString('SHOP CART ITEM', $html);
    }

    public function test_cart_mode_keeps_rendering_the_shop_cart(): void
    {
        add_action(self::ACTION, function () {
            echo 'CUSTOM CHECKOUT';
        });

        $html = $this->renderShortcode(['checkout_mode' => 'cart', 'variant_ids' => '999']);

        $this->assertStringNotContainsString('CUSTOM CHECKOUT', $html);
        $this->assertStringContainsString('data-fluent-cart-checkout-page', $html);
    }

    public function test_custom_mode_is_inferred_from_products_unless_cart_mode_is_explicit(): void
    {
        $this->assertTrue(CheckoutRenderer::isCustomProductCheckout(['variant_ids' => [5]]));
        $this->assertTrue(CheckoutRenderer::isCustomProductCheckout(['variation_id' => '5, 6']));
        $this->assertTrue(CheckoutRenderer::isCustomProductCheckout(['checkout_mode' => 'custom', 'variant_ids' => [['id' => 5]]]));
        $this->assertFalse(CheckoutRenderer::isCustomProductCheckout(['checkout_mode' => 'cart', 'variant_ids' => [5]]));
        $this->assertFalse(CheckoutRenderer::isCustomProductCheckout(['checkout_mode' => 'custom']));
        $this->assertFalse(CheckoutRenderer::isCustomProductCheckout(['variant_ids' => '0']));
        $this->assertFalse(CheckoutRenderer::isCustomProductCheckout([]));
    }
}
