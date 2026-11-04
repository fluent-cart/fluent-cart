<?php

namespace Tests\Support\Factory;

use FluentCart\App\Models\TaxRate;

class TaxRateFactory
{
    private array $attributes = [];

    public function withAttributes(array $attributes): self
    {
        $clone = clone $this;
        $clone->attributes = array_merge($clone->attributes, $attributes);
        return $clone;
    }

    protected function defaults(): array
    {
        return [
            'country'      => 'US',
            'state'        => '',
            'postcode'     => '',
            'city'         => '',
            'rate'         => '10',
            'name'         => 'Sales Tax',
            'group'        => '',
            'priority'     => 1,
            'is_compound'  => 0,
            'for_shipping' => null,
            'for_order'    => 0,
        ];
    }

    public function create(array $overrides = []): TaxRate
    {
        $attributes = array_merge($this->defaults(), $this->attributes, $overrides);

        if (empty($attributes['class_id'])) {
            throw new \RuntimeException('TaxRateFactory requires class_id');
        }

        return TaxRate::query()->create($attributes);
    }
}
