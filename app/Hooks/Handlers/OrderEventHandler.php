<?php

namespace FluentCart\App\Hooks\Handlers;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Customer;
use FluentCart\Framework\Support\Arr;

class OrderEventHandler
{
    public function maybeRecountCustomerStat($data)
    {
        $order = Arr::get($data, 'order');
        $oldStatus = Arr::get($data, 'old_status');
        $newStatus = Arr::get($data, 'new_status');

        if ($newStatus == Status::PAYMENT_PAID || $oldStatus == Status::PAYMENT_PAID) {
            $order->customer->recountStat();
        }
    }

    public function handleOrderDeleted($data)
    {
//        $order = Arr::get($data, 'order');
        $customer = Arr::get($data, 'customer');
        if ($customer) {
            $customer->recountStat();
        }
    }
}
