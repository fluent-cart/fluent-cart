<?php

namespace Tests\Integration\Services;

use FluentCart\Api\Checkout\CheckoutApi;
use FluentCart\Api\Resource\FrontendResource\CartResource;
use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Order;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Traits\BindsFluentRequest;
use Tests\Support\WpDieCapture;

/**
 * SCAN-08: the two public entry points that use RateLimiter must refuse the
 * request over their limit on a store without an external object cache.
 */
class RateLimitedEntryPointsTest extends DatabaseTestCase
{
    use BindsFluentRequest;

    private bool $hadExtObjectCache;

    public function setUp(): void
    {
        parent::setUp();
        $this->hadExtObjectCache = (bool) wp_using_ext_object_cache(false);
        $this->clearLimiterWindows();
        CartResource::resetCartCache();
    }

    public function tearDown(): void
    {
        $this->clearLimiterWindows();
        wp_using_ext_object_cache($this->hadExtObjectCache);
        parent::tearDown();
    }

    // The windows are keyed by IP and persist in wp_options; clear them so no other test inherits hits.
    private function clearLimiterWindows(): void
    {
        $ip = md5(AddressHelper::getIpAddress());
        foreach (['place_order_attempt', 'generate_instant_checkout_cart_attempt'] as $key) {
            delete_transient('fct_rate_limit_' . $ip . '_' . $key);
        }
    }

    public function test_instant_checkout_cart_creation_is_limited_to_10_per_minute(): void
    {
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 1000, 'manage_stock' => 0])->create()->variants->first();
        $before = Cart::query()->count();

        for ($i = 1; $i <= 10; $i++) {
            $result = WpDieCapture::capture(function () use ($variation) {
                CartResource::resetCartCache();
                $cart = CartResource::generateCartForInstantCheckout($variation->id, 1);
                wp_send_json(['ok' => !is_wp_error($cart)], 200);
            });
            $this->assertSame(200, $result['status'], 'instant cart ' . $i . ' is within the limit of 10. Body: ' . $result['body']);
        }

        $eleventh = WpDieCapture::capture(function () use ($variation) {
            CartResource::resetCartCache();
            CartResource::generateCartForInstantCheckout($variation->id, 1);
            wp_send_json(['ok' => true], 200);
        });

        $this->assertSame(429, $eleventh['status'], 'the 11th instant cart in a minute must be refused. Body: ' . $eleventh['body']);
        $this->assertSame($before + 10, Cart::query()->count(), 'only 10 instant carts may be created');
    }

    public function test_place_order_is_limited_to_5_attempts_per_minute(): void
    {
        $ordersBefore = Order::query()->count();
        $this->bindFluentRequest([]);

        for ($i = 1; $i <= 5; $i++) {
            $result = WpDieCapture::capture(function () {
                CheckoutApi::placeOrder([], true);
            });
            $this->assertNotSame(429, $result['status'], 'attempt ' . $i . ' is within the limit of 5. Body: ' . $result['body']);
        }

        $sixth = WpDieCapture::capture(function () {
            CheckoutApi::placeOrder([], true);
        });

        $this->assertSame(429, $sixth['status'], 'the 6th place-order attempt in a minute must be refused. Body: ' . $sixth['body']);
        $this->assertSame($ordersBefore, Order::query()->count(), 'no order is created by refused attempts');
    }
}
