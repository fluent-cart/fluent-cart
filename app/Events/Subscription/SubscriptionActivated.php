<?php


namespace FluentCart\App\Events\Subscription;

use FluentCart\App\Events\EventDispatcher;
use FluentCart\App\Listeners;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;


class SubscriptionActivated extends EventDispatcher
{
    public string $hook = 'fluent_cart/subscription_activated';
    protected array $listeners = [

    ];

    /**
     * @var Subscription $subscription
     */
    public Subscription $subscription;

    /**
     * @var Customer|null $customer
     */
    public ?Customer $customer;

    /**
     * @var Order|null $order
     */
    public ?Order $order;

    public array $meta = [];

    public function __construct($subscription, $order = null, $customer = null, $meta = [])
    {
        $this->subscription = $subscription;
        $this->order = $order;
        $this->customer = $customer;
        $this->meta = $meta;
    }


    public function toArray(): array
    {
        return [
            'subscription' => $this->subscription,
            'order' => $this->order,
            'customer' => $this->customer ?? [],
            'meta' => $this->meta
        ];
    }

    public function getActivityEventModel()
    {
        return $this->subscription;
    }

}