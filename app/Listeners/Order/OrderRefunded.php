<?php

namespace FluentCart\App\Listeners\Order;

use FluentCart\App\Models\Customer;

class OrderRefunded
{
    public static function handle(\FluentCart\App\Events\Order\OrderRefund $event)
    {
        if ($event->order) {
            $event->order->syncOrderAfterRefund($event->type, $event->refundedAmount);
            $event->order->updateRefundedItems($event->refundedItemIds, $event->refundedAmount);
        }

        if ($event->order->customer_id) {
            $customer = Customer::query()->where('id', $event->order->customer_id)->first();
            if (!empty($customer)) {
                $customer->recountStat();
            }
        }
    }
}
