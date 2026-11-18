<?php

namespace FluentCart\App\Modules\PaymentMethods\PayPalGateway;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Models\SubscriptionMeta;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\API\API;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Services\Payments\SubscriptionHelper;
use FluentCart\Framework\Support\Arr;

class SubscriptionManager
{

    /**
     * @throws \Exception
     */
    public function pauseSubscription($data, $order, $subscription)
    {
        if (!current_user_can('manage_options')) {
            throw new \Exception(esc_html__('Sorry, You do not have permission to pause subscription!', 'fluent-cart'));
        }

        $vendorSubscriptionId = Arr::get($data, 'vendor_subscription_id');
        $reason = Arr::get($data, 'reason');

        if (!$vendorSubscriptionId || !$subscription) {
            throw new \Exception(esc_html__('Sorry, Subscription not found!', 'fluent-cart'));
        }

        try {
            (new API())->makeRequest('billing/subscriptions/' . $vendorSubscriptionId . '/suspend', 'v1', 'POST', [
                'reason' => $reason
            ]);
        } catch (\Exception $e) {
            throw new \Exception(esc_html($e->getMessage()));
        }

        $subscription = Subscription::query()->where('id', $subscription->id)->first();
        if ($subscription) {
            $subscription->status = Status::SUBSCRIPTION_PAUSED;
            $subscription->save();
        }

        wp_send_json(array(
            'message' => __('Subscription has been paused successfully', 'fluent-cart')
        ), 200);
    }

    /**
     * @throws \Exception
     */
    public function resumeSubscription($data, $order, $subscription)
    {
        if (!current_user_can('manage_options')) {
            throw new \Exception(esc_html__('Sorry, You do not have permission to resume subscription!', 'fluent-cart'));
        }

        $vendorSubscriptionId = Arr::get($data, 'vendor_subscription_id');
        $reason = Arr::get($data, 'reason');

        if (!$vendorSubscriptionId || !$subscription) {
            throw new \Exception(esc_html__('Sorry, Subscription not found!', 'fluent-cart'));
        }

        $response = (new API())->makeRequest('billing/subscriptions/' . $vendorSubscriptionId . '/activate', 'v1', 'POST', [
            'reason' => $reason ?? 'Customer requested'
        ]);

        if (is_wp_error($response)) {
            throw new \Exception(esc_html($response->get_error_message()));
        }

        $subscription = Subscription::query()->where('id', $subscription->id)->first();
        if ($subscription) {
            $subscription->status = Status::SUBSCRIPTION_ACTIVE;
            $subscription->save();
        }

        wp_send_json(array(
            'message' => __('Subscription has been resumed successfully', 'fluent-cart')
        ), 200);

    }

    /**
     * @throws \Exception
     */
    public function getOrCreateNewPlan($subscriptionId, $reason)
    {
        if (!$subscriptionId) {
            wp_send_json([
                'message' => __('Sorry, Subscription ID is not available!', 'fluent-cart'),
            ], 423);
        }

        $subscriptionModel = Subscription::query()->where('id', $subscriptionId)->first();
        $order = Order::query()->where('id', $subscriptionModel->parent_order_id)->with('order_items')->first();
        if (!$subscriptionModel || !$order) {
            wp_send_json([
                'message' => __('Sorry, Subscription or Order not found!', 'fluent-cart'),
            ], 423);
        }

        // get the variation from variation id
        $variation = ProductVariation::query()->findOrFail($subscriptionModel->variation_id);

        $processedSubscriptionItem = $this->getSubscriptionItemForUpdate($subscriptionModel, $variation);

        $data = wp_parse_args($processedSubscriptionItem, [
            'order_id'       => $subscriptionModel->parent_order_id,
            'product_id'     => $subscriptionModel->product_id,
            'variation_id'   => $subscriptionModel->variation_id,
            'currency'       => $subscriptionModel->order->currency,
            'interval_count' => 1,
            'signup_fee'     => 0, // default setup fee in cents ($0.00)
        ]);

        $plan = PayPalHelper::getPayPalPlan($data);

        if (is_wp_error($plan)) {
            wp_send_json([
                'status'  => 'error',
                'message' => $plan->get_error_message(),
            ], 422);
        }

        // The confirm step accepts only a PayPal subscription on this plan.
        $subscriptionModel->updateMeta('paypal_switch_plan_id', Arr::get($plan, 'id'));

        wp_send_json([
            'status'  => 'success',
            'message' => __('Plan created successfully', 'fluent-cart'),
            'plan'    => $plan,
            'brand_name' => (new PayPalSettingsBase())->getBrandName([
                'order'        => $order,
                'subscription' => $subscriptionModel,
            ]),
        ], 200);
    }

