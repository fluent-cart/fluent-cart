<?php

namespace FluentCart\App\Listeners\Order;

use FluentCart\App\Helpers\CustomerHelper;

class OrderBulkAction
{
    public static function handle(\FluentCart\App\Events\Order\OrderBulkAction $event)
    {
        if ($event->customerIds) {
            (new CustomerHelper)->calculateCustomerStats($event->customerIds);
        }
    }
}