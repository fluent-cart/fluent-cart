<?php

namespace FluentCartPro\App\Modules\PaymentMethods\PaddleGateway;

use FluentCartPro\App\Modules\PaymentMethods\PaddleGateway\API\API;
use FluentCart\App\Events\Subscription\SubscriptionActivated;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Models\SubscriptionMeta;
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
            return new \WP_Error('invalid_vendor_charge_id', __('Invalid vendor charge ID.'));
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
        // TODO verify payment method on 0 payment subscription
        return true;
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
                'meta_key'        => 'old_subscriptions'
            ],
            [
                'meta_value' => $oldSubscriptions
            ]
        );
    }

    public function confirmSubscriptionAfterChargeSucceeded(Subscription $subscription, $billingInfo = [])
    {
        $order = $subscription->order;

        if (!$order) {
          return false;
        }

        $response = API::getPaddleObject("subscriptions/124324", [], $order->mode);


        if (is_wp_error($response)) {
           return false;
        }

        $nextBillingDate = Arr::get($response, 'data.next_billed_at') ?? null;

        if ($nextBillingDate) {
            $nextBillingDate = DateTime::anyTimeToGmt($nextBillingDate)->format('Y-m-d H:i:s');
        }

        $status = PaddleHelper::transformSubscriptionStatus($response, $subscription);
        $billCount = OrderTransaction::query()->where('subscription_id', $subscription->id)->count();

        $oldStatus = $subscription->status;

        if (Arr::get($response, 'id')) {
            $subscription->next_billing_date = $nextBillingDate;
            $subscription->status = $status;
            $subscription->current_payment_method = 'paddle';
            $subscription->vendor_subscription_id = Arr::get($response, 'data.id');
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