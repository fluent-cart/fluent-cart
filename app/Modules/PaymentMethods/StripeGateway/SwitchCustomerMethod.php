<?php

namespace FluentCart\App\Modules\PaymentMethods\StripeGateway;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Models\SubscriptionMeta;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\API\API;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Payments\PaymentHelper;
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
            throw new \Exception(esc_html__('Invalid request', 'fluent-cart'));
        }

        $subscriptionModel = Subscription::query()->where('id', $subscriptionId)->first();

        if (!$subscriptionModel->lockPaymentMethodSwitch()) {
            $this->sendError(__('A payment method switch is already in progress.', 'fluent-cart'), 409);
        }

        try {
            $freshModel = Subscription::query()->where('id', $subscriptionId)->first();
            // A request that waited on the lock must not act on the row a finished switch already replaced.
            if ($freshModel->vendor_subscription_id !== $subscriptionModel->vendor_subscription_id) {
                $this->sendError(__('This subscription was just switched. Please refresh the page.', 'fluent-cart'), 409);
            }
            $this->switchLocked($data, $freshModel);
        } finally {
            $subscriptionModel->unlockPaymentMethodSwitch();
        }
    }

    /**
     * @throws \Exception
     */
    private function switchLocked($data, Subscription $subscriptionModel)
    {
        $subscriptionId = $subscriptionModel->id;
        $pm = Arr::get($data, 'vendorPaymentMethod');
        $verificationStatus = sanitize_text_field(Arr::get($data, 'verification_status', ''));
        $customerId = sanitize_text_field(Arr::get($data, 'customer_id'));

        if (!$customerId) {
            // this is part of preventing duplicating customer
            if ('stripe' === $subscriptionModel->current_payment_method) {
                $customerId = $subscriptionModel->vendor_customer_id;
            }
            if (!$customerId) {
                $stripeCustomer = StripeHelper::createOrGetStripeCustomer($subscriptionModel->customer, $subscriptionModel->order->currency);
                if (is_wp_error($stripeCustomer)) {
                    $this->sendError($stripeCustomer->get_error_message());
                }
                $customerId = Arr::get($stripeCustomer, 'id');
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
            'order_id'         => $subscriptionModel->parent_order_id,
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

        if (!in_array($newSubStatus, [Status::SUBSCRIPTION_TRIALING, Status::SUBSCRIPTION_ACTIVE], true)) {
            (new API())->deleteStripeObject('subscriptions/' . Arr::get($newSub, 'id'));
            $this->sendError(__('Could not switch payment method, please try again later', 'fluent-cart'));
        }

        $oldPaymentMethod = $subscriptionModel->current_payment_method;
        $oldVendorSubId = $subscriptionModel->vendor_subscription_id;
        $oldData = [
            'payment_method'         => $oldPaymentMethod,
            'vendor_subscription_id' => $oldVendorSubId,
            'vendor_customer_id'     => $subscriptionModel->vendor_customer_id,
            'vendor_plan_id'         => $subscriptionModel->vendor_plan_id,
            'payment_source'         => SubscriptionMeta::query()
                ->where('subscription_id', $subscriptionModel->id)
                ->where('meta_key', 'active_payment_method')
                ->value('meta_value'),
            'reason'                 => 'switch_payment_method',
            'canceled_at'            => DateTime::gmtNow(),
        ];

        $this->updateSubscription($subscriptionId, $newSub, $plan, $customerId);
        $this->updateBillingInfo($subscriptionId, $pm);

        if ($oldVendorSubId) {
            $subscriptionModel->cancelReplacedVendorSubscription(
                (string)$oldPaymentMethod,
                (string)$oldVendorSubId,
                __('Subscription switched to Stripe', 'fluent-cart')
            );
        }
        SubscriptionsManager::addOldSubscriptionMeta($subscriptionModel->id, $oldData);

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
                'name'       => $order->full_name,
                'order_reference' => 'fct_order_id_' . $order->id,
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

        $subscriptionModel->update([
            'vendor_subscription_id' => Arr::get($newSub, 'id'),
            'vendor_plan_id'         => Arr::get($newSub, 'id'),
            'current_payment_method' => 'stripe',
            'vendor_customer_id'     => $customerId,
            'status'                 => StripeHelper::transformSubscriptionStatus($newSub),
        ]);

        $subscriptionModel->mergeConfig(['is_trial_days_simulated' => 'yes']);
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

    public function getSubscriptionItem($subscription, $variation)
    {
        $trialDays = $subscription->paymentMethodChangeTrialDays();
        if ($trialDays <= 0) {
            throw new \Exception(esc_html__('Could not switch payment method', 'fluent-cart'));
        }

        $billTimes = Arr::get($subscription, 'bill_times');

        $billCount = $subscription->calculateBillCount();

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

        $processedSubscriptionItem['trial_end'] = DateTime::anyTimeToGmt($subscription->next_billing_date)->getTimestamp();

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
