<?php

namespace Tests\Support\Factory;

use FluentCart\App\Models\OrderAddress;

class OrderAddressFactory
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
            'type'      => 'billing',
            'name'      => 'John Doe',
            'address_1' => '123 Main St',
            'address_2' => '',
            'city'      => 'New York',
            'state'     => 'NY',
            'postcode'  => '10001',
            'country'   => 'US',
            'meta'      => [],
        ];
    }

    /**
     * Create an order address. Requires order_id.
     */
    public function create(int $orderId, array $overrides = []): OrderAddress
    {
        $attributes = array_merge($this->defaults(), $this->attributes, $overrides);
        $attributes['order_id'] = $orderId;

        return OrderAddress::query()->create($attributes);
    }
}
