<?php

namespace FluentCartPro\App\Modules\PaymentMethods\PaddleGateway;

use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCartPro\App\Modules\PaymentMethods\PaddleGateway\API\API;
use FluentCart\Api\CurrencySettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductVariation;
use FluentCart\Framework\Support\Arr;

class PaddleHelper
{
    /**
     * Process remote refund via Paddle API
     */
    public static function processRemoteRefund($transaction, $amount, $args)
    {
        $reason = Arr::get($args, 'reason', '');
        $itemIds = Arr::get($args, 'item_ids', []);
        $order = $transaction->order;

        $paddleTransactionId = $transaction->vendor_charge_id;
        if (!$paddleTransactionId) {
            return new \WP_Error('invalid_refund', __('Invalid transaction ID for refund.', 'fluent-cart-pro'));
        }

        $type = Arr::get($args, 'type', 'partial');

        if ($amount >= $transaction->total) {
            $type = 'full';
        }

        $taxMode = '';
        if (isset($args['tax_mode'])) {
            $taxMode = $args['tax_mode'];
        }

        if (!$taxMode) {
            $taxMode = (new PaddleSettings())->get('tax_mode');
        }

        $reason = !empty($reason) ? $reason : 'Refunded on Customer Request';

        $mappedPaddleItemIds = Arr::get($transaction->meta, 'mapped_paddle_item_ids', []);
        $itemsWithProportionalAmount = self::getItemIdsWithProportionalRefundableAmount($transaction, $itemIds, $amount);
        $totalRefundableAmountFromItems = 0;
        foreach ($itemsWithProportionalAmount as $item) {
            $totalRefundableAmountFromItems += $item['amount'];
        }

        if ($totalRefundableAmountFromItems < $amount && !empty($itemIds)) {
            return new \WP_Error('invalid_refund', __('Invalid refund amount. Please check the items available refund amounts.', 'fluent-cart-pro'));
        } else if ($totalRefundableAmountFromItems < $amount && empty($itemIds)){
            $type = 'full';
        }

        if ($type == 'partial' && (empty($mappedPaddleItemIds) || !$itemsWithProportionalAmount)) {
            return new \WP_Error('invalid_refund', __('Partial refund not allowed without any items selected.', 'fluent-cart-pro'));
        }

        if ($type == 'partial' && $totalRefundableAmountFromItems < $amount) {
            return new \WP_Error('invalid_refund', __('Invalid refund amount. Please check the items available refund amounts.', 'fluent-cart-pro'));
        }

        if ($type == 'full' && $amount < ($order->total_paid  - $order->total_refund) ) {
            return new \WP_Error('invalid_refund', __('Partial refund not allowed without any items selected.', 'fluent-cart-pro'));
        }

        $refundData = [
            'action' => 'refund',
            'reason' => $reason,
            'type' => $type,
            'transaction_id' => $paddleTransactionId
        ];

        if ($type == 'partial') {
            $items = [];
            foreach ($itemsWithProportionalAmount as $item) {
                $items[] = [
                    'item_id' => $mappedPaddleItemIds[$item['id']],
                    'type' => 'partial',
                    'amount' => (string) (int) $item['amount']
                ];
            }

            $refundData['items'] = $items;

            if ($taxMode) {
                $refundData['tax_mode'] = $taxMode;
            }
        }

        $refunded = (new API())->createPaddleObject('adjustments', $refundData, $transaction->payment_mode);

        if (is_wp_error($refunded)) {
            return $refunded;
        }

        $status = Arr::get($refunded, 'data.status');

        $acceptedStatus = ['approved', 'pending_approval'];
        if (!in_array($status, $acceptedStatus)) {
            return new \WP_Error('refund_failed', __('Refund could not be processed in paddle. Please check on your paddle account', 'fluent-cart-pro'));
        }

        if ($status == 'approved') {
            $status = 'refunded';
        }

        if ($status == 'pending_approval') {
            $status = 'pending_approval';
        }

        return Arr::get($refunded, 'data.id');
    }

