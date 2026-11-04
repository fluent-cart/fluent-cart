<?php

namespace Tests\Integration\Resources;

use FluentCart\Api\Resource\FrontendResource\CartResource;
use FluentCart\App\Models\Cart;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * SCAN-07: a public cart update with a bad item must not leave an empty cart
 * row behind. Before, the device cart was created and saved before the item
 * was checked, so every rejected request from a cookie-less client added a row.
 */
class CartUpdateRejectedItemTest extends WPTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        // A client with no cart cookie and no login, as the finding describes.
        unset($_COOKIE['fct_cart_hash']);
        wp_set_current_user(0);
        CartResource::resetCartCache();
    }

    public function tearDown(): void
    {
        CartResource::resetCartCache();
        parent::tearDown();
    }

    public function test_a_missing_item_id_does_not_create_a_cart(): void
    {
        $before = Cart::query()->count();

        $result = CartResource::update(['item_id' => 0, 'quantity' => 1]);

        $this->assertWPError($result, 'a missing item id must be rejected');
        $this->assertSame($before, Cart::query()->count(), 'a rejected update must not persist an empty cart');
    }

    public function test_an_unknown_item_id_does_not_create_a_cart(): void
    {
        $before = Cart::query()->count();

        $result = CartResource::update(['item_id' => 999999999, 'quantity' => 1]);

        $this->assertWPError($result, 'an unknown item id must be rejected');
        $this->assertSame($before, Cart::query()->count(), 'a rejected update must not persist an empty cart');
    }

    public function test_a_valid_item_still_creates_the_device_cart(): void
    {
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 1000, 'manage_stock' => 0])->create()->variants->first();
        $before = Cart::query()->count();

        $cart = CartResource::update(['item_id' => $variation->id, 'quantity' => 1]);

        $this->assertNotWPError($cart, 'a valid item must still be added');
        $this->assertSame($before + 1, Cart::query()->count(), 'the first valid update creates the device cart');
        $this->assertSame((int) $variation->id, (int) $cart->fresh()->cart_data[0]['object_id'], 'the item must be in the new cart');
    }
}
