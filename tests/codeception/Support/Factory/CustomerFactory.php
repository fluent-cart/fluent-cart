<?php

namespace Tests\Support\Factory;

use FluentCart\App\Models\Customer;

class CustomerFactory
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
            'first_name' => 'Test',
            'last_name'  => 'Customer' . self::$counter,
            'email'      => 'test-customer-' . self::$counter . '@example.com',
            'status'     => 'active',
            'country'    => 'US',
            'city'       => 'New York',
            'state'      => 'NY',
            'postcode'   => '10001',
        ];
    }

    public function create(array $overrides = []): Customer
    {
        $attributes = array_merge($this->defaults(), $this->attributes, $overrides);
        return Customer::query()->create($attributes);
    }

    /**
     * Create a customer with a linked WP user.
     */
    public function createWithUser(array $overrides = [], array $userArgs = []): Customer
    {
        $attributes = array_merge($this->defaults(), $this->attributes, $overrides);

        $userId = \Tests\Support\Factory\WPUserFactory::create(array_merge([
            'user_email' => $attributes['email'],
            'first_name' => $attributes['first_name'],
            'last_name'  => $attributes['last_name'],
        ], $userArgs));

        $attributes['user_id'] = $userId;
        return Customer::query()->create($attributes);
    }

    /**
     * Create the FluentCart customer owned by an existing WordPress user.
     */
    public function createForUser(int $userId, array $overrides = []): Customer
    {
        $user = get_user_by('ID', $userId);
        if (!$user) {
            throw new \RuntimeException('CustomerFactory requires a valid WordPress user ID');
        }

        return $this->create(array_merge([
            'user_id' => $userId,
            'email'   => $user->user_email,
        ], $overrides));
    }
}
