<?php

namespace FluentCart\App\Modules\PaymentMethods\StripeGateway;

use FluentCart\App\Events\Subscription\SubscriptionActivated;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Models\SubscriptionMeta;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\API\API;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;

class SubscriptionsManager
{
    public function updateSubscriptionStatus($subscriptionId, $status)
    {
        $subscription = Subscription::query()->where('id', $subscriptionId)->first();
        if ($subscription) {
            $subscription->status = $status;
            $subscription->save();
        }
    }

    public function validate($vendorChargeId, $data)
    {
        if ($vendorChargeId !== Arr::get($data, 'vendor_charge_id')) {
            return new \WP_Error('invalid_vendor_charge_id', __('Invalid vendor charge ID.', 'fluent-cart'));
        }
        return true;
    }


    /**
     * verify payment method via SetupIntent for future off-session payments, fraud prevention, and SCA compliance.
     * @return true | wp_send_json_success | wp_send_json_error
     * @throws \Exception
     */
    public static function verifyPaymentMethod($paymentMethodId, $customerId, $offSession = true)
    {
        // Verify via SetupIntent (for SCA compliance)
        $setupIntent = (new API())->createStripeObject('setup_intents', [
            'payment_method'       => $paymentMethodId,
            'customer'             => $customerId,
            'payment_method_types' => ['card'],
            'confirm'              => 'true',
            'usage'                => 'off_session'
        ]);

        $status = Arr::get($setupIntent, 'status');

        if ('requires_action' === $status) {
            wp_send_json([
                'status'        => 'requires_action',
                'message'       => __('Payment method updated successfully', 'fluent-cart'),
                'client_secret' => Arr::get($setupIntent, 'client_secret'),
                'customer_id'   => $customerId,
            ], 200);
        }

        if ('succeeded' !== $status) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Card verification failed', 'fluent-cart')
            ], 423);
        }
        return true;
    }

    public function getOrCreateStripeCustomer($pm)
    {
        $api = new API();
        // check if customer exist with the email, currently not using
        $email = Arr::get($pm, 'billing_details.email');
        $customers = $api->getStripeObject('customers', [
            'email' => $email, 'limit' => 1
        ]);

        if ($customers && !is_wp_error($customers) && !empty($customers['data'][0])) {
            return Arr::get($customers, 'data.0.id');
        }

        $response = $api->createStripeObject('customers', [
            'name'    => Arr::get($pm, 'billing_details.name'),
            'email'   => Arr::get($pm, 'billing_details.email'),
            'address' => Arr::get($pm, 'billing_details.address'),
        ]);

        if (is_wp_error($response)) {
            static::sendError($response->get_error_message());
        }

        return Arr::get($response, 'id');
    }

    public static function addOldSubscriptionMeta($subscriptionId, $oldSubData)
    {
        $defaults = [
            'payment_method'         => '',
            'vendor_subscription_id' => '',
            'vendor_customer_id'     => '',
            'vendor_plan_id'         => '',
            'payment_source'         => '',
            'canceled_at'            => null,
            'reason'                 => '',
            'expire_at'              => null,
        ];

        $oldSubscription = array_merge($defaults, $oldSubData);

        // get if exists
        $existingMeta = SubscriptionMeta::query()
            ->where('subscription_id', '=', $subscriptionId)
            ->where('meta_key', '=', 'old_subscriptions')
            ->first();

        $oldSubscriptions = [];

        if ($existingMeta && $existingMeta->meta_value) {
            if (is_string($existingMeta->meta_value)) {
                $decoded = json_decode($existingMeta->meta_value, true);
                $oldSubscriptions = is_array($decoded) ? $decoded : [];
            } else {
                $oldSubscriptions = (array)$existingMeta->meta_value;
            }
        }

        // Add new subscription data
        $oldSubscriptions[] = $oldSubscription;

        // Update or create the meta with JSON encoded value
        SubscriptionMeta::updateOrCreate(
            [
                'subscription_id' => $subscriptionId,
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_key'        => 'old_subscriptions'
            ],
            [
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'meta_value' => $oldSubscriptions
            ]
        );
    }

    public static function sendError($message, $code = 423)
    {
        wp_send_json([
            'status'  => 'failed',
            'message' => $message
        ], $code);
    }

    // Used by IPN and Charge Success to confirm subscription after charge succeeded
    public function confirmSubscriptionAfterChargeSucceeded(Subscription $subscription, $billingInfo = [])
    {
        $order = $subscription->order;

        if (!$order) {
            return;
        }

        $api = new API();
        $response = $api->getStripeObject('subscriptions/' . $subscription->vendor_subscription_id, [], $order->mode);

        if (is_wp_error($response)) {
            return;
        }

        $nextBillingDate = Arr::get($response, 'current_period_end') ?? null;

        if ($nextBillingDate) {
            $nextBillingDate = gmdate('Y-m-d H:i:s', (int) $nextBillingDate);
        }

        $status = StripeHelper::transformSubscriptionStatus($response, $subscription);
        $billCount = OrderTransaction::query()->where('subscription_id', $subscription->id)->count();

        $oldStatus = $subscription->status;

        if (Arr::get($response, 'id')) {
            $subscription->next_billing_date = $nextBillingDate;
            $subscription->status = $status;
            $subscription->current_payment_method = 'stripe';
            $subscription->vendor_subscription_id = Arr::get($response, 'id');
            $subscription->bill_count = $billCount;
            $subscription->save();
        }

        if ($billingInfo) {
            $subscription->updateMeta('active_payment_method', $billingInfo);
        }

        if ($oldStatus != $subscription->status && (Status::SUBSCRIPTION_ACTIVE === $subscription->status || Status::SUBSCRIPTION_TRIALING === $subscription->status)) {
            (new SubscriptionActivated($subscription, $order, $order->customer))->dispatch();
        }

        return $subscription;
    }

}
