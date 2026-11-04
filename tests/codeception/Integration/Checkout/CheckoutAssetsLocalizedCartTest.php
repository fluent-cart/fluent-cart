<?php

namespace Tests\Integration\Checkout;

use FluentCart\App\Modules\Templating\AssetLoader;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CartFactory;

/**
 * On the store's Checkout page, AssetLoader::enqueueAssets() loads the checkout
 * assets in <head> from the shopper's own cart. A checkout block that renders
 * from an add-on's cart (the product selector) then calls loadCheckoutAssets()
 * with that cart, and the vars must follow it: with the head cart's vars every
 * save_checkout_data request resolved the shop cart and swapped it into the
 * summary.
 */
class CheckoutAssetsLocalizedCartTest extends DatabaseTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        AssetLoader::resetCheckoutAssetsRuntimeCache();
        wp_scripts()->add_data('fct-checkout', 'data', '');
    }

    public function tearDown(): void
    {
        remove_all_filters('fluent_cart/checkout/localize_data');
        AssetLoader::resetCheckoutAssetsRuntimeCache();
        wp_scripts()->add_data('fct-checkout', 'data', '');
        parent::tearDown();
    }

    public function test_a_later_explicit_cart_relocalizes_the_checkout_vars(): void
    {
        $shopCart = (new CartFactory())->create();
        $blockCart = (new CartFactory())->create(['cart_group' => 'inpage']);

        $localizedHashes = [];
        add_filter('fluent_cart/checkout/localize_data', function ($data, $cart) use (&$localizedHashes) {
            $localizedHashes[] = $cart->cart_hash;
            return $data;
        }, 10, 2);

        AssetLoader::loadCheckoutAssets($shopCart);
        AssetLoader::loadCheckoutAssets($blockCart);

        $this->assertSame(
            [$shopCart->cart_hash, $blockCart->cart_hash],
            $localizedHashes,
            'The block cart must reach the localize_data filter after the head cart.'
        );

        preg_match_all('/^var fluentcart_checkout_vars = (.*);$/m', (string)wp_scripts()->get_data('fct-checkout', 'data'), $matches);
        $this->assertNotEmpty($matches[1], 'fct-checkout must carry fluentcart_checkout_vars.');

        // Later assignments win in the browser, so the last one is what the JS sees.
        $vars = json_decode(end($matches[1]), true);
        $this->assertSame($blockCart->cart_hash, $vars['cart_hash'] ?? null, 'The checkout JS must see the block cart hash, not the shop cart hash.');
    }

    public function test_the_same_cart_is_localized_only_once(): void
    {
        $cart = (new CartFactory())->create();

        $calls = 0;
        add_filter('fluent_cart/checkout/localize_data', function ($data) use (&$calls) {
            $calls++;
            return $data;
        });

        AssetLoader::loadCheckoutAssets($cart);
        AssetLoader::loadCheckoutAssets($cart);

        $this->assertSame(1, $calls, 'Loading twice with the same cart must not localize it twice.');
        $this->assertSame(
            1,
            substr_count((string)wp_scripts()->get_data('fct-checkout', 'data'), 'var fluentcart_checkout_vars ='),
            'The checkout vars must be printed once for one cart.'
        );
    }
}
