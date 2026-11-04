<?php

namespace Tests\Support\Factory;

use FluentCart\App\Models\Activity;
use FluentCart\App\Models\Order;

class ActivityFactory
{
    private array $attributes = [];

    private static int $counter = 0;

    public function withAttributes(array $attributes): self
    {
        $clone = clone $this;
        $clone->attributes = array_merge($clone->attributes, $attributes);
        return $clone;
    }

    protected function defaults(): array
    {
        self::$counter++;
        return [
            'status'      => 'info',
            'log_type'    => 'activity',
            'module_type' => Order::class,
            'module_name' => 'order',
            'title'       => 'Activity ' . self::$counter,
            'content'     => 'Test activity content ' . self::$counter,
            'read_status' => 'unread',
            'created_by'  => 'FCT-BOT',
        ];
    }

    public function create(array $overrides = []): Activity
    {
        $attributes = array_merge($this->defaults(), $this->attributes, $overrides);
        return Activity::query()->create($attributes);
    }

    public function createForOrder(int $orderId, array $overrides = []): Activity
    {
        return $this->create(array_merge([
            'module_type' => Order::class,
            'module_name' => 'order',
            'module_id'   => $orderId,
        ], $overrides));
    }
}
