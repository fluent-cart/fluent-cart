<?php

namespace Tests\Support\Factory;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Subscription;

class SubscriptionFactory
{
    private array $attributes = [];
    private static int $counter = 0;

    public function withAttributes(array $attributes): self
    {
        $clone = clone $this;
        $clone->attributes = array_merge($clone->attributes, $attributes);
        return $clone;
    }

    protected function defaults(): array
    {
        self::$counter++;
        return [
            'billing_interval'       => 'monthly',
            'quantity'               => 1,
            'item_name'              => 'Test Subscription ' . self::$counter,
            'signup_fee'             => 0,
            'recurring_amount'       => Helper::toCent(29.99),
            'recurring_tax_total'    => 0,
            'recurring_total'        => Helper::toCent(29.99),
            'bill_times'             => 0,
            'bill_count'             => 0,
            'trial_days'             => 0,
            'status'                 => Status::SUBSCRIPTION_ACTIVE,
            'current_payment_method' => 'offline_payment',
            'collection_method'      => 'automatic',
            'config'                 => [],
        ];
    }

    /**
     * Create a subscription. Requires customer_id, parent_order_id, product_id, variation_id.
     */
    public function create(array $overrides = []): Subscription
    {
        $attributes = array_merge($this->defaults(), $this->attributes, $overrides);

        if (empty($attributes['customer_id'])) {
            throw new \RuntimeException('SubscriptionFactory requires customer_id');
        }
        if (empty($attributes['parent_order_id'])) {
            throw new \RuntimeException('SubscriptionFactory requires parent_order_id');
        }
        if (empty($attributes['product_id'])) {
            throw new \RuntimeException('SubscriptionFactory requires product_id');
        }
        if (!isset($attributes['variation_id'])) {
            throw new \RuntimeException('SubscriptionFactory requires variation_id');
        }

        return Subscription::query()->create($attributes);
    }
}
