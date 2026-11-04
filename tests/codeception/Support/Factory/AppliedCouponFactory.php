<?php

namespace Tests\Support\Factory;

use FluentCart\App\Models\AppliedCoupon;

class AppliedCouponFactory
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
            'amount' => 500,
        ];
    }

    /**
     * Create an applied coupon. Requires order_id, coupon_id, and code.
     * coupon_id may be passed as an explicit null to model legacy rows
     * (the column is nullable in fct_applied_coupons) — only omitting
     * the key entirely is treated as a mistake.
     */
    public function create(array $overrides = []): AppliedCoupon
    {
        $attributes = array_merge($this->defaults(), $this->attributes, $overrides);

        if (empty($attributes['order_id'])) {
            throw new \RuntimeException('AppliedCouponFactory requires order_id');
        }
        if (!array_key_exists('coupon_id', $attributes)) {
            throw new \RuntimeException('AppliedCouponFactory requires coupon_id (pass an explicit null for a legacy row)');
        }
        if (empty($attributes['coupon_id']) && $attributes['coupon_id'] !== null) {
            throw new \RuntimeException('AppliedCouponFactory requires coupon_id');
        }
        if (empty($attributes['code'])) {
            throw new \RuntimeException('AppliedCouponFactory requires code');
        }

        return AppliedCoupon::query()->create($attributes);
    }
}
