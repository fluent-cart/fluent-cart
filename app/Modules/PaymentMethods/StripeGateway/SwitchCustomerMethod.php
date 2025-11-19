<?php

namespace FluentCart\App\Modules\PaymentMethods\StripeGateway;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Models\SubscriptionMeta;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\API\API;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Services\Payments\SubscriptionHelper;
use FluentCart\Framework\Support\Arr;

class SwitchCustomerMethod
{
    private $subscriptions;

    public function __construct()
    {
        $this->subscriptions = new SubscriptionsManager();
    }

    /**
     * @throws \Exception
     */
    public function switchPayMethod($data, $subscriptionId)
    {
        if (!$this->validateRequest($data, $subscriptionId)) {
            throw new \Exception('Invalid request');
        }

        $subscriptionModel = Subscription::query()->where('id', $subscriptionId)->first();
        $currentPaymentMethod = sanitize_text_field(Arr::get($data, 'currentPaymentMethod'));
        $pm = Arr::get($data, 'vendorPaymentMethod');
        $verificationStatus = sanitize_text_field(Arr::get($data, 'verification_status', ''));
        $customerId = sanitize_text_field(Arr::get($data, 'customer_id'));

        if (!$customerId) {
            // this is part of preventing duplicating customer
            if ('stripe' === $subscriptionModel->current_payment_method) {
                $customerId = $subscriptionModel->vendor_customer_id;
            }
            if (!$customerId) {
                $customerId = $this->subscriptions->getOrCreateStripeCustomer($pm);
            }
        }

        $paymentMethodId = Arr::get($pm, 'id');

        if ('verify' === $verificationStatus) {
            $this->subscriptions::verifyPaymentMethod($paymentMethodId, $customerId, true);
        }

        $this->attachPaymentMethodToCustomer($customerId, $paymentMethodId);

        $order = Order::query()->where('id', $subscriptionModel->parent_order_id)->first();
        $variation = ProductVariation::query()->findOrFail($subscriptionModel->variation_id);
        $processedSubscriptionItem = $this->getSubscriptionItem($subscriptionModel, $variation);

        $data = wp_parse_args($processedSubscriptionItem, [
            'product_id'       => $subscriptionModel->product_id,
            'variation_id'     => $subscriptionModel->variation_id,
            'billing_interval' => $subscriptionModel->billing_interval,
            'recurring_total'  => $subscriptionModel->recurring_total,
            'currency'         => $subscriptionModel->order->currency,
            'trial_days'       => (int)$subscriptionModel->trial_days,
            'interval_count'   => 1 // per month / year / week
        ]);


        $plan = Plan::getStripePricing($data);

        if (is_wp_error($plan)) {
            $this->sendError($plan->get_error_message());
        }

        $newSub = $this->createStripeSubscription($customerId, $paymentMethodId, $plan, $processedSubscriptionItem, $order);

        if (is_wp_error($newSub)) {
            $this->sendError($newSub->get_error_message());
        }

        $newSubStatus = StripeHelper::transformSubscriptionStatus($newSub);


        if ($newSubStatus == 'incomplete') {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Could not switch payment method, please try again later', 'fluent-cart'),
                'data'    => Arr::get($newSub, 'id')
            ], 200);
        }

        $oldVendorSubId = $subscriptionModel->vendor_subscription_id;
        $oldVendorCusId = $subscriptionModel->vendor_customer_id;
        $oldData = [
            'vendor_subscription_id' => $subscriptionModel->vendor_subscription_id,
            'vendor_customer_id'     => $subscriptionModel->vendor_customer_id,
            'vendor_plan_id'         => $subscriptionModel->vendor_plan_id,
            'old_payment_gateway'    => $currentPaymentMethod,
            'payment_source'         => SubscriptionMeta::query()
                ->where('subscription_id', $subscriptionModel->id)
                ->where('meta_key', 'active_payment_method')
                ->value('meta_value'),
            'reason'                 => 'switch_payment_method',
            'canceled_at'            => DateTime::gmtNow(),
        ];

        $this->updateSubscription($subscriptionId, $newSub, $plan, $customerId);
        $this->updateBillingInfo($subscriptionId, $pm);
        $this->handleOldSubscription($oldData, $newSub, $subscriptionModel);

        wp_send_json([
            'status'  => 'success',
            'message' => __('Payment Method updated successfully', 'fluent-cart'),
            'data'    => Arr::get($newSub, 'id')
        ], 200);
    }

    private function validateRequest($data, $subscriptionId): bool
    {
        $currentPaymentMethod = sanitize_text_field(Arr::get($data, 'currentPaymentMethod'));
        if (!$currentPaymentMethod || !$subscriptionId) {
            return false;
        }
        return true;
    }

    private function attachPaymentMethodToCustomer($customerId, $paymentMethodId)
    {
        // check if payment method is already attached
        $existingPaymentMethods = (new API())->getStripeObject('customers/' . $customerId . '/payment_methods');

        if (is_wp_error($existingPaymentMethods)) {
            $this->sendError($existingPaymentMethods->get_error_message());
        }

        foreach ($existingPaymentMethods['data'] as $method) {
            if (Arr::get($method, 'id') === $paymentMethodId) {
                return;
            }
        }

        $response = (new API())->createStripeObject('payment_methods/' . $paymentMethodId . '/attach', [
            'customer' => $customerId
        ]);

        if (is_wp_error($response)) {
            $this->sendError($response->get_error_message());
        }
    }


    /**
     * @throws \Exception
     */
    private function createStripeSubscription($customerId, $paymentMethodId, $plan, $processedSubscriptionItem, $order)
    {
        $stripeSubscriptionData = [
            'customer'               => $customerId,
            'payment_behavior'       => 'default_incomplete',
            'payment_settings'       => [
                'save_default_payment_method' => 'on_subscription'
            ],
            'items'                  => [
                [
                    'plan'     => Arr::get($plan, 'id'),
                    'quantity' => 1,
                ]
            ],
            'default_payment_method' => $paymentMethodId,
            'expand'                 => [
                'latest_invoice.confirmation_secret',
                'pending_setup_intent'
            ],
            'metadata'               => [
                'fct_ref_id' => $order->uuid,
                'email'      => $order->customer->email,
                'name'       => $order->full_name
            ]
        ];

        if (!empty($processedSubscriptionItem['expire_at'])) {
            // $stripeSubscriptionData['cancel_at'] = $processedSubscriptionItem['expire_at'];
        }

        if (!empty($processedSubscriptionItem['trial_end'])) {
            $stripeSubscriptionData['trial_end'] = $processedSubscriptionItem['trial_end'];
        }

        $newSub = (new API())->createStripeObject('subscriptions', $stripeSubscriptionData);

        if (is_wp_error($newSub)) {
            throw new \Exception(esc_html($newSub->get_error_message()));
        }

        return $newSub;
    }

    private function updateSubscription($subscriptionId, $newSub, $plan, $customerId)
    {
        $subscriptionModel = Subscription::query()->where('id', $subscriptionId)->first();
        $config = $subscriptionModel->config ?: [];

        $subscriptionModel->update([
            'vendor_subscription_id' => Arr::get($newSub, 'id'),
            'vendor_plan_id'         => Arr::get($newSub, 'id'),
            'current_payment_method' => 'stripe',
            'vendor_customer_id'     => $customerId,
            'status'                 => StripeHelper::transformSubscriptionStatus($newSub),
            'config'                 => array_merge($config, [
                'is_trial_days_simulated' => 'yes'
            ])
        ]);
    }

    private function updateBillingInfo($subscriptionId, $pm)
    {
        $billingInfo = PaymentHelper::parsePaymentMethodDetails('stripe', $pm);

        SubscriptionMeta::updateOrCreate([
            'subscription_id' => $subscriptionId,
            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_key'        => 'active_payment_method'
        ], [
            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'meta_value' => json_encode($billingInfo)
        ]);
    }

    private function handleOldSubscription($oldData, $newSub, $subscriptionModel)
    {
        SubscriptionsManager::addOldSubscriptionMeta($subscriptionModel->id, $oldData);

        $gateway = App::gateway(Arr::get($oldData, 'old_payment_gateway'));
        if ($gateway && $gateway->subscriptions) {
            $gateway->subscriptions->cancel(Arr::get($oldData, 'vendor_subscription_id'), [
                'mode' => $subscriptionModel->order->mode
            ]);
        }
    }

    public function getSubscriptionItem($subscription, $variation)
    {
        $trialDays = 0;
        $nextBillingTimestamp = null;

        // trial days is the difference between the next billing date and the current date in days
        $nextBillingDate = Arr::get($subscription, 'next_billing_date');

        if ($nextBillingDate) {
            $now = DateTime::gmtNow()->getTimestamp();
            $nextBillingTimestamp = DateTime::anyTimeToGmt($nextBillingDate)->getTimestamp();

            if ($nextBillingTimestamp <= $now) {
                $trialDays = 0;
            } else {
                $trialDays = ceil(($nextBillingTimestamp - $now) / 86400);
            }
        }

        // there is loophole for getting 1 day trial and continue the subscription in loop, so we need to check for that
        $trialDays = SubscriptionHelper::checkTrailDaysLoopHole($subscription, $trialDays);

        $billTimes = Arr::get($subscription, 'bill_times');

        $billCount = OrderTransaction::query()->where('subscription_id', $subscription->id)
            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
            ->where('status', Status::TRANSACTION_SUCCEEDED)
            ->count();

        if ($billTimes && $billCount) {
            $billTimes = $billTimes - $billCount;
        } else {
            $billTimes = 0;
        }

        $processedSubscriptionItem = [
            'billing_interval' => Arr::get($subscription, 'billing_interval'),
            'recurring_amount' => intval(Arr::get($subscription, 'recurring_amount')),
            'trial_days'       => $trialDays,
            'bill_times'       => $billTimes,
            'product_id'       => Arr::get($subscription, 'product_id'),
            'parent_order_id'  => Arr::get($subscription, 'parent_order_id'),
            'item_name'        => Arr::get($subscription, 'item_name'),
            'expire_at'        => null,
        ];

        if ($trialDays > 0) {
            $processedSubscriptionItem['trial_end'] = $nextBillingTimestamp;
        }

        return $processedSubscriptionItem;
    }

    public function sendError($message, $code = 423)
    {
        wp_send_json([
            'status'  => 'failed',
            'message' => $message
        ], $code);
    }

}
