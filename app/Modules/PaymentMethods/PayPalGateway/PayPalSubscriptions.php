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
    /**
     * Read-only lookup used by the admin "Edit Vendor IDs" verify action.
     *
     * PayPal's status vocabulary is its own (ACTIVE / SUSPENDED / CANCELLED), so it is
     * mapped through SubscriptionManager the same way the resync path does.
     */
    public function verifyVendorSubscription(array $args, $mode = 'current')
    {
        $vendorSubscriptionId = Arr::get($args, 'vendor_subscription_id');

        if (!$vendorSubscriptionId) {
            return new \WP_Error('invalid_subscription', __('A Vendor Subscription ID is required to look up a PayPal subscription.', 'fluent-cart'));
        }

        $subscription = (new API())->verifySubscription($vendorSubscriptionId, $mode);

        if (is_wp_error($subscription)) {
            return $subscription;
        }

        $nextBilling = Arr::get($subscription, 'billing_info.next_billing_time');

        return [
            'id'                => Arr::get($subscription, 'id'),
            'status'            => (new SubscriptionManager)->getCorrectSubscriptionStatus(Arr::get($subscription, 'status')),
            'customer_id'       => Arr::get($subscription, 'subscriber.payer_id'),
            'amount'            => Arr::get($subscription, 'billing_info.last_payment.amount.value', ''),
            'currency'          => strtoupper((string) Arr::get($subscription, 'billing_info.last_payment.amount.currency_code')),
            'next_billing_date' => $nextBilling ? gmdate('Y-m-d H:i:s', strtotime($nextBilling)) : '',
        ];
    }

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
            'status'                 => $subscriptionStatus
        ]);

        if ($nextBillingDate) {
            $subscriptionUpdateData['next_billing_date'] = $nextBillingDate;
        }

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
                    // PayPal reports when the remote charge completed; that is the
                    // settlement moment, not this resync's run time.
                    $settledAt = DateTime::anyTimeToGmt(Arr::get($paypalTransaction, 'time'))->format('Y-m-d H:i:s');

                    // status drives the branch below and meta is merged on update,
                    // so both must be selected — omitting them would read null and
                    // wipe unrelated meta keys.
                    $transaction = OrderTransaction::query()
                        ->select(['id', 'order_id', 'status', 'meta'])
                        ->where('vendor_charge_id', $chargeId)
                        ->first();

                    if (!$transaction) {
                        // check if any transaction related to this subscription exists without vendor_charge_id, mainly for first cycle payment
                        $transaction = OrderTransaction::query()
                            ->select(['id', 'order_id', 'status', 'meta'])
                            ->where('subscription_id', $subscriptionModel->id)
                            ->where('vendor_charge_id', '')
                            ->where('total', $amount)
                            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
                            ->first();

                        if ($transaction) {
                            $meta = array_merge($transaction->meta, ['payer' => $payer]);
                            if (empty($meta['settled_at'])) {
                                $meta['settled_at'] = $settledAt;
                            }
                            $transaction->update([
                                'vendor_charge_id' => $chargeId,
                                'status'           => Status::TRANSACTION_SUCCEEDED,
                                'payment_method_type' => 'PayPal',
                                'meta'             => $meta
                            ]);
                            continue;
                        }

                        $transactionData = [
                            'subscription_id'     => $subscriptionModel->id,
                            'payment_method'      => 'paypal',
                            'vendor_charge_id'    => $chargeId,
                            'payment_method_type' => 'PayPal',
                            'total'               => $amount,
                            'meta'                => [
                                'payer'      => $payer,
                                'settled_at' => $settledAt
                            ],
                            'created_at'          => DateTime::anyTimeToGmt(Arr::get($paypalTransaction, 'time'))->format('Y-m-d H:i:s'),
                        ];

                        $newPayment = true;
                        SubscriptionService::recordRenewalPayment($transactionData, $subscriptionModel, $subscriptionUpdateData);
                    } else if ($transaction->status !== Status::TRANSACTION_SUCCEEDED) {
                        $meta = array_merge($transaction->meta, ['payer' => $payer]);
                        if (empty($meta['settled_at'])) {
                            $meta['settled_at'] = $settledAt;
                        }
                        $transaction->update([
                            'vendor_charge_id' => $chargeId,
                            'status'           => Status::TRANSACTION_SUCCEEDED,
                            'meta'             => $meta
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

        // first check , before canceling the subscription
        $paypalSubscription = (new API())->verifySubscription($vendorSubscriptionId, Arr::get($args, 'mode', ''));

        if (is_wp_error($paypalSubscription)) {
            return $paypalSubscription;
        }

        $subscriptionStatus = (new SubscriptionManager)->getCorrectSubscriptionStatus(Arr::get($paypalSubscription, 'status'));

        // CANCELLED and EXPIRED are terminal at PayPal — the cancel API rejects them with SUBSCRIPTION_STATUS_INVALID
        if (in_array($subscriptionStatus, [Status::SUBSCRIPTION_CANCELED, Status::SUBSCRIPTION_EXPIRED])) {
            $result = [
                'status' => $subscriptionStatus
            ];

            if ($subscriptionStatus === Status::SUBSCRIPTION_CANCELED) {
                $statusUpdateTime = Arr::get($paypalSubscription, 'status_update_time');
                $result['canceled_at'] = $statusUpdateTime ? gmdate('Y-m-d H:i:s', strtotime($statusUpdateTime)) : NULL;
            }

            return $result;
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

    /**
     * Resume (activate) a suspended PayPal subscription.
     *
     * Called by SubscriptionService::resumeSubscription for automatic subscriptions.
     * Remote first: activates the subscription at PayPal, and only on success flips
     * the local status and fires the SubscriptionResumed event.
     *
     * @param Subscription $subscription
     * @param string $reason
     * @return true|\WP_Error
     */
    public function resume(Subscription $subscription, $reason = '')
    {
        $vendorSubscriptionId = $subscription->vendor_subscription_id;

        if (!$vendorSubscriptionId) {
            return new \WP_Error('invalid_subscription', __('Invalid vendor subscription ID.', 'fluent-cart'));
        }

        $order = $subscription->order;

        $response = API::createResource('billing/subscriptions/' . $vendorSubscriptionId . '/activate', [
            'reason' => $reason ?: __('Subscription resumed.', 'fluent-cart'),
        ], $order ? $order->mode : '');

        if (is_wp_error($response)) {
            return $response;
        }

        $oldStatus = $subscription->status;
        $subscription->status = Status::SUBSCRIPTION_ACTIVE;
        $subscription->save();

        $subscription->addLog(
            'Subscription resumed',
            $reason ?: __('Subscription resumed via PayPal', 'fluent-cart'),
            'info'
        );

        SubscriptionService::dispatchStatusEvent($subscription, 'resumed', [
            'old_status' => $oldStatus,
            'reason'     => $reason,
        ]);

        return true;
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
