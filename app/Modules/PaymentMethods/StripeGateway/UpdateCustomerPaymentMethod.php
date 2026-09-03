<?php

namespace FluentCart\App\Modules\PaymentMethods\StripeGateway;

use FluentCart\App\Models\Subscription;
use FluentCart\App\Models\SubscriptionMeta;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\API\API;
use FluentCart\App\Modules\Subscriptions\Services\SystemChargeService;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\Framework\Support\Arr;

class UpdateCustomerPaymentMethod
{
    private SubscriptionsManager $subscriptions;

    public function __construct()
    {
        $this->subscriptions = new SubscriptionsManager();
    }

    /**
     * @throws \Exception
     */
    public function update($data, $subscriptionId)
    {
        $vendorSubscriptionId = Arr::get($data, 'vendor_subscription_id');
        $newPaymentMethod = Arr::get($data, 'newPaymentMethod');
        $verificationStatus = Arr::get($data, 'verification_status', '');

        if (!$vendorSubscriptionId) {
            // System (auto-charged) subscriptions have no vendor subscription — the
            // stored token IS the subscription's payment method. Update it locally.
            $localSubscription = Subscription::query()->find($subscriptionId);

            if ($localSubscription instanceof Subscription && $localSubscription->isSystem()) {
                $this->updateSystemPaymentMethod($localSubscription, $newPaymentMethod, $verificationStatus);
                return;
            }

            throw new \Exception(esc_html__('This subscription has no vendor subscription to update.', 'fluent-cart'), 423);
        }

        // get customer id
        $subscription = Subscription::query()->where('vendor_subscription_id', $vendorSubscriptionId)->first();
        $customerId = $subscription->vendor_customer_id;

        $newPaymentMethodId = Arr::get($newPaymentMethod, 'id');

        if ('verify' === $verificationStatus) {
            // verify the payment method
            $this->subscriptions::verifyPaymentMethod($newPaymentMethodId, $customerId, true);
        }


        $response = (new API())->createStripeObject('payment_methods/' . $newPaymentMethodId . '/attach', ['customer' => $customerId]);

        if (is_wp_error($response)) {
            wp_send_json([
                'status'  => 'failed',
                'message' => $response->get_error_message()
            ], 423);
        }

        // now update the subscription's default payment method ,
        // ensures that the subscription will use the new payment method for the next invoice and all subsequent invoices
        static::updateSubscriptionDefaultPaymentMethod($vendorSubscriptionId, $newPaymentMethodId, $newPaymentMethod, $customerId, $subscriptionId);

    }


    /**
     * Replace the stored token of a system (auto-charged) subscription. No vendor
     * subscription exists, so nothing is called on Stripe's subscriptions API: the
     * new payment method is verified for off-session use (SCA mandate), attached to
     * the Stripe customer, and written to active_payment_method — which the charge
     * engine reads at fire time, so the next renewal (or a pending retry) uses it.
     *
     * @throws \Exception
     */
    private function updateSystemPaymentMethod(Subscription $subscription, $newPaymentMethod, $verificationStatus)
    {
        $newPaymentMethodId = Arr::get($newPaymentMethod, 'id');
        $customerId = $subscription->vendor_customer_id;

        if (!$newPaymentMethodId || !$customerId) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('This subscription has no saved payment profile to update.', 'fluent-cart')
            ], 423);
        }

        if ('verify' === $verificationStatus) {
            // SCA-compliant off-session mandate (handles requires_action + rate limiting)
            $this->subscriptions::verifyPaymentMethod($newPaymentMethodId, $customerId, true);
        }

        $response = (new API())->createStripeObject('payment_methods/' . $newPaymentMethodId . '/attach', [
            'customer' => $customerId
        ], $subscription->order ? $subscription->order->mode : 'current');

        if (is_wp_error($response)) {
            wp_send_json([
                'status'  => 'failed',
                'message' => $response->get_error_message()
            ], 423);
        }

        $billingInfo = PaymentHelper::parsePaymentMethodDetails('stripe', $newPaymentMethod);

        SubscriptionMeta::updateOrCreate([
            'subscription_id' => $subscription->id,
            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_key'        => 'active_payment_method'
        ], [
            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'meta_value' => json_encode($billingInfo)
        ]);

        // The previous decline reason describes the OLD card — the portal and admin
        // render it verbatim, so drop it. Retry bookkeeping and any in-flight
        // processing marker survive; the next attempt reads the new token.
        SystemChargeService::clearFailureState($subscription);

        $subscription->addLog(
            'Payment method updated',
            __('The saved payment method for automatic renewal charges was updated by the customer.', 'fluent-cart'),
            'info'
        );

        do_action('fluent_cart/subscriptions/system_payment_method_updated', [
            'subscription'   => $subscription,
            'payment_method' => $billingInfo,
        ]);

        wp_send_json([
            'status'  => 'success',
            'message' => __('Payment method updated successfully', 'fluent-cart'),
            'action'  => 'none',
            'data'    => $billingInfo
        ], 200);
    }

    public static function updateSubscriptionDefaultPaymentMethod($vendorSubscriptionId, $newPaymentMethodId, $paymentMethod, $customerId, $subscriptionId, $customerDefault = false)
    {
        // attach payment method to customer and make it the default payment method for the subscription
        $subscriptionData = [
            'default_payment_method' => $newPaymentMethodId
        ];

        $response = (new API())->createStripeObject('subscriptions/' . $vendorSubscriptionId, $subscriptionData);

        if (is_wp_error($response)) {
            wp_send_json([
                'status'  => 'failed',
                'message' => $response->get_error_message()
            ], 423);
        }

        if ($customerDefault) {
            $response = (new API())->createStripeObject('customers/' . $customerId, ['invoice_settings' => ['default_payment_method' => $newPaymentMethodId]]);
            if (is_wp_error($response)) {
                wp_send_json([
                    'status'  => 'failed',
                    'message' => $response->get_error_message()
                ], 423);
            }
        }

        // update active payment method subscription meta
        $billingInfo = PaymentHelper::parsePaymentMethodDetails('stripe', $paymentMethod);

        SubscriptionMeta::updateOrCreate([
            'subscription_id' => $subscriptionId,
            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_key'        => 'active_payment_method'
        ], [
            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'meta_value' => json_encode($billingInfo)
        ]);

        wp_send_json([
            'status'  => 'success',
            'message' => __('Payment method updated successfully', 'fluent-cart'),
            'action'  => 'none',
            'data'    => $billingInfo
        ], 200);
    }
}
