<?php

namespace FluentCart\App\Events\Order;

use FluentCart\App\Events\EventDispatcher;
use FluentCart\App\Listeners;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;

class RenewalOrderDeleted extends EventDispatcher
{
    public string $hook = 'fluent_cart/renewal_order_deleted';

    protected array $listeners = [
        // Listeners\UpdateStock::class,
        Listeners\Order\RenewalOrderDeleted::class
    ];

    /**
     * @var $order Order
     */
    public Order $order;

    /**
     * @var $customer Customer|null
     */

    public function __construct(Order $order)
    {
        $this->order = $order;
        $this->order->load('customer','shipping_address','billing_address');
    }

    public function toArray(): array
    {
        return [
            'order' => $this->order,
            'customer' => $this->order->customer ?? [],
        ];
    }

    public function getActivityEventModel()
    {
        return $this->order;
    }
}