    public function confirmSubscriptionSwitch($data, $subscriptionId)
    {
        $newVendorSubscriptionId = Arr::get($data, 'newVendorSubscriptionId');
        $vendorOrderId = Arr::get($data, 'vendorOrderId');

        $subscription = Subscription::query()->where('id', $subscriptionId)->first();

        if (!$newVendorSubscriptionId || !$vendorOrderId) {
            wp_send_json([
                'status'  => 'error',
                'message' => __('Sorry, New Subscription ID or Vendor Order ID is not available!', 'fluent-cart'),
            ], 422);
        }

        if (!$subscription->lockPaymentMethodSwitch()) {
            $this->sendError(__('A payment method switch is already in progress.', 'fluent-cart'), 409);
        }

        try {
            $this->confirmSwitchLocked($subscription, $newVendorSubscriptionId);
        } finally {
            $subscription->unlockPaymentMethodSwitch();
        }
    }

    private function confirmSwitchLocked(Subscription $subscription, $newVendorSubscriptionId)
    {
        $subscription = Subscription::query()->where('id', $subscription->id)->first();

        if ($subscription->vendor_subscription_id === $newVendorSubscriptionId) {
            $this->sendSwitchSuccess($newVendorSubscriptionId);
        }

        // get the subscription from paypal
        $paypalSubscription = (new API())->verifySubscription($newVendorSubscriptionId);

        if (is_wp_error($paypalSubscription)) {
            // log that subscription was created but not found in paypal or connecting issue
            fluent_cart_error_log(
                __('Subscription was created but not found in paypal or connecting issue', 'fluent-cart'),
                $paypalSubscription->get_error_message(),
                [
                    'module_name' => 'Subscription',
                    'module_id'   => $subscription->id,
                    'log_type'    => 'api'
                ]
            );
            wp_send_json([
                'status'  => 'error',
                'message' => $paypalSubscription->get_error_message(),
            ], 422);
        }

        $result = $this->finishSwitch($subscription, $paypalSubscription);
        if (is_wp_error($result)) {
            $this->sendError($result->get_error_message());
        }

        $this->sendSwitchSuccess($result);
    }

