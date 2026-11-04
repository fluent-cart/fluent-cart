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

    /** @var array Renewal amount in FluentCart cents and its currency. */
    public array $renewalPayment = [];

    public function __construct($subscription, $order, $customer, $error = '', array $renewalPayment = [])
    {
        $this->subscription = $subscription;
        $this->order = $order;
        $this->customer = $customer;
        $this->error = $error;
        $this->renewalPayment = $renewalPayment;
    }

    public function toArray(): array
    {
        return [
            'subscription'    => $this->subscription ?? null,
            'order'           => $this->order ?? null,
            'customer'        => $this->customer ?? null,
            'error'           => $this->error ?? '',
            'renewal_payment' => $this->renewalPayment,
        ];
    }

    public function getActivityEventModel()
    {
        return $this->subscription;
    }

    public function getEventInfoForActivity(): array
    {
        return [
            'content' => sprintf(
                /* translators: %1$s is the payment gateway's failure reason */
                __('Automatic renewal charge failed: %1$s', 'fluent-cart'),
                $this->error
            ),
            'status'  => 'error',
        ];
    }

}
