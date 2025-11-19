<?php

namespace FluentCart\App\Modules\Subscriptions\Services;

use FluentCart\App\Events\Subscription\SubscriptionEOT;
use FluentCart\App\Events\Subscription\SubscriptionRenewed;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\API\API;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\Framework\Support\Arr;

class SubscriptionService
{
    public static function recordRenewalPayment($transactionData, $subscriptionModel = null, $subscriptionUpdateArgs = [])
    {
        if (!$subscriptionModel) {
            $subscriptionModel = Subscription::query()->find($transactionData['subscription_id']);
        }

        if (!$subscriptionModel) {
            return new \WP_Error('subscription_not_found', __('Subscription not found.', 'fluent-cart'));
        }

        $vendorTransactionId = $transactionData['vendor_charge_id'] ?? null;

        if ($vendorTransactionId) {
            if (OrderTransaction::query()->where('vendor_charge_id', $vendorTransactionId)->exists()) {
                return new \WP_Error('transaction_exists', __('This transaction already exists for this subscription.', 'fluent-cart'));
            }
        }

        $parentOrder = $subscriptionModel->order;

        if (!$parentOrder) {
            return new \WP_Error('parent_order_not_found', __('Parent order not found for this subscription.', 'fluent-cart'));
        }

        $transactionDefaults = [
            'order_id' => $parentOrder->id,
            'subscription_id' => $subscriptionModel->id,
            'order_type' => Status::ORDER_TYPE_RENEWAL,
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'payment_method' => $subscriptionModel->current_payment_method,
            'payment_mode' => $parentOrder->mode,
            'status' => Status::TRANSACTION_SUCCEEDED,
            'currency' => $parentOrder->currency,
            'total' => $subscriptionModel->recurring_total,
            'meta' => Arr::get($transactionData, 'meta', [])
        ];

        $transactionData = wp_parse_args($transactionData, $transactionDefaults);

        $createdAt = Arr::get($transactionData, 'created_at', DateTime::now()->format('Y-m-d H:i:s'));

        $taxTotal = Arr::get($transactionData, 'tax_total', 0);
        $subtotal = $transactionData['total'];
        if ($taxTotal) {
            $subtotal = $transactionData['total'] - $taxTotal;
        }

        // Let's create the order item first
        $variation = $subscriptionModel->variation;
        $product = $subscriptionModel->product;

        $parentOrderItem = OrderItem::query()
            ->where('order_id', $parentOrder->order_id)
            ->where('payment_type', Status::ORDER_TYPE_SUBSCRIPTION)
            ->first();

        $orderItem = [
            'post_id' => $subscriptionModel->product_id,
            'object_id' => $subscriptionModel->variation_id,
            'payment_type' => Status::ORDER_TYPE_SUBSCRIPTION,
            'post_title' => $product && $product->post_title ? $product->post_title : $subscriptionModel->item_name,
            'title' => $product && $variation ? $variation->variation_title : '',
            'quantity' => 1,
            'fulfillment_type' => $parentOrderItem ? $parentOrderItem->fulfillment_type : 'digital',
            'unit_price' => $subtotal,
            'subtotal' => $subtotal,
            'tax_amount' => $taxTotal,
            'line_total' => $transactionData['total'],
            'line_meta' => [],
            'other_info' => []
        ];

        $fulfillmentType = $orderItem['fulfillment_type'];

        // Let's create the order first
        $childOrderData = [
            'parent_id' => $parentOrder->id,
            'fulfillment_type' => $fulfillmentType,
            'status' => $fulfillmentType === 'physical' ? Status::ORDER_PROCESSING : Status::ORDER_COMPLETED,
            'type' => Status::ORDER_TYPE_RENEWAL,
            'mode' => $transactionData['payment_mode'],
            'shipping_status' => $fulfillmentType === 'physical' ? Status::SHIPPING_UNSHIPPED : '',
            'customer_id' => $subscriptionModel->customer_id,
            'payment_method' => $transactionData['payment_method'],
            'payment_status' => $transactionData['status'] === Status::TRANSACTION_SUCCEEDED ? Status::PAYMENT_PAID : Status::PAYMENT_PENDING,
            'currency' => $transactionData['currency'],
            'subtotal' => $subtotal,
            'tax_total' => $taxTotal,
            'total_amount' => $transactionData['total'],
            'total_paid' => $transactionData['status'] === Status::TRANSACTION_SUCCEEDED ? $transactionData['total'] : 0,
            'completed_at' => DateTime::now()->format('Y-m-d H:i:s'),
            'created_at' => $createdAt,
            'config' => []
        ];

        $childOrder = Order::query()->create($childOrderData);

        if (!$childOrder) {
            return new \WP_Error('order_creation_failed', __('Failed to create child order for the subscription renewal.', 'fluent-cart'));
        }

        //  Create Order Item
        $orderItem['order_id'] = $childOrder->id;
        $orderItem['created_at'] = $createdAt;
        OrderItem::query()->create($orderItem);

        // let's create the transaction
        $transactionData['order_id'] = $childOrder->id;

        $createdTransaction = OrderTransaction::query()->create($transactionData);

        $subscriptionModel = self::syncSubscriptionStates($subscriptionModel, $subscriptionUpdateArgs);

        (new SubscriptionRenewed($subscriptionModel, $childOrder, $parentOrder, $childOrder->customer))->dispatch();

        return $createdTransaction;
    }

