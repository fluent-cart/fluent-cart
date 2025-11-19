<?php

namespace FluentCart\App\Events\Order;

use FluentCart\App\Events\EventDispatcher;
use FluentCart\App\Listeners;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;

class OrderDeleted extends EventDispatcher
{
    public string $hook = 'fluent_cart/order_deleted';

    protected array $listeners = [
        // Listeners\UpdateStock::class,
        Listeners\Order\OrderDeleted::class
    ];

    /**
     * @var $order Order
     */
    public Order $order;

    /**
     * @var $customer Customer|null
     */

    public array $connectedOrderIds;

    public function __construct(Order $order, $connectedOrderIds = [])
    {
        $this->order = $order;
        $this->connectedOrderIds = $connectedOrderIds ?? [];
        $this->order->load('customer','shipping_address','billing_address');
    }

    public function toArray(): array
    {
        return [
            'order' => $this->order,
            'customer' => $this->order->customer ?? [],
            'connected_order_ids' => $this->connectedOrderIds
        ];
    }

    public function getActivityEventModel()
    {
        return $this->order;
    }
}
