<?php

namespace FluentCart\App\Listeners\Order;

use FluentCart\App\Models\Customer;

class OrderDeleted
{
    public static function handle(\FluentCart\App\Events\Order\OrderDeleted $event)
    {
        if ($event->order->customer_id) {
            $customer = Customer::query()->where('id', $event->order->customer_id)->first();
            if (!empty($customer)) {
                $customer->recountStat();
            }
        }
    }

}