<?php

namespace FluentCart\App\Listeners\Order;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\OrderTransaction;

class OrderUpdated
{
    public static function handle(\FluentCart\App\Events\Order\OrderUpdated $event)
    {
        static::updateTransaction($event->oldOrder, $event->order);
    }

    public static function updateTransaction($oldOrder, $newOrder)
    {
        if ($oldOrder->total_amount == $newOrder->total_amount) {
            return;
        }

        $transaction = OrderTransaction::query()->where('order_id', $newOrder->id)
            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
            ->where('status', 'pending')->first();

        if ($transaction) {
            $transaction->total = $newOrder->total_amount;
            $transaction->save();
        }
    }

}