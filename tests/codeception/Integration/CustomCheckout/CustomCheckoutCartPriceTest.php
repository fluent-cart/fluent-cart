<?php

namespace Tests\Integration\CustomCheckout;

use FluentCart\App\Hooks\Handlers\CustomCheckout\CustomCheckout;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\ProductFactory;

class CustomCheckoutCartPriceTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;
    private OrderItemFactory $itemFactory;
    private ProductFactory $productFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
        $this->itemFactory = new OrderItemFactory();
        $this->productFactory = new ProductFactory();
    }

    /**
     * Reproduces the reported bug: a repeat-student discount adjusts an order
     * item's unit_price below the product's current catalogue price. The
     * custom-checkout re-pay cart must price the item at the order's agreed
     * unit_price, not the current catalogue item_price.
     */
    public function test_one_time_order_cart_item_uses_order_unit_price_not_catalogue_price(): void
    {
        $customer = $this->customerFactory->create();
        $product = $this->productFactory->withVariantAttributes([
            'item_price' => 12000,
        ])->create();
        $variant = $product->variants->first();

        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'subtotal'    => 9000,
            'total_amount' => 9000,
        ]);

        $this->itemFactory->create($order->id, [
            'post_id'    => $product->ID,
            'object_id'  => $variant->id,
            'unit_price' => 9000,
            'subtotal'   => 9000,
            'line_total' => 9000,
        ]);

        $order = $order->fresh(['order_items']);

        $reflection = new \ReflectionMethod(CustomCheckout::class, 'buildOneTimeCartItems');
        $reflection->setAccessible(true);
        $items = $reflection->invoke(null, $order, 0);

        $this->assertCount(1, $items);
        $this->assertSame(9000, (int) $items[0]['price'], 'Cart item price must match the order unit_price');
        $this->assertNotSame(12000, (int) $items[0]['price'], 'Cart item price must not fall back to the current catalogue price');
    }
}