    public static function getItemIdsWithProportionalRefundableAmount($transactionModel, $itemIds, $refundedAmount) : array
    {
        $totalItems = count($itemIds);

        if ($totalItems === 1) {
            return [
                [
                    'id' => $itemIds[0],
                    'amount' => $refundedAmount
                ]
            ];
        }

        if ($totalItems === 0) {
            $itemIds = OrderItem::query()->where('order_id', $transactionModel->order_id)
                ->pluck('id')->toArray();
        }


        // Calculate remaining amount for each item
        $items = [];
        $totalRemain = 0;
        foreach ($itemIds as $itemId) {
            $orderItem = OrderItem::find($itemId);
            $remain = max(0, $orderItem->line_total - $orderItem->refund_total);
            $items[] = [
                'model'  => $orderItem,
                'remain' => $remain
            ];
            $totalRemain += $remain;
        }

        if ($totalRemain == 0) {
            return [];
        }

        if ($totalRemain < $refundedAmount) {
            $refundedAmount = $totalRemain;
        }

        // Distribute refund proportionally
        $itemsWithProportionalAmount = [];
        $distributed = 0;
        foreach ($items as $index => $item) {
            if ($index === count($items) - 1) {
                $amount = $refundedAmount - $distributed;
            } else {
                $amount = $refundedAmount * ($item['remain'] / $totalRemain);
                $distributed += $amount;
            }
            $itemsWithProportionalAmount[] = [
                'id' => $item['model']->id,
                'amount' => $amount
            ];
        }

        return $itemsWithProportionalAmount;
    }

    /**
     * Create or get Paddle customer
     */
    public static function createOrGetPaddleCustomer(Customer $customer, $mode = '')
    {
        // Check if customer already has a Paddle customer ID
        $paddleCustomerId = $customer->getMeta('paddle_customer_id_' . $mode);
        
        if ($paddleCustomerId) {
            // Verify customer still exists in Paddle
            $paddleCustomer = API::getPaddleObject("customers/{$paddleCustomerId}", [], $mode);
            if (!is_wp_error($paddleCustomer)) {
                return $paddleCustomer;
            }
        }

        // Create new customer in Paddle
        $customerData = [
            'name' => $customer->full_name,
            'email' => $customer->email,
            'custom_data' => [
                'fct_cart_customer_hash' => $customer->uuid
            ]
        ];

        $paddleCustomer = API::createPaddleObject('customers', $customerData, $mode);

        if (is_wp_error($paddleCustomer)) {
            $errorData = $paddleCustomer->get_error_data();
            $errorCode = Arr::get($errorData, 'response_body.error.code', '');

            if ($errorCode === 'customer_already_exists') {
                $detail = Arr::get($errorData, 'response_body.error.detail', '');

                preg_match('/ctm_[a-z0-9]+/', $detail, $matches);
                $paddleCustomerId = !empty($matches) ? $matches[0] : '';

                $customer->updateMeta('paddle_customer_id_' . $mode, $paddleCustomerId);

                return API::getPaddleObject("customers/{$paddleCustomerId}", [], $mode);

            } else {
                return $paddleCustomer;
            }
        }

        $customer->updateMeta('paddle_customer_id_' . $mode, Arr::get($paddleCustomer, 'data.id'));

        return $paddleCustomer;
    }

    public static function getTransactionMeta($paddleTransaction, $order)
    {
        $orderItemIds = $order->order_items->pluck('id')->toArray();
        $paddleLineItems = Arr::get($paddleTransaction, 'details.line_items', []);

        // filter out items from paddleItemIds if total is <= 0
        $paddleLineItems = array_filter($paddleLineItems, function ($item) {
            return Arr::get($item, 'totals.total') > 0;
        });

        $paddleItemIds = array_column($paddleLineItems, 'id');

        // for refund purpose
        $mappedPaddleItemIds = [];
        foreach ($orderItemIds as $index => $orderItemId) {
            if (isset($paddleItemIds[$index])) {
                $mappedPaddleItemIds[$orderItemId] = $paddleItemIds[$index];
            } else {
                break; // Stop if paddleItemIds is shorter (not applicable here)
            }
        }

        $details = Arr::get($paddleTransaction, 'details');

        return [
            'mapped_paddle_item_ids'   => $mappedPaddleItemIds,
            'paddle_totals'     => Arr::get($details, 'totals'),
            'tax_mode'          => Arr::get($details, 'tax_mode')
        ];
    }

