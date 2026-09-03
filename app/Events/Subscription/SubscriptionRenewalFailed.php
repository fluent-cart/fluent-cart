<?php

namespace FluentCart\App\Events\Subscription;

use FluentCart\App\Events\EventDispatcher;
use FluentCart\App\Listeners;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;

class SubscriptionRenewalFailed extends EventDispatcher
{
    public string $hook = 'fluent_cart/subscription_renewal_failed';
    protected array $listeners = [
        Listeners\Subscription\SubscriptionRenewalFailed::class,
    ];

    /**
     * @var Subscription $subscription
     */
    public Subscription $subscription;

    /**
     * @var Order $order
     */
    public Order $order;

    /**
     * @var Customer|null $customer
     */
    public ?Customer $customer;

    /**
     * @var string $error Gateway failure reason
     */
    public string $error;

    public function __construct($subscription, $order, $customer, $error = '')
    {
        $this->subscription = $subscription;
        $this->order = $order;
        $this->customer = $customer;
        $this->error = $error;
    }

    public function toArray(): array
    {
        return [
            'subscription' => $this->subscription ?? null,
            'order'        => $this->order ?? null,
            'customer'     => $this->customer ?? null,
            'error'        => $this->error ?? '',
        ];
    }

    public function getActivityEventModel()
    {
        return $this->subscription;
    }

}
