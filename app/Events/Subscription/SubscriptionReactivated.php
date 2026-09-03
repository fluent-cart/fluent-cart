<?php

namespace FluentCart\App\Events\Subscription;

use FluentCart\App\Events\EventDispatcher;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;

class SubscriptionReactivated extends EventDispatcher
{
    public string $hook = 'fluent_cart/subscription_reactivated';
    protected array $listeners = [];

    public Subscription $subscription;
    public ?Customer $customer;
    public ?Order $order;
    public ?string $oldStatus;

    public function __construct(Subscription $subscription, ?Order $order = null, ?Customer $customer = null, ?string $oldStatus = null)
    {
        $this->subscription = $subscription;
        $this->order = $order;
        $this->customer = $customer;
        $this->oldStatus = $oldStatus;
    }

    public function toArray(): array
    {
        return [
            'subscription' => $this->subscription,
            'order'        => $this->order,
            'customer'     => $this->customer ?? [],
            'old_status'   => $this->oldStatus,
        ];
    }

    public function getActivityEventModel()
    {
        return $this->subscription;
    }
}
