<?php

namespace FluentCart\App\Events\Subscription;

use FluentCart\App\Events\EventDispatcher;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;

class SubscriptionEOT extends EventDispatcher
{

    public string $hook = 'fluent_cart/subscription_eot';
    protected array $listeners = [];

    /**
     * @var Subscription
     */
    public Subscription $subscription;

    /**
     * @var Order
     */
    public Order $order;

    public function __construct(Subscription $subscription, Order $order)
    {
        $this->subscription = $subscription;
        $this->order = $order;
        $this->order->load('customer');
    }


    public function toArray(): array
    {
        return [
            'subscription' => $this->subscription,
            'order'        => $this->order,
            'customer'     => $this->order->customer ?? [],
        ];
    }

    public function beforeDispatch()
    {
        $this->subscription->cancelRemoteSubscription([
            'reason'     => 'end_of_term',
            'fire_hooks' => false
        ]);
    }

    public function getActivityEventModel()
    {
        return $this->subscription;
    }

    public function shouldCreateActivity(): bool
    {
        return true;
    }

}

