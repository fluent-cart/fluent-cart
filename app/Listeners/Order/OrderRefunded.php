<?php

namespace FluentCart\App\Listeners\Order;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\Framework\Support\Arr;

class OrderRefunded
{
    public static function handle(\FluentCart\App\Events\Order\OrderRefund $event)
    {
        if ($event->order) {
            // The order total and the line totals commit together, so a failure leaves the refund uncounted for a retry.
            $db = App::db();
            $db->beginTransaction();
            try {
                // Order-level total always reflects the full charge (items + shipping).
                $event->order->syncOrderAfterRefund($event->type, $event->refundedAmount);
                if ($event->isItemRefund) {
                    self::addItemRefundTotals($event->order, $event->refundedItems, $event->refundedAmount);
                } else {
                    $overflow = $event->order->updateRefundedItems($event->refundedItemIds, $event->refundedAmount);
                    if (!$event->refundedItemIds) {
                        $event->order->addRefundOverflowToShipping($overflow);
                    }
                }
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollBack();
                throw $e;
            }

            $charges = OrderTransaction::query()
                ->where('order_id', $event->order->id)
                ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
                ->get();

            foreach ($charges as $charge) {
                PaymentHelper::recountTransactionRefundedTotal($charge);
            }
        }

        if ($event->order->customer_id) {
            $customer = Customer::query()->where('id', $event->order->customer_id)->first();
            if (!empty($customer)) {
                $customer->recountStat();
            }
        }
    }

    private static function addItemRefundTotals(Order $order, array $refundedItems, int $refundedAmount)
    {
        $left = $refundedAmount;
        $amounts = [];
        foreach ($refundedItems as $item) {
            $amount = min((int) Arr::get($item, 'refund_amount', 0), $left);
            if ($amount > 0) {
                $amounts[(int) $item['id']] = $amount;
                $left -= $amount;
            }
        }

        $order->addItemRefunds($amounts);
    }
}
