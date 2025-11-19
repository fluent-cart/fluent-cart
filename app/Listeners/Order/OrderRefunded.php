<?php

namespace FluentCart\App\Listeners\Order;

class OrderRefunded
{
    public static function handle(\FluentCart\App\Events\Order\OrderRefund $event)
    {
        if ($event->order) {
            $event->order->syncOrderAfterRefund($event->type, $event->refundedAmount);
            $event->order->updateRefundedItems($event->refundedItemIds, $event->refundedAmount);
        }
    }
}
