<?php

namespace FluentCart\App\Events\Order;

use FluentCart\App\Events\EventDispatcher;
use FluentCart\App\Listeners;
use FluentCart\App\Models\Order;

class OrderDeleting extends EventDispatcher
{
    public string $hook = 'fluent_cart/order_before_delete';

    protected array $listeners = [
        Listeners\Order\OrderDeleting::class
    ];

    public Order $order;

    public array $connectedOrderIds;

    public bool $isTestMode;

    public string $type;

    public function __construct(Order $order, $connectedOrderIds = [], bool $isTestMode = false, string $type = '')
    {
        $this->order = $order;
        $this->connectedOrderIds = $connectedOrderIds ?? [];
        $this->isTestMode = $isTestMode;
        $this->type = $type;
    }

    public function toArray(): array
    {
        return [
            'order'               => $this->order,
            'connected_order_ids' => $this->connectedOrderIds,
            'is_test_mode'        => $this->isTestMode,
            'type'                => $this->type
        ];
    }

    public function getActivityEventModel()
    {
        return $this->order;
    }

    public function shouldCreateActivity(): bool
    {
        return false;
    }
}
