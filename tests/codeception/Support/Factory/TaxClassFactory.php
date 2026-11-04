<?php

namespace Tests\Support\Factory;

use FluentCart\App\Models\TaxClass;

class TaxClassFactory
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
            'title'       => 'Standard Tax ' . self::$counter,
            'description' => 'Default standard tax class',
            'meta'        => [
                'priority'   => 10,
                'categories' => [],
            ],
        ];
    }

    public function create(array $overrides = []): TaxClass
    {
        $attributes = array_merge($this->defaults(), $this->attributes, $overrides);
        return TaxClass::query()->create($attributes);
    }
}
