<?php

namespace FluentCart\App\Events\Order;

use FluentCart\App\Events\EventDispatcher;
use FluentCart\App\Listeners;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\Framework\Support\Str;
use ReflectionClass;

class OrderCreated extends EventDispatcher
{
    public string $hook = 'fluent_cart/order_created';
    protected array $listeners = [
        Listeners\Order\OrderCreated::class,
//        Listeners\UpdateStock::class,
    ];

    /**
     * @var Order $order
     */
    public Order $order;

    /**
     * @var Order|null $prevOrder
     */
    public ?Order $prevOrder;

    /**
     * @var Customer|null $customer
     */
    public ?Customer $customer;

    /**
     * @var OrderTransaction|null $transaction
     */
    public ?OrderTransaction $transaction;

    public function __construct($order, $prevOrder = null, $customer = null, $transaction = null)
    {
        $this->order = $order;
        $this->prevOrder = $prevOrder;
        $this->order->load('customer','shipping_address','billing_address');
        $this->customer = $customer;
        $this->transaction = $transaction;
    }


    public function toArray(): array
    {
        return [
            'order' => $this->order,
            'prev_order' => $this->prevOrder,
            'customer' => $this->customer ?? [],
            'transaction' => $this->transaction ?? []
        ];
    }

    public function getActivityEventModel()
    {
        return $this->order;
    }

}
