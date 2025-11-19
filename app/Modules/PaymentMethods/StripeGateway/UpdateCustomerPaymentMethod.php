<?php

namespace FluentCart\App\Modules\PaymentMethods\StripeGateway;

use FluentCart\App\Models\Subscription;
use FluentCart\App\Models\SubscriptionMeta;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\API\API;
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
            return;
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
