<?php

namespace Tests\Support\Factory;

use FluentCart\App\Models\CustomerAddresses;

class CustomerAddressFactory
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
            'type'       => 'billing',
            'status'     => 'active',
            'is_primary' => 0,
            'name'       => 'John Doe',
            'email'      => 'john@example.com',
            'phone'      => '555-0100',
            'address_1'  => '123 Main St',
            'address_2'  => '',
            'city'       => 'New York',
            'state'      => 'NY',
            'postcode'   => '10001',
            'country'    => 'US',
            'label'      => '',
        ];
    }

    /**
     * @param int $customerId Required customer ID to associate address with.
     */
    public function create(int $customerId, array $overrides = []): CustomerAddresses
    {
        $attributes = array_merge($this->defaults(), $this->attributes, $overrides);
        $attributes['customer_id'] = $customerId;
        return CustomerAddresses::query()->create($attributes);
    }
}
