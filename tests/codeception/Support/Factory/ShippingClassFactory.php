<?php

namespace Tests\Support\Factory;

use FluentCart\App\Models\ShippingClass;

class ShippingClassFactory
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
            'name'     => 'Class ' . self::$counter,
            'cost'     => 5.00,
            'type'     => 'fixed',
            'per_item' => 0,
        ];
    }

    public function create(array $overrides = []): ShippingClass
    {
        $attributes = array_merge($this->defaults(), $this->attributes, $overrides);
        return ShippingClass::query()->create($attributes);
    }
}
