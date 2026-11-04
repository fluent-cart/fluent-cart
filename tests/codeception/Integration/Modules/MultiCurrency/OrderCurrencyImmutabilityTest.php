<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\Api\Resource\OrderResource;
use FluentCart\App\Models\Order;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;

/**
 * Multi-currency invariant #1: an order's currency is stamped once at creation
 * and never changes. dev-docs/multi-currency/plan.md §2.3, §12.
 */
class OrderCurrencyImmutabilityTest extends DatabaseTestCase
{
    public function test_admin_edit_keeps_a_non_store_currency_order_in_its_own_currency(): void
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id' => $customer->id,
            'currency'    => 'EUR',
            'rate'        => '1.000000000000',
        ]);
        $item = (new OrderItemFactory())->create($order->id);

        $result = OrderResource::update([
            'orderData'         => [
                'currency'    => 'USD',
                'rate'        => 1,
                'order_items' => [
                    [
                        'id'         => $item->id,
                        'post_id'    => $item->post_id,
                        'object_id'  => $item->object_id,
                        'title'      => $item->title,
                        'post_title' => $item->post_title,
                        'quantity'   => 2,
                        'unit_price' => $item->unit_price,
                    ],
                ],
            ],
            'deletedItems'      => [],
            'discount'          => '',
            'shipping'          => '',
            'couponCalculation' => [],
        ], $order->id);

        $this->assertNotInstanceOf(\WP_Error::class, $result);

        $fresh = Order::query()->find($order->id);

        // Before the fix every admin edit relabelled the order with the store
        // currency (USD) without touching a single amount.
        $this->assertSame('EUR', $fresh->currency);
    }
}
