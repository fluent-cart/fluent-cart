<?php

namespace Tests\Support\Factory;

use FluentCart\App\Models\OrderTaxRate;

class OrderTaxRateFactory
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
            'tax_rate_id'  => 0,
            'shipping_tax' => 0,
            'order_tax'    => 0,
            'total_tax'    => 0,
            'meta'         => [],
            'filed_at'     => null,
        ];
    }

    public function create(array $overrides = []): OrderTaxRate
    {
        $attributes = array_merge($this->defaults(), $this->attributes, $overrides);

        if (empty($attributes['order_id'])) {
            throw new \RuntimeException('OrderTaxRateFactory requires order_id');
        }

        return OrderTaxRate::query()->create($attributes);
    }
}
