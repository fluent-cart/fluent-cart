<?php

namespace Tests\Integration\CustomCheckout;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Hooks\Handlers\CustomCheckout\CustomCheckout;
use FluentCart\App\Models\Cart;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\ProductFactory;

/**
 * DP-009: the admin payment link cart must belong to the order's customer,
 * or customer-based Dynamic Pricing conditions see a guest.
 */
class CustomCheckoutCartCustomerTest extends DatabaseTestCase
{
    public function test_payment_link_cart_carries_the_order_customer_id(): void
    {
        $customer = (new CustomerFactory())->create();
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        $order = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'payment_status' => Status::PAYMENT_PENDING,
        ]);
        (new OrderItemFactory())->create($order->id, [
            'post_id'   => $product->ID,
            'object_id' => $variant->id,
        ]);

        $cart = $this->cartFromRedirect(function () use ($order) {
            (new CustomCheckout())->handleCustomCheckoutRedirect(['order_hash' => $order->uuid]);
        });

        $this->assertSame((int) $customer->id, (int) $cart->customer_id, 'The payment link cart must be saved with the order customer id');
    }

    /**
     * The handler ends in wp_redirect() + exit(), so stop it at the redirect
     * filter and load the cart it saved from the fct_cart_hash in the URL.
     */
    private function cartFromRedirect(callable $run): Cart
    {
        $stop = function ($location) {
            throw new \RuntimeException($location);
        };
        add_filter('wp_redirect', $stop, 1);

        $location = '';
        try {
            $run();
        } catch (\RuntimeException $e) {
            $location = $e->getMessage();
        } finally {
            remove_filter('wp_redirect', $stop, 1);
        }

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertNotEmpty($query['fct_cart_hash'] ?? '', 'The handler must redirect to checkout with a cart hash');

        $cart = Cart::query()->where('cart_hash', $query['fct_cart_hash'])->first();
        $this->assertNotNull($cart, 'The redirect cart must be saved');

        return $cart;
    }
}