    /**
     * @param $subscriptionModel
     * @param $subscriptionUpdateArgs
     *      - next_billing_date - You must provide this if you want to update the next billing date.
     * *    - Accepts all other filliable attributes of the Subscription model.
     * @return mixed
     */
    public static function syncSubscriptionStates(Subscription $subscriptionModel, $subscriptionUpdateArgs = [])
    {
        $billsCount = OrderTransaction::query()
            ->where('subscription_id', $subscriptionModel->id)
            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
            ->where('total', '>', 0)
            ->count();

        $subscriptionUpdateArgs['bill_count'] = $billsCount;
        $billTimes = $subscriptionModel->bill_times;
        $oldStatus = $subscriptionModel->status;

        $subscriptionUpdateArgs['bill_count'] = $billsCount;
        $isEot = $billTimes > 0 && $billsCount >= $billTimes;

        if ($isEot) {
            $subscriptionUpdateArgs['status'] = 'completed';
            $subscriptionUpdateArgs['next_billing_date'] = NULL;
        } else if (!$subscriptionModel->next_billing_date && empty($subscriptionUpdateArgs['next_billing_date'])) {
            $subscriptionUpdateArgs['next_billing_date'] = $subscriptionModel->guessNextBillingDate();
        }

        $givenSubscriptionStatus = Arr::get($subscriptionUpdateArgs, 'status');
        if ($givenSubscriptionStatus === Status::SUBSCRIPTION_CANCELED && empty($subscriptionUpdateArgs['canceled_at'])) {
            $subscriptionUpdateArgs['canceled_at'] = gmdate('Y-m-d H:i:s');
        }

        $subscriptionModel->fill($subscriptionUpdateArgs);
        $dirtyData = $subscriptionModel->getDirty();
        $subscriptionModel->save();

        $meta = array_filter(Arr::get($subscriptionUpdateArgs, 'meta', []));

        foreach ($meta as $key => $value) {
            $subscriptionModel->updateMeta($key, $value);
        }

        if ($oldStatus === $subscriptionModel->status) {
            if ($dirtyData) {
                do_action('fluent_cart/subscription/data_updated', [
                    'subscription' => $subscriptionModel,
                    'updated_data' => $dirtyData
                ]);
            }

            return $subscriptionModel; // No change in status
        }

        if ($isEot) {
            (new SubscriptionEOT($subscriptionModel, $subscriptionModel->order))->dispatch();
        }

        do_action('fluent_cart/payments/subscription_status_changed', [
            'subscription' => $subscriptionModel,
            'order' => $subscriptionModel->order,
            'customer' => $subscriptionModel->customer,
            'old_status' => $oldStatus,
            'new_status' => $subscriptionModel->status
        ]);

        /**
         * lists of hooks for this action
         * fluent_cart/payments/subscription_cancelled
         * fluent_cart/payments/subscription_active
         * fluent_cart/payments/subscription_paused
         * fluent_cart/payments/subscription_expired
         * fluent_cart/payments/subscription_failing
         * fluent_cart/payments/subscription_expiring
         * fluent_cart/payments/subscription_completed
         **/
        do_action('fluent_cart/payments/subscription_' . $subscriptionModel->status, [
            'subscription' => $subscriptionModel,
            'order' => $subscriptionModel->order,
            'customer' => $subscriptionModel->customer,
            'old_status' => $oldStatus,
            'new_status' => $subscriptionModel->status
        ]);

        return $subscriptionModel;
    }


    /**
     *
     * Use this method when you are reactivating a expired subscription manually by creating order, transaction etc.
     * Make sure you already handle your transaction statuses!
     *
     * @param \FluentCart\App\Models\Subscription $subscriptionModel
     * @param \FluentCart\App\Models\OrderTransaction $transaction
     * @param $args
     * @return mixed
     */
    public static function recordManualRenewal(Subscription $subscriptionModel, OrderTransaction $transaction, $args = [])
    {
        $renewalOrder = $transaction->order;

        $orderUpdateData = [
            'status' => $renewalOrder->fulfillment_type === 'physical' ? Status::ORDER_PROCESSING : Status::ORDER_COMPLETED,
            'type' => Status::ORDER_TYPE_RENEWAL,
            'payment_method' => $transaction->payment_method,
            'payment_status' => Status::PAYMENT_PAID,
            'total_paid' => $transaction->total,
            'completed_at' => DateTime::now()->format('Y-m-d H:i:s')
        ];

        $renewalOrder->fill($orderUpdateData);
        $renewalOrder->save();

        if ($billingInfo = Arr::get($args, 'billing_info', [])) {
            $subscriptionModel->updateMeta('active_payment_method', $billingInfo);
        }

        $updateData = wp_parse_args(Arr::get($args, 'subscription_args', []), [
            'status' => Status::SUBSCRIPTION_ACTIVE,
            'current_payment_method' => $transaction->payment_method,
        ]);

        $subscriptionModel = self::syncSubscriptionStates($subscriptionModel, $updateData);

        (new StatusHelper($transaction->order))->syncOrderStatuses($transaction);

        (new SubscriptionRenewed($subscriptionModel, $renewalOrder, $subscriptionModel->order, $renewalOrder->customer))->dispatch();

        return $subscriptionModel;
    }
}