    /**
     * Transform Paddle subscription status to FluentCart status
     */
    public static function transformSubscriptionStatus($paddleStatus)
    {
        $statusMap = [
            'active' => Status::SUBSCRIPTION_ACTIVE,
            'trialing' => Status::SUBSCRIPTION_TRIALING,
            'past_due' => Status::SUBSCRIPTION_PAST_DUE,
            'paused' => Status::SUBSCRIPTION_PAUSED,
            'canceled' => Status::SUBSCRIPTION_CANCELED,
        ];

        return Arr::get($statusMap, strtolower($paddleStatus), Status::SUBSCRIPTION_PENDING);
    }

    /**
     * Transform Paddle transaction status to FluentCart status
     */
    public static function transformTransactionStatus($paddleStatus)
    {
        $statusMap = [
            'completed' => Status::TRANSACTION_SUCCEEDED,
            'paid' => Status::TRANSACTION_SUCCEEDED,
            'billed' => Status::TRANSACTION_SUCCEEDED,
            'ready' => Status::TRANSACTION_PENDING,
            'draft' => Status::TRANSACTION_PENDING,
        ];

        return Arr::get($statusMap, strtolower($paddleStatus), Status::TRANSACTION_PENDING);
    }

    /**
     * Format order items for Paddle transaction
     * a reference
     */
    public static function formatOrderItemsForPaddle($orderItems, Order $order)
    {
        $items = [];
        
        foreach ($orderItems as $item) {
            $unitPrice = Helper::toDecimal($item['unit_price'], false, null, true, true, false);
            
            $items[] = [
                'price_id' => null, // Will be set when creating price
                'quantity' => $item['quantity'],
                'price' => [
                    'description' => $item['item_name'],
                    'product_id' => null, // Will be set when creating product
                    'unit_price' => [
                        'amount' => $unitPrice,
                        'currency_code' => strtoupper($order->currency)
                    ],
                    'tax_mode' => 'account_setting'
                ]
            ];
        }

        return $items;
    }

    /**
     * Get subscription update data from Paddle subscription
     */
    public static function getSubscriptionUpdateData($paddleSubscription, $localSubscription)
    {
        $status = self::transformSubscriptionStatus(Arr::get($paddleSubscription, 'data.status'));
        
        $updateData = [
            'status' => $status,
            'vendor_response' => json_encode($paddleSubscription)
        ];

        // Update next billing date if available
        $nextBillingAt = Arr::get($paddleSubscription, 'data.next_billed_at');
        if ($nextBillingAt) {
            $updateData['next_billing_date'] = gmdate('Y-m-d H:i:s', strtotime($nextBillingAt));
        }

        if ($status == Status::SUBSCRIPTION_CANCELED) {
            $updateData['next_billing_date'] = null;
        }

        $trialEndsAt = Arr::get($paddleSubscription, 'data.trial_dates.ends_at');
        if ($trialEndsAt) {
            $updateData['trial_ends_at'] = gmdate('Y-m-d H:i:s', strtotime($trialEndsAt));
        }

        if ($status === Status::SUBSCRIPTION_CANCELED) {
            $canceledAt = Arr::get($paddleSubscription, 'data.canceled_at');
            if ($canceledAt) {
                $updateData['canceled_at'] = gmdate('Y-m-d H:i:s', strtotime($canceledAt));
            }
        }

        return $updateData;
    }

    /**
     * Generate checkout URL for Paddle
     */
    public static function generateCheckoutUrl($transactionData, $mode = '')
    {
        $settings = new PaddleSettings();
        $checkoutUrl = $settings->getCheckoutUrl($mode);
        
        $queryParams = [
            'items' => json_encode($transactionData['items']),
            'customer_email' => $transactionData['customer_email'],
            'custom_data' => json_encode($transactionData['custom_data'] ?? [])
        ];

        return add_query_arg($queryParams, $checkoutUrl);
    }

