<?php

namespace Tests\Support\Factory;

use FluentCart\App\Models\ShippingZone;

class ShippingZoneFactory
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
            'name'   => 'Zone ' . self::$counter,
            'region' => 'US',
            'meta'   => null,
            'order'  => 0,
        ];
    }

    public function create(array $overrides = []): ShippingZone
    {
        $attributes = array_merge($this->defaults(), $this->attributes, $overrides);
        return ShippingZone::query()->create($attributes);
    }
}
