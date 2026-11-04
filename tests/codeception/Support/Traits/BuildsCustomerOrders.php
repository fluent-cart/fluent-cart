<?php

namespace Tests\Support\Traits;

use FluentCart\App\Models\Order;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

/**
 * Builds an order attached to a fresh customer and returns it reloaded with the
 * `customer` relation — the shape the email/notification services receive from
 * production event payloads. Shared by the email service Integration tests,
 * which all start from "an order with a real customer".
 */
trait BuildsCustomerOrders
{
    protected function makeOrderWithCustomer(array $overrides = []): Order
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create(array_merge(['customer_id' => $customer->id], $overrides));

        return Order::query()->with('customer')->find($order->id);
    }
}