    /**
     * Get order from Paddle webhook data
     */
    public static function getOrderFromWebhookData($webhookData)
    {
        $customData = Arr::get($webhookData, 'data.custom_data', []);

        if (!$customData) {
            // Let's try to get the order from transaction
            $vendorTransactionId = Arr::get($webhookData, 'data.transaction_id');
            if ($vendorTransactionId) {
                $transaction = OrderTransaction::query()->where('vendor_charge_id', $vendorTransactionId)->first();
                if ($transaction) {
                    return $transaction->order;
                }
            }
        }

        $orderHash = Arr::get($customData, 'fct_order_hash');
        
        if (!$orderHash) {
            return null;
        }

        return Order::query()->where('uuid', $orderHash)->first();
    }

    /**
     * Get billing cycle configuration from subscription
     */
    public static function getBillingCycleFromSubscription($subscription)
    {
        $interval = $subscription->billing_interval;
        $intervalCount = $subscription->billing_interval_count ?: 1;

        $paddleInterval = [
            'day' => 'day',
            'week' => 'week',
            'month' => 'month',
            'year' => 'year'
        ];

        return [
            'interval' => Arr::get($paddleInterval, $interval, 'month'),
            'frequency' => $intervalCount
        ];
    }

    public static function createOrUpdateIpnRefund($refundData, $parentTransaction)
    {
        $allRefunds = OrderTransaction::query()
            ->where('order_id', $refundData['order_id'])
            ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
            ->orderBy('id', 'DESC')
            ->get();

        if ($allRefunds->isEmpty()) {
            // this is the first refund for this order
            return OrderTransaction::query()->create($refundData);
        }

        $currentRefundTransactionId = Arr::get($refundData, 'meta.parent_id', '');

        $existingLocalRefund = null;
        foreach ($allRefunds as $refund) {
            if ($refund->vendor_charge_id == $refundData['vendor_charge_id']) {
                if ($refund->total != $refundData['total']) {
                    $refund->fill($refundData);
                    $refund->save();
                }
                // this refund already exists
                return $refund;
            }

            if (!$refund->vendor_charge_id) { // this is a local refund without vendor charge id
                $refundTransactionId = Arr::get($refund->meta, 'parent_id', '');
                $isTransactionMatched = $refundTransactionId == $currentRefundTransactionId;

                // this is a local refund without vendor charge id, we will update it
                if ($refund->total == $refundData['total'] && $isTransactionMatched) {
                    // this refund already exists
                    $existingLocalRefund = $refund;
                }
            }
        }

        if ($existingLocalRefund) {
            $existingLocalRefund->fill($refundData);
            $existingLocalRefund->save();
            return $existingLocalRefund;
        }

        $createdRefund = OrderTransaction::query()->create($refundData);

        PaymentHelper::updateTransactionRefundedTotal($parentTransaction, $createdRefund->total);
        return $createdRefund;
    }

    public static function adjustExtraAmount($extraAmount, $transactionModel, $lineItems = [], $recurringPriceId = null)
    {
        $transactionModel->order->tax_total += $extraAmount;
        $transactionModel->order->total_amount += $extraAmount;
        $transactionModel->order->save();

        $subscription = Subscription::query()->where('parent_order_id', $transactionModel->order->id)->first();
        if ($subscription) {
            $recurringTax = $subscription->recurring_tax_total;
            $recurringTotal = $subscription->recurring_total;
            if ($recurringPriceId === null) {
                $recurringItem = $lineItems[0];
            } else {
                $recurringItem = array_filter($lineItems, function ($item) use ($recurringPriceId) {
                    return Arr::get($item, 'price_id') == $recurringPriceId;
                });
                $recurringItem = reset($recurringItem);
            }

            if ($recurringItem && Arr::get($recurringItem, 'unit_totals')) {
                $recurringTotal = (int) Arr::get($recurringItem, 'unit_totals.total', 0);
                $recurringTax = (int) Arr::get($recurringItem, 'unit_totals.tax', 0);
            }

            $subscription->recurring_tax_total = $recurringTax;
            $subscription->recurring_total = $recurringTotal;
            $subscription->save();
        }
    }


    public static function formatAmount($amount)
    {
        return (string) (int) $amount;
    }

    /**
     * Parse amount from Paddle (convert to cents)
     */
    public static function parseAmount($amount)
    {
        return round(floatval($amount) * 100, 2);
    }
}
