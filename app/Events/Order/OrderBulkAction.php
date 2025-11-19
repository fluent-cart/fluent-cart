<?php

namespace FluentCart\App\Events\Order;

use FluentCart\App\Events\EventDispatcher;
use FluentCart\App\Listeners;

class OrderBulkAction extends EventDispatcher
{
    public string $hook = 'fluent_cart/order_bulk_action';
    protected array $listeners = [
        Listeners\Order\OrderBulkAction::class,
    ];

    /**
     * @var $customerIds array
     */
    public $customerIds;

    public function __construct($customerIds)
    {
        $this->customerIds = $customerIds;
    }

    public function toArray(): array
    {
        return [
            'customerIds' => $this->customerIds,
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