<?php

namespace Tests\Integration\Checkout;

use FluentCart\App\Hooks\Handlers\BlockEditors\Checkout\CheckoutBlockEditor;
use FluentCart\App\Hooks\Handlers\ShortCodes\Checkout\CheckoutPageHandler;
use FluentCart\App\Models\Cart;
use Tests\Support\DatabaseTestCase;

/**
 * An add-on that supplies the checkout's cart (a product offer) can also say
 * what an empty checkout should read, e.g. "Product unavailable" when every
 * offered product is gone, instead of the generic empty-cart message.
 */
class CheckoutEmptyMessageTest extends DatabaseTestCase
{
    /** @var array */
    private $seenContexts = [];

    public function setUp(): void
    {
        parent::setUp();

        // An add-on that answers with a cart holding nothing to buy.
        add_filter('fluent_cart/checkout/render_cart', [$this, 'emptyCart'], 10, 2);
    }

    public function tearDown(): void
    {
        remove_filter('fluent_cart/checkout/render_cart', [$this, 'emptyCart'], 10);
        remove_all_filters('fluent_cart/checkout/empty_message');
        parent::tearDown();
    }

    public function emptyCart($cart, $context)
    {
        $empty = new Cart();
        $empty->cart_data = [];

        return $empty;
    }

    private function messageFilter($message)
    {
        add_filter('fluent_cart/checkout/empty_message', function ($current, $context) use ($message) {
            $this->seenContexts[] = $context;
            return $message;
        }, 10, 2);
    }

    private function renderShortcode(array $attributes): string
    {
        ob_start();
        $returned = (new CheckoutPageHandler())->render($attributes);

        return ob_get_clean() . (string)$returned;
    }

    public function test_shortcode_empty_checkout_uses_the_filtered_message(): void
    {
        $this->messageFilter('Product unavailable');

        $html = $this->renderShortcode(['variant_ids' => '999']);

        $this->assertStringContainsString('Product unavailable', $html);
        $this->assertStringNotContainsString('Your cart is empty.', $html);
        $this->assertSame('checkout-shortcode', $this->seenContexts[0]['surface']);
        $this->assertSame('999', $this->seenContexts[0]['attributes']['variant_ids']);
    }

    public function test_block_empty_checkout_uses_the_filtered_message(): void
    {
        $this->messageFilter('Product unavailable');

        $html = (new CheckoutBlockEditor())->render(['variant_ids' => [999]]);

        $this->assertStringContainsString('Product unavailable', $html);
        $this->assertStringNotContainsString('Your cart is empty.', $html);
        $this->assertSame('checkout-block', $this->seenContexts[0]['surface']);
    }

    public function test_without_a_listener_the_generic_message_is_unchanged(): void
    {
        $this->assertStringContainsString('Your cart is empty.', $this->renderShortcode([]));
    }
}
