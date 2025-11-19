<?php

namespace FluentCart\App\Modules\PaymentMethods\PayPalGateway;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractSubscriptionModule;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\API\API;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;

class PayPalSubscriptions extends AbstractSubscriptionModule
{
    public function reSyncSubscriptionFromRemote(Subscription $subscriptionModel)
    {
        $order = $subscriptionModel->order;

        $paypalSubscription = (new API())->verifySubscription($subscriptionModel->vendor_subscription_id, $order->mode);

        if (is_wp_error($paypalSubscription)) {
            return $paypalSubscription;
        }

        $newPayment = false;

        $subscriptionStatus = (new SubscriptionManager)->getCorrectSubscriptionStatus(Arr::get($paypalSubscription, 'status'));
        $nextBillingDate = Arr::get($paypalSubscription, 'billing_info.next_billing_time') ?? null;

        $payer = Arr::get($paypalSubscription, 'subscriber', []);

        if ($nextBillingDate) {
            $nextBillingDate = gmdate('Y-m-d H:i:s', strtotime($nextBillingDate));
        }

        $subscriptionUpdateData = array_filter([
            'current_payment_method' => 'paypal',
            'status'                 => $subscriptionStatus,
            'next_billing_date'      => $nextBillingDate
        ]);

        if (Arr::get($paypalSubscription, 'status') === 'CANCELLED') {
            $statusUpdateTime = Arr::get($paypalSubscription, 'status_update_time');
            if ($statusUpdateTime) {
                $subscriptionUpdateData['canceled_at'] = gmdate('Y-m-d H:i:s', strtotime($statusUpdateTime));
            }
        }

        $startTime = Arr::get($paypalSubscription, 'start_time');
        $endTime = DateTime::gmtNow()->format('Y-m-d\TH:i:s.v\Z');

        $response = (new API())->getResource('billing/subscriptions/' . $subscriptionModel->vendor_subscription_id . '/transactions', [
            'start_time' => $startTime,
            'end_time'   => $endTime
        ], $order->mode);

        if (is_wp_error($response)) {
            return $response;
        }

        // reverse the array to get the latest transaction last
        $paypalTransactions = array_reverse(Arr::get($response, 'transactions', []));


        if (!empty($paypalTransactions)) {
            foreach ($paypalTransactions as $paypalTransaction) {

                $amount = Helper::toCent(Arr::get($paypalTransaction, 'amount_with_breakdown.gross_amount.value', 0));
                $chargeId = Arr::get($paypalTransaction, 'id');

                $status = strtolower(Arr::get($paypalTransaction, 'status'));

                if ($status == 'completed') {
                    $transaction = OrderTransaction::query()
                        ->select(['id', 'order_id'])
                        ->where('vendor_charge_id', $chargeId)
                        ->first();

                    if (!$transaction) {
                        // check if any transaction related to this subscription exists without vendor_charge_id, mainly for first cycle payment
                        $transaction = OrderTransaction::query()
                            ->select(['id', 'order_id'])
                            ->where('subscription_id', $subscriptionModel->id)
                            ->where('vendor_charge_id', '')
                            ->where('total', $amount)
                            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
                            ->first();

                        if ($transaction) {
                            $transaction->update([
                                'vendor_charge_id' => $chargeId,
                                'status'           => Status::TRANSACTION_SUCCEEDED,
                                'payment_method_type' => 'PayPal',
                                'meta'             => array_merge($transaction->meta, [
                                    'payer' => $payer
                                ])
                            ]);
                            continue;
                        }

                        $transactionData = [
                            'subscription_id'     => $subscriptionModel->id,
                            'payment_method'      => 'paypal',
                            'vendor_charge_id'    => $chargeId,
                            'payment_method_type' => 'PayPal',
                            'meta'                => [
                                'payer' => $payer
                            ],
                            'created_at'          => DateTime::anyTimeToGmt(Arr::get($paypalTransaction, 'time'))->format('Y-m-d H:i:s'),
                        ];

                        $newPayment = true;
                        SubscriptionService::recordRenewalPayment($transactionData, $subscriptionModel, $subscriptionUpdateData);
                    } else if ($transaction->status !== Status::TRANSACTION_SUCCEEDED) {
                        $transaction->update([
                            'vendor_charge_id' => $chargeId,
                            'status'           => Status::TRANSACTION_SUCCEEDED,
                            'meta'             => array_merge($transaction->meta, [
                                'payer' => $payer
                            ])
                        ]);
                    }
                }
            }
        }

        if (!$newPayment) {
            $subscriptionModel = SubscriptionService::syncSubscriptionStates($subscriptionModel, $subscriptionUpdateData);
        } else {
            $subscriptionModel = Subscription::query()->find($subscriptionModel->id);
        }

        return $subscriptionModel;
    }

    public function cancel($vendorSubscriptionId, $args = [])
    {
        if (!$vendorSubscriptionId) {
            return new \WP_Error('invalid_subscription', __('Invalid vendor subscription ID.', 'fluent-cart'));
        }

        $response = API::createResource('billing/subscriptions/' . $vendorSubscriptionId . '/cancel', [
            'reason' => Arr::get($args, 'reason', __('Subscription canceled.', 'fluent-cart')),
        ], Arr::get($args, 'mode', ''));

        if (is_wp_error($response)) {
            return $response;
        }

        return [
            'status' => Status::SUBSCRIPTION_CANCELED
        ];
    }

    public function getOrCreateNewPlan($subscriptionId, $reason)
    {
        (new SubscriptionManager())->getOrCreateNewPlan($subscriptionId, $reason);
    }

    public function confirmSubscriptionSwitch($data, $subscriptionId)
    {
        (new SubscriptionManager())->confirmSubscriptionSwitch($data, $subscriptionId);
    }

}