    /**
     * Binds the new PayPal subscription to the plan issued for this switch, moves the
     * local subscription onto it and cancels the replaced one. Caller holds the switch lock.
     *
     * @param Subscription $subscription fresh row, read under the switch lock
     * @param array $paypalSubscription PayPal subscription resource
     * @return string|\WP_Error the new vendor subscription id
     */
    public function finishSwitch(Subscription $subscription, array $paypalSubscription)
    {
        $newVendorSubscriptionId = (string)Arr::get($paypalSubscription, 'id');

        // The id comes from the browser or a webhook: bind it to the plan the store
        // issued for this switch before anything is changed or cancelled.
        $issuedPlanId = $subscription->getMeta('paypal_switch_plan_id');
        if (!$issuedPlanId || Arr::get($paypalSubscription, 'plan_id') !== $issuedPlanId) {
            return new \WP_Error('paypal_switch_refused', __('This PayPal subscription does not match the plan issued for this switch.', 'fluent-cart'));
        }

        if (Arr::get($paypalSubscription, 'custom_id') !== $subscription->uuid) {
            return new \WP_Error('paypal_switch_refused', __('This PayPal subscription was not created for this subscription.', 'fluent-cart'));
        }

        $alreadyLinked = Subscription::query()
            ->where('vendor_subscription_id', Arr::get($paypalSubscription, 'id'))
            ->where('id', '!=', $subscription->id)
            ->exists();
        if ($alreadyLinked) {
            return new \WP_Error('paypal_switch_refused', __('This PayPal subscription is already linked to another subscription.', 'fluent-cart'));
        }

        // From here the new PayPal subscription is ours: refuse it only after cancelling it, or it bills.
        $paypalStatus = Arr::get($paypalSubscription, 'status');
        if (!in_array($paypalStatus, ['ACTIVE', 'APPROVED'], true)) {
            if (!in_array($paypalStatus, ['CANCELLED', 'EXPIRED'], true)) {
                $this->cancelUnusedSwitchSubscription($subscription, $newVendorSubscriptionId);
            }
            return new \WP_Error('paypal_switch_refused', __('This PayPal subscription is not active.', 'fluent-cart'));
        }

        $nextBillingDate = Arr::get($paypalSubscription, 'billing_info.next_billing_time');
        $nextBillingDate = $nextBillingDate ? gmdate('Y-m-d H:i:s', strtotime($nextBillingDate)) : null;

        if ($nextBillingDate && $subscription->next_billing_date && substr($nextBillingDate, 0, 10) < substr(DateTime::anyTimeToGmt($subscription->next_billing_date)->format('Y-m-d H:i:s'), 0, 10)) {
            $this->cancelUnusedSwitchSubscription($subscription, $newVendorSubscriptionId);
            return new \WP_Error('paypal_switch_refused', __('This PayPal subscription would bill before the paid period ends.', 'fluent-cart'));
        }

        $oldPaymentMethod = $subscription->current_payment_method;
        $oldVendorSubscriptionId = $subscription->vendor_subscription_id;
        $oldSubData = [
            'payment_method'         => $oldPaymentMethod,
            'vendor_subscription_id' => $oldVendorSubscriptionId,
            'vendor_customer_id'     => $subscription->vendor_customer_id,
            'vendor_plan_id'         => $subscription->vendor_plan_id,
            'reason'                 => 'switch_payment_method',
            'payment_source'         => SubscriptionMeta::query()
                ->where('subscription_id', $subscription->id)
                ->where('meta_key', 'active_payment_method')
                ->value('meta_value') ?: '',
            'canceled_at'            => DateTime::gmtNow(),
        ];

        // PayPal APPROVED is confirmed but has no local status of its own.
        $localStatus = $paypalStatus === 'APPROVED' ? 'ACTIVE' : $paypalStatus;

        Subscription::query()->where('id', $subscription->id)->update(array_filter([
            'vendor_subscription_id' => Arr::get($paypalSubscription, 'id'),
            'vendor_plan_id'         => Arr::get($paypalSubscription, 'plan_id'),
            'current_payment_method' => 'paypal',
            'vendor_customer_id'     => Arr::get($paypalSubscription, 'subscriber.payer_id'),
            'next_billing_date'      => $nextBillingDate,
            'status'                 => $this->getCorrectSubscriptionStatus($localStatus),
        ]));
        $subscription->deleteMeta('paypal_switch_plan_id');

        $subscription->mergeConfig(['is_trial_days_simulated' => 'yes']);

        $billingInfo = PaymentHelper::parsePaymentMethodDetails('paypal', [
            'email'    => Arr::get($paypalSubscription, 'subscriber.email_address'),
            'payer_id' => Arr::get($paypalSubscription, 'subscriber.payer_id'),
            'name'     => Arr::get($paypalSubscription, 'subscriber.name.given_name') . ' ' . Arr::get($paypalSubscription, 'subscriber.name.surname'),
            'address'  => Arr::get($paypalSubscription, 'subscriber.shipping_address.address')
        ]);

        $subscription->updateMeta('active_payment_method', $billingInfo);

        if ($oldVendorSubscriptionId) {
            $subscription->cancelReplacedVendorSubscription(
                (string)$oldPaymentMethod,
                (string)$oldVendorSubscriptionId,
                __('Subscription switched to PayPal', 'fluent-cart')
            );
        }

        self::addOldSubscriptionMeta($subscription, $oldSubData);

        return $newVendorSubscriptionId;
    }

    private function cancelUnusedSwitchSubscription(Subscription $subscription, $vendorSubscriptionId)
    {
        // Same mode verifySubscription() used: the current store mode, not the order's.
        $response = API::createResource('billing/subscriptions/' . $vendorSubscriptionId . '/cancel', [
            'reason' => __('Payment method switch was not completed.', 'fluent-cart'),
        ]);

        if (is_wp_error($response)) {
            $subscription->addLog(
                __('Unused PayPal subscription was not cancelled', 'fluent-cart'),
                /* translators: 1: PayPal subscription id, 2: error message */
                sprintf(__('Cancel PayPal subscription %1$s at the gateway to stop it billing. Error: %2$s', 'fluent-cart'), $vendorSubscriptionId, $response->get_error_message()),
                'error'
            );
        }
    }

    private function sendSwitchSuccess($vendorSubscriptionId)
    {
        wp_send_json([
            'status'  => 'success',
            'message' => __('Subscription updated successfully', 'fluent-cart'),
            'data'    => $vendorSubscriptionId
        ], 200);
    }

