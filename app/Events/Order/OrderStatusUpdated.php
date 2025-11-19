<?php

namespace FluentCart\App\Events\Order;

use FluentCart\App\Events\EventDispatcher;
use FluentCart\App\Listeners;
use FluentCart\App\Models\Order;
use FluentCart\Framework\Support\Arr;

class OrderStatusUpdated extends EventDispatcher
{
    public string $hook = 'fluent_cart/order_status_updated';
    public bool $autoFireHook = false;

    protected array $listeners = [
//        Listeners\UpdateStock::class,
    ];

    /**
     * @var $order Order
     */
    public Order $order;
    public ?string $oldStatus;
    public ?string $newStatus;
    public ?bool $manageStock;
    public array $activity = [];

    public string $type;

    protected $willDispatch = true;

    public function __construct(Order $order, $oldStatus = null, $newStatus = null, $manageStock = true, $activity = [], $type = 'payment_status')
    {
        $this->order = $order;

        if ($oldStatus === $newStatus) {
            $this->willDispatch = false;
            $this->listeners = [];
            return;
        }

        $this->order->load('customer', 'shipping_address', 'billing_address');
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
        $this->manageStock = $manageStock;
        $this->activity = $activity;
        $this->type = $type;
    }

    public function toArray(): array
    {
        return [
            'order'       => $this->order,
            'old_status'  => $this->oldStatus,
            'new_status'  => $this->newStatus,
            'manageStock' => $this->manageStock,
            'activity'    => $this->activity,
        ];
    }

    public function getActivityEventModel()
    {
        return $this->order;
    }

    public function getEventInfoForActivity(): array
    {
        return [
            'title'   => Arr::get($this->activity, 'title', ''),
            'content' => Arr::get($this->activity, 'content', '')
        ];
    }

    public function afterDispatch()
    {
        if (!$this->willDispatch) {
            return;
        }

        if ($this->type === 'payment_status') {
            do_action('fluent_cart/payment_status_changed_to_'.$this->newStatus, $this->toArray());
            do_action('fluent_cart/payment_status_changed', $this->toArray());
        }

        if ($this->type === 'shipping_status') {
            do_action('fluent_cart/shipping_status_changed_to_' . $this->newStatus, $this->toArray());
            do_action('fluent_cart/shipping_status_changed', $this->toArray());
        }

        if ($this->type === 'order_status') {
            do_action('fluent_cart/order_status_changed_to_' . $this->newStatus, $this->toArray());
            do_action('fluent_cart/order_status_changed', $this->toArray());
        }
    }
}
