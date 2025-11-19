<?php

namespace FluentCart\App\Helpers;

use FluentCart\App\Events\Order\OrderPaid;
use FluentCart\App\Events\Order\OrderStatusUpdated;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;


class StatusHelper
{
    protected $order;

    public function __construct($order = null)
    {
        $this->order = $order;
    }

    public function setOrder($order)
    {
        $this->order = $order;
        return $this;
    }

    public function changeOrderStatus($orderStatus, $paymentStatus, $title, $slug)
    {
        $oldStatus = $this->order->status;
        if ($orderStatus === $oldStatus) {
            return $this;
        }
        if ($orderStatus === Status::ORDER_COMPLETED) {
            $this->order->completed_at = DateTime::gmtNow();
        }
        if ($paymentStatus === Status::PAYMENT_REFUNDED) {
            $this->order->refunded_at = DateTime::gmtNow();
        }
        $this->order->status = $orderStatus;
        $this->order->payment_method = $slug;
        $this->order->payment_status = $paymentStatus;
        $this->order->payment_method_title = $title;
        $this->order->save();

        $actionActivity = [
            'title'   => __('Order status updated', 'fluent-cart'),
            'content' => sprintf(
                /* translators: 1: old status, 2: new status */
                __('Order status has been updated from %1$s to %2$s', 'fluent-cart'), $oldStatus, $orderStatus)
        ];

        (new OrderStatusUpdated($this->order, $oldStatus, $orderStatus, true, $actionActivity, 'order_status'))->dispatch();

        return $this;
    }

    public function updateTotalPaid($amount)
    {
        $this->order->total_paid = intval($amount) + intval($this->order->total_paid);
        if ($this->order->total_paid >= $this->order->total_amount) {
            $this->order->payment_status = Status::PAYMENT_PAID;
            $this->triggerPaymentStatusActions($this->order, Status::PAYMENT_PAID);
        } else if ($this->order->total_paid < $this->order->total_amount && Status::PAYMENT_PARTIALLY_PAID !== $this->order->payment_status) {
            $this->order->payment_status = Status::PAYMENT_PARTIALLY_PAID;
        }
        $this->order->save();
        return $this;
    }

    public function triggerPaymentStatusActions($order, $paymentStatus)
    {
        if (Status::PAYMENT_PAID === $paymentStatus) {
            $transaction = OrderTransaction::query()->where('order_id', $order->id)
                ->where('status', Status::TRANSACTION_SUCCEEDED)
                ->first();
            (new OrderPaid($order, $this->order->customer, $transaction))->dispatch();
        }

        // Trigger any other payment status actions based on the payment status
    }

    public function updateTransactionData($updateData = [], $transaction = null)
    {
        if ($transaction) {
            $transaction->update($updateData);
            return $this;
        }

        OrderTransaction::query()->where('order_id', $this->order->id)
            ->where('order_type', 'payment')
            ->update($updateData);

        return $this;
    }

    public function syncOrderStatuses($latestTransaction = null)
    {
        // Change the order status depends on payment paid
        // Change the paid total depends on the order total amount
        // Change the payment status depends on the order total paid

        $successStatuses = Status::getTransactionSuccessStatuses();

        $transactionPaidTotal = OrderTransaction::query()
            ->where('order_id', $this->order->id)
            ->whereIn('status', $successStatuses)
            ->sum('total');

        $refundedTotal = OrderTransaction::query()
            ->where('order_id', $this->order->id)
            ->where('status', Status::TRANSACTION_REFUNDED)
            ->sum('total');

        $isFullyPaid = $this->order->total_amount <= ($transactionPaidTotal - $refundedTotal);

        $orderPaymentStatus = $this->order->payment_status;
        if ($isFullyPaid) {
            $orderPaymentStatus = Status::PAYMENT_PAID;
        } else if ($refundedTotal) {
            $orderPaymentStatus = Status::PAYMENT_PARTIALLY_REFUNDED;
        }

        $orderStatus = $this->order->status;
        if (!in_array($orderStatus, Status::getOrderSuccessStatuses())) {
            if ($orderPaymentStatus == Status::PAYMENT_PAID) {
                $orderStatus = Status::ORDER_PROCESSING;
            }
        }

        $oldOrderStatus = $this->order->status;
        $oldPaymentStatus = $this->order->payment_status;

        $this->order->status = $orderStatus;
        $this->order->payment_status = $orderPaymentStatus;
        $this->order->total_paid = $transactionPaidTotal;
        $this->order->total_refund = $refundedTotal;
        $this->order->save();

        if (($this->order->type === 'renewal') || ($oldPaymentStatus != $this->order->payment_status && $this->order->payment_status == Status::PAYMENT_PAID)) {
            if (!$latestTransaction) {
                $latestTransaction = OrderTransaction::query()
                    ->where('order_id', $this->order->id)
                    ->orderBy('id', 'desc')
                    ->first();
            }

            $relatedCart = Cart::query()->where('order_id', $this->order->id)
                ->where('stage', '!=', 'completed')
                ->first();

            if ($relatedCart) {
                $relatedCart->stage = 'completed';
                $relatedCart->completed_at = DateTime::now()->format('Y-m-d H:i:s');
                $relatedCart->save();

                do_action('fluent_cart/cart_completed', [
                    'cart'  => $relatedCart,
                    'order' => $this->order,
                ]);

                $onSuccessActions = Arr::get($relatedCart->checkout_data, '__on_success_actions__', []);

                if ($onSuccessActions) {
                    foreach ($onSuccessActions as $onSuccessAction) {
                        $onSuccessAction = (string)$onSuccessAction;
                        if (has_action($onSuccessAction)) {
                            do_action($onSuccessAction, [
                                'cart'        => $relatedCart,
                                'order'       => $this->order,
                                'transaction' => $latestTransaction
                            ]);
                        }
                    }
                }
            }

            (new OrderPaid($this->order, $this->order->customer, $latestTransaction))->dispatch();
        }

        if ($oldOrderStatus != $this->order->status) {
            $actionActivity = [
                'title'   => __('Order status updated', 'fluent-cart'),
                'content' => sprintf(
                    /* translators: 1: old status, 2: new status */
                    __('Order status has been updated from %1$s to %2$s', 'fluent-cart'), $oldOrderStatus, $this->order->status)
            ];
            (new OrderStatusUpdated($this->order, $oldOrderStatus, $this->order->status, true, $actionActivity, 'order_status'))->dispatch();
        }

        // Now if it's a digital product so we will make it auto completed
        if ($this->order->fulfillment_type == 'digital'
            && !$refundedTotal && ($oldOrderStatus != $this->order->status)
            && ($this->order->status != Status::ORDER_COMPLETED)
        ) {
            $oldOrderStatus = $this->order->status;
            $this->order->status = Status::ORDER_COMPLETED;
            $this->order->completed_at = DateTime::gmtNow();
            $this->order->save();

            $actionActivity = [
                'title'   => __('Order status updated', 'fluent-cart'),
                'content' => sprintf(
                    /* translators: 1: old status, 2: new status */
                    __('Order status has been updated from %1$s to %2$s', 'fluent-cart'), $oldOrderStatus, $this->order->status)
            ];

            (new OrderStatusUpdated($this->order, $oldOrderStatus, $this->order->status, true, $actionActivity, 'order_status'))->dispatch();
        }

        return $this->order;
    }
}