    /**
     * Append the replaced vendor subscription to the subscription's old_subscriptions meta.
     *
     * @param \FluentCart\App\Models\Subscription $subscription
     * @param array $oldSubData
     * @return void
     */
    public static function addOldSubscriptionMeta($subscription, $oldSubData)
    {
        $defaults = [
            'payment_method'         => '',
            'vendor_subscription_id' => '',
            'vendor_customer_id'     => '',
            'vendor_plan_id'         => '',
            'bill_count'             => 0,
            'payment_source'         => '',
            'canceled_at'            => null,
            'reason'                 => '',
            'expire_at'              => null,
        ];
        $oldSubscription = array_merge($defaults, $oldSubData);

        $existingMeta = SubscriptionMeta::query()
            ->where('subscription_id', '=', $subscription->id)
            ->where('meta_key', '=', 'old_subscriptions')
            ->first();

        $oldSubscriptions = [];
        $storedValue = $existingMeta ? $existingMeta->meta_value : null;

        if (is_array($storedValue)) {
            // Rows written before this fix wrap the model's own properties around the list.
            foreach ($storedValue as $key => $entry) {
                if (is_int($key) && is_array($entry)) {
                    $oldSubscriptions[] = $entry;
                }
            }
        }

        $oldSubscriptions[] = $oldSubscription;

        SubscriptionMeta::updateOrCreate([
            'subscription_id' => $subscription->id,
            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_key'        => 'old_subscriptions'
        ], [
            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'meta_value' => $oldSubscriptions
        ]);
    }

    public function getSubscriptionItemForUpdate($subscriptionModel, $variation)
    {
        $trialDays = $subscriptionModel->paymentMethodChangeTrialDays();
        if ($trialDays <= 0) {
            throw new \Exception(esc_html__('Could not get or create plan', 'fluent-cart'));
        }

        $billCount = $subscriptionModel->calculateBillCount();

        // max trial days is 365
        if ($trialDays > 365) {
            $trialDays = 365;
        }

        $billTimes = Arr::get($subscriptionModel, 'bill_times', 0);
        if ($billTimes && $billCount) {
            $billTimes = $billTimes - $billCount;
        } else {
            $billTimes = 0;
        }

        $expireAt = null;
        // expire at is the end date of the subscription, if bill times is 0 then it is null
        if ($billTimes) {
            $expireAt = SubscriptionHelper::getSubscriptionCancelAtTimeStamp($trialDays, $billTimes, $subscriptionModel->billing_interval);
        }

        $processedSubscriptionItem = [
            'billing_interval' => Arr::get($subscriptionModel, 'billing_interval'),
            'recurring_amount' => Arr::get($subscriptionModel, 'recurring_total'),
            'line_total'       => intval(Arr::get($subscriptionModel, 'recurring_total')),
            'id'               => Arr::get($subscriptionModel, 'variation_id'),
            'trial_days'       => $trialDays,
            'product_id'       => Arr::get($subscriptionModel, 'product_id'),
            'parent_order_id'  => Arr::get($subscriptionModel, 'parent_order_id'),
            'item_name'        => Arr::get($subscriptionModel, 'item_name'),
            'expire_at'        => $expireAt,
            'bill_times'       => $billTimes,
        ];

        $processedSubscriptionItem['trial_end'] = DateTime::anyTimeToGmt($subscriptionModel->next_billing_date)->getTimestamp();

        return $processedSubscriptionItem;

    }

    public function getCorrectSubscriptionStatus($status): string
    {
        $status = strtolower($status);
        if ('active' == $status) {
            $status = Status::SUBSCRIPTION_ACTIVE;
        } else if ('trialing' == $status) {
            $status = Status::SUBSCRIPTION_TRIALING;
        } else if ('cancelled' == $status || 'canceled' == $status) {
            $status = Status::SUBSCRIPTION_CANCELED;
        } else if ('expired' == $status) {
            $status = Status::SUBSCRIPTION_EXPIRED;
        } else if ('paused' == $status) {
            $status = Status::SUBSCRIPTION_PAUSED;
        } else if ('expiring' == $status) {
            $status = Status::SUBSCRIPTION_EXPIRING;
        } else if ('suspended' == $status) {
            $status = Status::SUBSCRIPTION_PAUSED;
        }
        return $status;
    }

    public function sendError($message, $code = 422): void
    {
        wp_send_json([
            'status'  => 'failed',
            'message' => $message
        ], $code);
    }

}
