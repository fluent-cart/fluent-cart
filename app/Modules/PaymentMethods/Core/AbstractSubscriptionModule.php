<?php

namespace FluentCart\App\Modules\PaymentMethods\Core;

use FluentCart\App\Models\Subscription;

abstract class AbstractSubscriptionModule
{
    /**
     * @throws \Exception
     */
    public function fetchSubscription($data, $order, $subscription)
    {
        throw new \Exception(esc_html__('Subscription fetching not available for this method!', 'fluent-cart'), 404);
    }

    /**
     * @throws \Exception
     */
    public function cardUpdate($data, $subscriptionId)
    {
        throw new \Exception(esc_html__('No valid payment method to update!', 'fluent-cart'), 404);
    }

    /**
     * @throws \Exception
     */
    public function switchPaymentMethod($data, $subscriptionId)
    {
        throw new \Exception(esc_html__('No valid payment method to switch!', 'fluent-cart'), 404);
    }

    /**
     * @throws \Exception
     */
    public function reactivateSubscription($data, $subscriptionId)
    {
        throw new \Exception(esc_html__('No valid payment method to reactivate!', 'fluent-cart'), 404);
    }

    /**
     * @throws \Exception
     */
    public function pauseSubscription($data, $order, $subscription)
    {
        throw new \Exception(esc_html__('No valid payment method to pause!', 'fluent-cart'), 404);
    }

    /**
     * @throws \Exception
     */
    public function resumeSubscription($data, $order, $subscription)
    {
        throw new \Exception(esc_html__('No valid payment method to resume!', 'fluent-cart'), 404);
    }

    public function cancel($vendorSubscriptionId, $args = [])
    {
        return new \WP_Error(
            'not_implemented',
            esc_html__('Cancel subscription is not implemented for this payment method.', 'fluent-cart')
        );
    }

    /**
     * @throws \Exception
     */
    public function cancelSubscription($data, $order, $subscription)
    {
        throw new \Exception(esc_html__('No valid payment method to cancel!', 'fluent-cart'), 404);
    }

    public function cancelOnPlanChange($vendorSubscriptionId, $parentOrderId, $subscriptionId, $reason)
    {
        // cancel the subscription on the vendor side, implement on the child class
    }

    public function cancelAutoRenew($subscription)
    {
        // cancel the subscription on the vendor side, implement on the child class
    }

    public function cancelOnSwitchPaymentMethod($currentVendorSubscriptionId, $parentOrderId, $vendorSubscriptionId, $newPaymentMethod, $reason)
    {
        // cancel the subscription on the vendor side, implement on the child class
    }

    public function reSyncSubscriptionFromRemote(Subscription $subscriptionModel)
    {
        return new \WP_Error(
            'not_implemented',
            esc_html__('Re-sync subscription from remote is not implemented for this payment method.', 'fluent-cart')
        );
    }
}
