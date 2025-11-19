<?php


namespace FluentCart\App\Events\Subscription;

use FluentCart\App\Events\EventDispatcher;
use FluentCart\App\Listeners;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;


class SubscriptionRenewed extends EventDispatcher
{
    public string $hook = 'fluent_cart/subscription_renewed';
    protected array $listeners = [
        Listeners\Subscription\SubscriptionRenewed::class,
    ];


    /**
     * @var $subscription Subscription
     */
    public Subscription $subscription;

    /**
     * @var $customer Customer|null
     */
    public ?Customer $customer;

    /**
     * @var $newOrder Order|null
     */
    public ?Order $newOrder;

    /**
     * @var $oldOrder Order|null
     */
    public ?Order $oldOrder;

    public function __construct($subscription, $newOrder, $oldOrder, $customer)
    {
        $this->subscription = $subscription;
        $this->newOrder = $newOrder;
        $this->oldOrder = $oldOrder;
        $this->customer = $customer;
    }


    public function toArray(): array
    {
        return [
            'subscription' => $this->subscription ?? null,
            'order'        => $this->newOrder ?? null,
            'main_order'   => $this->oldOrder ?? null,
            'customer'     => $this->customer ?? null,
        ];
    }

    public function beforeDispatch()
    {
        $this->newOrder = $this->newOrder->generateReceiptNumber();
    }

    public function getActivityEventModel()
    {
        return $this->subscription;
    }

}
