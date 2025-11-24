<?php

namespace FluentCartPro\App\Modules\PaymentMethods\PaddleGateway;

use FluentCart\App\Helpers\StatusHelper;
use FluentCartPro\App\Modules\PaymentMethods\PaddleGateway\API\API;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractSubscriptionModule;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;

class PaddleSubscriptions extends AbstractSubscriptionModule
{
    /**
     * Re-sync subscription from Paddle remote
     */
    public function reSyncSubscriptionFromRemote(Subscription $subscriptionModel)
    {
        if ($subscriptionModel->current_payment_method !== 'paddle') {
            return new \WP_Error(
                'invalid_payment_method',
                __('This subscription is not using Paddle as payment method.', 'fluent-cart-pro')
            );
        }

        $order = $subscriptionModel->order;
        $vendorSubscriptionId = $subscriptionModel->vendor_subscription_id;

        if (!$vendorSubscriptionId) {
            return new \WP_Error(
                'invalid_subscription',
                __('Invalid vendor subscription ID.', 'fluent-cart-pro')
            );
        }

        // Get subscription from Paddle
        $paddleSubscription = API::getPaddleObject("subscriptions/{$vendorSubscriptionId}", [], $order->mode);

        if (is_wp_error($paddleSubscription)) {
            return $paddleSubscription;
        }

        $queryParams = [
            'subscription_id' => $vendorSubscriptionId,
            'status' => 'completed',
        ];

        $paddleTransactions = [];
        $hasMore = false;
        do {
            $transactions = API::getPaddleObject('transactions', $queryParams, $order->mode);

            if (is_wp_error($transactions)) {
                break;
            }
           foreach (Arr::get($transactions, 'data', []) as $transaction) {
               $paddleTransactions[] = $transaction;
           }

            $hasMore = Arr::get($transactions, 'meta.pagination.has_more');
            $next = Arr::get($transactions, 'meta.pagination.next');
            $queryString = parse_url($next, PHP_URL_QUERY);
            parse_str($queryString, $params);
            $after = $params['after'];
            $queryParams['after'] = $after;

        } while ($hasMore);


        $subscriptionUpdateData = PaddleHelper::getSubscriptionUpdateData($paddleSubscription, $subscriptionModel);

        $newPayment = false;

        // Process transactions and create renewal orders if needed
        if (!empty($paddleTransactions)) {
            // reverse the array to get the latest transaction last
            $paddleTransactions = array_reverse($paddleTransactions);
            foreach ($paddleTransactions as $paddleTransaction) {
                $transactionStatus = Arr::get($paddleTransaction, 'status');
                $meta = PaddleHelper::getTransactionMeta($paddleTransaction, $order);
                $details = Arr::get($paddleTransaction, 'details');
                
                if ($transactionStatus === 'completed') {
                    $vendorChargeId = Arr::get($paddleTransaction, 'id');

                    $payment = Arr::get($paddleTransaction, 'payments.0');
                    $methodType  = Arr::get($payment, 'method_details.type');
                    $cardLast4 = null;
                    $cardBrand = null;
                    if ($methodType == 'card') {
                        $cardLast4 = Arr::get($payment, 'method_details.card.last4');
                        $cardBrand = Arr::get($payment, 'method_details.card.brand');
                    }

                    $amount = Arr::get($details, 'totals.total', 0);

                    $transaction = OrderTransaction::query()->where('vendor_charge_id', $vendorChargeId)->first();
                    if (!$transaction) {
                        $transaction = OrderTransaction::query()
                            ->where('subscription_id', $subscriptionModel->id)
                            ->where('vendor_charge_id', '')
                            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
                            ->first();


                        if ($transaction) {
                            if ($amount > $transaction->order->total_amount) {
                                $recurringPriceId = null;
                                foreach (Arr::get($paddleTransaction, 'items', []) as $item) {
                                    if (Arr::get($item, 'price.billing_cycle')) {
                                        $recurringPriceId = Arr::get($item, 'price.id');
                                        break;
                                    }
                                }
                                $lineItems = Arr::get($details, 'line_items', []);
                                PaddleHelper::adjustExtraAmount($amount - $transaction->total, $transaction, $lineItems, $recurringPriceId);
                            }

                            $transaction->update([
                                'vendor_charge_id' => $vendorChargeId,
                                'status'           => Status::TRANSACTION_SUCCEEDED,
                                'total'            => $amount,
                                'meta'             => array_merge($transaction->meta ?? [], $meta),
                                'card_last_4'    => $cardLast4,
                                'card_brand'       => $cardBrand,
                                'payment_method_type' => $methodType
                            ]);

                            (new StatusHelper($transaction->order))->syncOrderStatuses($transaction);

                            continue;
                        }

                        $billedAt = Arr::get($paddleTransaction, 'billed_at', null);

                        $transactionData = [
                            'subscription_id'     => $subscriptionModel->id,
                            'payment_method'      => 'paddle',
                            'vendor_charge_id'    => $vendorChargeId,
                            'total'               => $amount,
                            'tax_total'           => Arr::get($details, 'totals.tax', 0),
                            'payment_method_type' => $methodType,
                            'card_last_4'         => $cardLast4,
                            'card_brand'          => $cardBrand,
                            'meta'                => $meta,
                            'created_at'          => $billedAt ? DateTime::anyTimeToGmt($billedAt)->format('Y-m-d H:i:s') : DateTime::now()->format('Y-m-d H:i:s')
                        ];

                        $newPayment = true;
                        SubscriptionService::recordRenewalPayment($transactionData, $subscriptionModel, $subscriptionUpdateData);
                    } else if ($transaction->status !== Status::TRANSACTION_SUCCEEDED) {
                        $transaction->update([
                            'vendor_charge_id' => $vendorChargeId,
                            'status'           => Status::TRANSACTION_SUCCEEDED,
                            'meta'             => [
                                'paddle_totals' => Arr::get($details, 'totals', [])
                            ]
                        ]);

                        (new StatusHelper($transaction->order))->syncOrderStatuses($transaction);
                    }
                }
            }
        }

        // Update subscription data
        if (!$newPayment) {
            $subscriptionModel = SubscriptionService::syncSubscriptionStates($subscriptionModel, $subscriptionUpdateData);
        } else {
            $subscriptionModel = Subscription::query()->find($subscriptionModel->id);
        }

        return $subscriptionModel;
    }

