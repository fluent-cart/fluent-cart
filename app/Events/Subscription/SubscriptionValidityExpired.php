<?php


namespace FluentCart\App\Events\Subscription;

use FluentCart\App\Events\EventDispatcher;
use FluentCart\App\Listeners;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;


class SubscriptionValidityExpired extends EventDispatcher
{
    public string $hook = 'fluent_cart/subscription_expired_validity';
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

    public string $reason;

    public function __construct(Subscription $subscription, $order = null, $customer = null, $reason = '')
    {
        $this->subscription = $subscription;
        $this->order = $order;
        $this->customer = $customer;
        $this->reason = $reason;
    }


    public function toArray(): array
    {
        return [
            'subscription' => $this->subscription,
            'order'        => $this->order,
            'customer'     => $this->customer ?? [],
            'reason'       => $this->reason ?: __('Subscription validity expired', 'fluent-cart'),
        ];
    }

    /**
     * @return Subscription
     */
    public function getActivityEventModel()
    {
        return $this->subscription;
    }

    public function shouldCreateActivity(): bool
    {
        return true;
    }

}
