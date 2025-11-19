<?php

namespace FluentCart\App\Modules\PaymentMethods\PayPalGateway;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
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

        wp_send_json([
            'status'  => 'success',
            'message' => 'Plan created successfully',
            'plan'    => $plan,
        ], 200);
    }

    public function confirmSubscriptionSwitch($data, $subscriptionId)
    {
        $newVendorSubscriptionId = Arr::get($data, 'newVendorSubscriptionId');
        $vendorOrderId = Arr::get($data, 'vendorOrderId');

        $subscription = Subscription::query()->where('id', $subscriptionId)->first();
        $order = Order::query()->where('id', $subscription->parent_order_id)->first();

        if (!$newVendorSubscriptionId || !$vendorOrderId) {
            wp_send_json([
                'status'  => 'error',
                'message' => __('Sorry, New Subscription ID or Vendor Order ID is not available!', 'fluent-cart'),
            ], 422);
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

        $oldPaymentMethod = $subscription->current_payment_method;
        $oldVendorSubscriptionId = $subscription->vendor_subscription_id;
        $oldVendorCustomerId = $subscription->vendor_customer_id;
        $oldVendorPlanId = $subscription->vendor_plan_id;

        $vendorSubscriptionId = Arr::get($paypalSubscription, 'id');
        $vendorPlanId = Arr::get($paypalSubscription, 'plan_id');
        $vendorCustomerId = Arr::get($paypalSubscription, 'subscriber.payer_id');

        $nextBillingDate = Arr::get($paypalSubscription, 'billing_info.next_billing_time') ?? null;
        if (!empty($nextBillingDate)) {
            $nextBillingDate = gmdate('Y-m-d H:i:s');
        }

        $config = $subscription->config ?: [];

        // update subscription in table
        $data = array_filter([
            'vendor_subscription_id' => $vendorSubscriptionId,
            'vendor_plan_id'         => $vendorPlanId,
            'current_payment_method' => 'paypal',
            'vendor_customer_id'     => $vendorCustomerId,
            'next_billing_date'      => $nextBillingDate,
            'status'                 => $this->getCorrectSubscriptionStatus(Arr::get($paypalSubscription, 'status')),
            'config'                 => array_merge($config, ['is_trial_days_simulated' => 'yes'])
        ]);

        Subscription::query()->where('id', $subscriptionId)->update($data);

        $billingInfo = PaymentHelper::parsePaymentMethodDetails('paypal', [
            'email'    => Arr::get($paypalSubscription, 'subscriber.email_address'),
            'payer_id' => Arr::get($paypalSubscription, 'subscriber.payer_id'),
            'name'     => Arr::get($paypalSubscription, 'subscriber.name.given_name') . ' ' . Arr::get($paypalSubscription, 'subscriber.name.surname'),
            'address'  => Arr::get($paypalSubscription, 'subscriber.shipping_address.address')
        ]);
        // update subscription meta for active payment method

        $subscription->updateMeta('active_payment_method', $billingInfo);

        $gateway = App::gateway($oldPaymentMethod);
        if ($gateway && $gateway->subscriptions) {
            $gateway->subscriptions->cancel(
                $oldVendorSubscriptionId,
                [
                    'reason' => __('Subscription switched to PayPal', 'fluent-cart'),
                    'mode'   => $order->mode
                ]
            );
        }

        // add or update subscription meta of old subscriptions
        $paymentSource = SubscriptionMeta::query()
            ->where('subscription_id', $subscription->id)
            ->where('meta_key', 'active_payment_method')
            ->first();

        if ($paymentSource) {
            $paymentSource = $paymentSource->meta_value;
        }

        $oldSubData = [
            'payment_method'         => $oldPaymentMethod,
            'vendor_subscription_id' => $oldVendorSubscriptionId,
            'vendor_customer_id'     => $oldVendorCustomerId,
            'vendor_plan_id'         => $oldVendorPlanId,
            'reason'                 => 'switch_payment_method',
            'payment_source'         => $paymentSource ?? '',
            'canceled_at'            => DateTime::gmtNow(),
        ];

        self::addOldSubscriptionMeta($subscription, $oldSubData);

        wp_send_json([
            'status'  => 'success',
            'message' => __('Subscription updated successfully', 'fluent-cart'),
            'data'    => $vendorSubscriptionId
        ], 200);

    }

    /**
     * Confirm subscription reactivation
     *
     * @param array $data | newVendorSubscriptionId (string) required
     * @param int $subscriptionId
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

        // get if exists
        $oldSubscriptions = SubscriptionMeta::query()
            ->where('subscription_id', '=', $subscription->id)
            ->where('meta_key', '=', 'old_subscriptions')
            ->first();

        if ($oldSubscriptions && $oldSubscriptions->meta_value) {
            if (is_string($oldSubscriptions->meta_value)) {
                $oldSubscriptions = $oldSubscriptions->meta_value;
            }
            $oldSubscriptions = (array)$oldSubscriptions ?: [];
            $oldSubscriptions[] = $oldSubscription;
        } else {
            $oldSubscriptions = [$oldSubscription];
        }

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
        $trialDays = 0;

        // trial days is the difference between the next billing date and the current date in days
        $nextBillingDate = $subscriptionModel->next_billing_date;
        $nextBillingTimestamp = strtotime($nextBillingDate);

        if ($nextBillingTimestamp && $nextBillingTimestamp > time()) {
            $trialDays = ceil(($nextBillingTimestamp - time()) / 86400);
        }

        $billCount = OrderTransaction::query()->where('subscription_id', $subscriptionModel->id)
            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
            ->where('status', Status::TRANSACTION_SUCCEEDED)
            ->count();


        $trialDays = SubscriptionHelper::checkTrailDaysLoopHole($subscriptionModel, $trialDays);

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
            'recurring_amount' => Arr::get($subscriptionModel, 'recurring_amount'),
            'line_total'       => intval(Arr::get($subscriptionModel, 'recurring_amount')),
            'id'               => Arr::get($subscriptionModel, 'variation_id'),
            'trial_days'       => $trialDays,
            'product_id'       => Arr::get($subscriptionModel, 'product_id'),
            'parent_order_id'  => Arr::get($subscriptionModel, 'parent_order_id'),
            'item_name'        => Arr::get($subscriptionModel, 'item_name'),
            'expire_at'        => $expireAt,
            'bill_times'       => $billTimes,
        ];

        if ($trialDays > 0) {
            $processedSubscriptionItem['trial_end'] = $nextBillingTimestamp;
        }

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