    /**
     * Cancel subscription
     */
    public function cancel($vendorSubscriptionId, $args = [])
    {
        if (!$vendorSubscriptionId) {
            return new \WP_Error(
                'invalid_subscription',
                __('Invalid vendor subscription ID.', 'fluent-cart-pro')
            );
        }

        $cancelData = [
            'effective_from' => Arr::get($args, 'effective_from', 'immediately')
        ];

        $response = API::actionPaddleObject("subscriptions/{$vendorSubscriptionId}/cancel", $cancelData, Arr::get($args, 'mode', 'live'));

        if (is_wp_error($response)) {
            return $response;
        }

        $canceledAt = Arr::get($response, 'data.canceled_at');

        return [
            'status' => Status::SUBSCRIPTION_CANCELED,
            'canceled_at' => $canceledAt ? gmdate('Y-m-d H:i:s', strtotime($canceledAt)) : null
        ];
    }

    /**
     * Pause subscription
     */
    public function pauseSubscription($data, $order, $subscription)
    {
        $vendorSubscriptionId = $subscription->vendor_subscription_id;
        
        if (!$vendorSubscriptionId) {
            throw new \Exception(__('Invalid vendor subscription ID.', 'fluent-cart-pro'), 404);
        }

        $pauseData = [
            'effective_from' => Arr::get($data, 'effective_from', 'next_billing_period')
        ];

        $response = API::actionPaddleObject("subscriptions/{$vendorSubscriptionId}/pause", $pauseData, $order->mode);

        if (is_wp_error($response)) {
            throw new \Exception($response->get_error_message(), 422);
        }

        return [
            'status' => 'success',
            'message' => __('Subscription paused successfully', 'fluent-cart-pro'),
            'paddle_response' => $response
        ];
    }

    /**
     * Resume subscription
     */
    public function resumeSubscription($data, $order, $subscription)
    {
        $vendorSubscriptionId = $subscription->vendor_subscription_id;
        
        if (!$vendorSubscriptionId) {
            throw new \Exception(__('Invalid vendor subscription ID.', 'fluent-cart-pro'), 404);
        }

        $resumeData = [
            'effective_from' => Arr::get($data, 'effective_from', 'immediately')
        ];

        $response = API::actionPaddleObject("subscriptions/{$vendorSubscriptionId}/resume", $resumeData, $order->mode);

        if (is_wp_error($response)) {
            throw new \Exception($response->get_error_message(), 422);
        }

        return [
            'status' => 'success',
            'message' => __('Subscription resumed successfully', 'fluent-cart-pro'),
            'paddle_response' => $response
        ];
    }

    /**
     * Update subscription
     */
    public function updateSubscription($subscriptionId, $updateData, $mode = '')
    {
        $response = API::updatePaddleObject("subscriptions/{$subscriptionId}", $updateData, $mode);

        if (is_wp_error($response)) {
            return $response;
        }

        return [
            'status' => 'success',
            'message' => __('Subscription updated successfully', 'fluent-cart-pro'),
            'paddle_response' => $response
        ];
    }

    /**
     * Switch payment method for subscription
     */
    public function switchPaymentMethod($data, $subscriptionId)
    {
       new \WP_Error(
            'not_supported',
            __('Payment method switching is not directly supported by Paddle. Please cancel and recreate the subscription.', 'fluent-cart-pro')
        );
    }

    /**
     * Reactivate subscription
     */
    public function reactivateSubscription($data, $subscriptionId)
    {
        // For Paddle, reactivation is typically done through resuming a paused subscription
        // or creating a new subscription if the old one was canceled
       new \WP_Error(
            'not_supported',
            __('Subscription reactivation is not directly supported by Paddle. Please create a new subscription.', 'fluent-cart-pro')
        );
    }
}
