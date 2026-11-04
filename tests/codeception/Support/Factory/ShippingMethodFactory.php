<?php

namespace Tests\Support\Factory;

use FluentCart\App\Models\ShippingMethod;

class ShippingMethodFactory
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
            'title'      => 'Method ' . self::$counter,
            'type'       => 'flat_rate',
            'settings'   => [],
            'amount'     => 10,
            'is_enabled' => 1,
            'states'     => [],
            'meta'       => [],
            'order'      => 0,
        ];
    }

    public function create(array $overrides = []): ShippingMethod
    {
        $attributes = array_merge($this->defaults(), $this->attributes, $overrides);

        if (empty($attributes['zone_id'])) {
            throw new \RuntimeException('ShippingMethodFactory requires zone_id');
        }

        return ShippingMethod::query()->create($attributes);
    }
}
