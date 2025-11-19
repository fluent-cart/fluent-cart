<?php

namespace FluentCart\App\Listeners\Order;

use FluentCart\App\Models\Customer;

class OrderCreated
{
    public static function handle(\FluentCart\App\Events\Order\OrderCreated $event)
    {
        if ($event->order->customer_id) {
            $customer = Customer::query()->where('id', $event->order->customer_id)->first();
            (!empty($customer)) ? $customer->recountStat() : '';
        }
    }

}