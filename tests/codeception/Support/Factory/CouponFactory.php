<?php

namespace Tests\Support\Factory;

use FluentCart\App\Models\Coupon;

class CouponFactory
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
            'title'      => 'Test Coupon ' . self::$counter,
            'code'       => 'TEST' . self::$counter,
            'status'     => 'active',
            'type'       => 'fixed',
            'amount'     => 1000, // 10.00 in cents
            'stackable'  => 'yes',
            'priority'   => 1,
            'use_count'  => 0,
            'notes'      => '',
            'conditions' => [],
        ];
    }

    public function create(array $overrides = []): Coupon
    {
        $attributes = array_merge($this->defaults(), $this->attributes, $overrides);
        return Coupon::query()->create($attributes);
    }

    /**
     * Create a percentage coupon.
     */
    public function createPercentage(array $overrides = []): Coupon
    {
        return $this->create(array_merge([
            'type'   => 'percentage',
            'amount' => 10, // 10%
        ], $overrides));
    }

    /**
     * Create a non-stackable coupon.
     */
    public function createNonStackable(array $overrides = []): Coupon
    {
        return $this->create(array_merge([
            'stackable' => 'no',
        ], $overrides));
    }
}
