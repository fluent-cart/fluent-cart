<?php

namespace Tests\Support\Traits;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\Subscription;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;

/**
 * Multi-step setup helper for subscription REST tests.
 *
 * A subscription row in fct_subscriptions requires customer_id + parent_order_id +
 * product_id + variation_id. Building the full chain (customer → product → order →
 * subscription) inline in 20+ Cest methods is unreadable, and the chain is identical
 * across list / view / cancel / resync Cests. Build it once here, then let each
 * method override only the fields the spec sentence actually exercises.
 */
trait BuildsSubscriptions
{
    /**
     * Build a complete subscription chain and return [customer, product, order, subscription].
     * Pass $subscriptionOverrides to control billing_interval / status / current_payment_method /
     * vendor_subscription_id / item_name / bill_times / bill_count / next_billing_date / config /
     * created_at without rewriting the customer + product + order boilerplate at every call site.
     */
    protected function buildSubscriptionChain(
        array $subscriptionOverrides = [],
        array $customerOverrides = [],
        array $orderOverrides = []
    ): array {
        $customer = (new CustomerFactory())->create($customerOverrides);
        $product  = (new ProductFactory())->create();
        $variant  = $product->variants->first();

        $order = (new OrderFactory())->create(array_merge(
            ['customer_id' => $customer->id],
            $orderOverrides
        ));

        $subscription = (new SubscriptionFactory())->create(array_merge(
            [
                'customer_id'     => $customer->id,
                'parent_order_id' => $order->id,
                'product_id'      => $product->ID,
                'variation_id'    => $variant->id,
            ],
            $subscriptionOverrides
        ));

        return [$customer, $product, $order, $subscription];
    }

    /**
     * Convenience for tests that only need the subscription row.
     */
    protected function buildSubscription(
        array $subscriptionOverrides = [],
        array $customerOverrides = [],
        array $orderOverrides = []
    ): Subscription {
        [, , , $subscription] = $this->buildSubscriptionChain(
            $subscriptionOverrides,
            $customerOverrides,
            $orderOverrides
        );
        return $subscription;
    }

    /**
     * Build a complete product, order, and subscription chain for an existing
     * customer. Useful for customer-portal tests whose WordPress user link is
     * part of the request authorization contract.
     */
    protected function buildSubscriptionForCustomer(
        int $customerId,
        array $subscriptionOverrides = [],
        array $orderOverrides = []
    ): Subscription {
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();
        $order = (new OrderFactory())->create(array_merge(
            ['customer_id' => $customerId],
            $orderOverrides
        ));

        return (new SubscriptionFactory())->create(array_merge(
            [
                'customer_id'     => $customerId,
                'parent_order_id' => $order->id,
                'product_id'      => $product->ID,
                'variation_id'    => $variant->id,
            ],
            $subscriptionOverrides
        ));
    }

    /**
     * Build a finite-term (installment / split-pay) subscription on an existing
     * customer + parent order. $perCharge is in dollars.
     */
    protected function buildInstallmentSubscription(
        int $customerId,
        int $orderId,
        int $billTimes,
        int $billCount,
        $perCharge,
        array $overrides = []
    ): Subscription {
        return (new SubscriptionFactory())->create(array_merge([
            'customer_id'      => $customerId,
            'parent_order_id'  => $orderId,
            'product_id'       => 555,
            'variation_id'     => 5550,
            'recurring_total'  => Helper::toCent($perCharge),
            'recurring_amount' => Helper::toCent($perCharge),
            'bill_times'       => $billTimes,
            'bill_count'       => $billCount,
            'billing_interval' => 'monthly',
        ], $overrides));
    }

    /**
     * Record N succeeded charge transactions against a subscription so
     * Subscription::calculateBillCount() counts them. $perCharge in dollars.
     */
    protected function recordSucceededCharges(int $orderId, int $subscriptionId, int $count, $perCharge): void
    {
        $factory = new OrderTransactionFactory();
        for ($i = 0; $i < $count; $i++) {
            $factory->create($orderId, [
                'subscription_id'  => $subscriptionId,
                'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
                'status'           => Status::TRANSACTION_SUCCEEDED,
                'total'            => Helper::toCent($perCharge),
            ]);
        }
    }
}
