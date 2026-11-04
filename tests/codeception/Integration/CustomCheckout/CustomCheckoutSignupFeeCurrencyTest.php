<?php

namespace Tests\Integration\CustomCheckout;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Hooks\Handlers\CustomCheckout\CustomCheckout;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Order;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\ProductFactory;

class CustomCheckoutSignupFeeCurrencyTest extends DatabaseTestCase
{
    private $stopRedirect;

    public function setUp(): void
    {
        parent::setUp();
        $this->stopRedirect = function () {
            throw new \RuntimeException('redirected');
        };
        add_filter('wp_redirect', $this->stopRedirect);
    }

    public function tearDown(): void
    {
        remove_filter('wp_redirect', $this->stopRedirect);
        parent::tearDown();
    }

    private function subscriptionOrder(string $currency, $rate, int $unitPrice, int $orderSignupFee, int $quantity = 1): Order
    {
        $customer = (new CustomerFactory())->create();
        $product = (new ProductFactory())->withVariantAttributes([
            'payment_type' => 'subscription',
            'item_price'   => 1000,
            'other_info'   => [
                'payment_type'     => 'subscription',
                'repeat_interval'  => 'monthly',
                'manage_setup_fee' => 'yes',
                'signup_fee'       => 500,
                'signup_fee_name'  => 'Setup',
            ],
        ])->create();
        $variant = $product->variants->first();

        $total = ($unitPrice + $orderSignupFee) * $quantity;
        $order = (new OrderFactory())->create([
            'customer_id'  => $customer->id,
            'type'         => Status::ORDER_TYPE_SUBSCRIPTION,
            'currency'     => $currency,
            'rate'         => $rate,
            'subtotal'     => $total,
            'total_amount' => $total,
        ]);

        $items = new OrderItemFactory();
        $items->create($order->id, [
            'post_id'      => $product->ID,
            'object_id'    => $variant->id,
            'payment_type' => 'subscription',
            'quantity'     => $quantity,
            'unit_price'   => $unitPrice,
            'subtotal'     => $unitPrice * $quantity,
            'line_total'   => $unitPrice * $quantity,
        ]);
        if ($orderSignupFee) {
            $items->create($order->id, [
                'post_id'      => $product->ID,
                'object_id'    => $variant->id,
                'payment_type' => 'signup_fee',
                'title'        => 'Setup',
                'quantity'     => $quantity,
                'unit_price'   => $orderSignupFee,
                'subtotal'     => $orderSignupFee * $quantity,
                'line_total'   => $orderSignupFee * $quantity,
            ]);
        }

        return $order->fresh(['order_items']);
    }

    private function repayCartItem(Order $order): array
    {
        try {
            (new CustomCheckout())->handleCustomCheckoutRedirect(['order_hash' => $order->uuid]);
            $this->fail('custom checkout did not redirect');
        } catch (\RuntimeException $e) {
            $this->assertSame('redirected', $e->getMessage());
        }

        $cart = Cart::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($cart, 'custom checkout saved a cart');

        return $cart->cart_data[0];
    }

    public function test_repay_charges_the_signup_fee_the_order_was_priced_with_in_its_currency(): void
    {
        // 500 base cents at 0.9 is 450 in EUR; the variation still holds the base 500
        $order = $this->subscriptionOrder('EUR', '0.9', 900, 450);

        $item = $this->repayCartItem($order);

        $this->assertSame(900, (int) $item['unit_price']);
        $this->assertSame(450, (int) $item['other_info']['signup_fee'], 'the signup fee is the order\'s, not the base-currency variation fee');
    }

    public function test_repay_of_a_zero_decimal_order_does_not_charge_base_cents_as_yen(): void
    {
        $order = $this->subscriptionOrder('JPY', '150', 1500, 750);

        $item = $this->repayCartItem($order);

        $this->assertSame(750, (int) $item['other_info']['signup_fee']);
    }

    public function test_repay_adds_no_signup_fee_the_order_did_not_charge(): void
    {
        $order = $this->subscriptionOrder('USD', 1, 1000, 0);

        $item = $this->repayCartItem($order);

        $this->assertSame(0, (int) $item['other_info']['signup_fee'], 'the re-pay total must match the order, not a fee added to the product later');
    }

    public function test_base_currency_repay_keeps_the_order_signup_fee(): void
    {
        $order = $this->subscriptionOrder('USD', 1, 1000, 500);

        $item = $this->repayCartItem($order);

        $this->assertSame(500, (int) $item['other_info']['signup_fee']);
    }

    public function test_repay_of_a_multi_quantity_order_uses_the_per_unit_signup_fee(): void
    {
        $order = $this->subscriptionOrder('EUR', '0.9', 900, 450, 2);

        $item = $this->repayCartItem($order);

        $this->assertSame(2, (int) $item['quantity']);
        $this->assertSame(450, (int) $item['other_info']['signup_fee'], 'checkout multiplies the fee by quantity itself');
    }
}
