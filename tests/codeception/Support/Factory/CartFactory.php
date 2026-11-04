<?php

namespace Tests\Support\Factory;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Cart;

class CartFactory
{
    private array $attributes = [];
    private array $cartItems = [];

    private static int $counter = 0;

    public function withAttributes(array $attributes): self
    {
        $clone = clone $this;
        $clone->attributes = array_merge($clone->attributes, $attributes);
        return $clone;
    }

    /**
     * Set cart_data items directly.
     */
    public function withCartItems(array $items): self
    {
        $clone = clone $this;
        $clone->cartItems = $items;
        return $clone;
    }

    protected function defaults(): array
    {
        self::$counter++;
        return [
            'cart_hash'     => md5('test_cart_' . self::$counter . '_' . microtime(true)),
            'stage'         => 'draft',
            'cart_group'    => 'global',
            'email'         => 'cart-' . self::$counter . '@example.com',
            'first_name'    => 'Cart',
            'last_name'     => 'User' . self::$counter,
            'ip_address'    => '127.0.0.1',
            'cart_data'     => [],
            'checkout_data' => [],
            'coupons'       => [],
            'utm_data'      => [],
        ];
    }

    public function create(array $overrides = []): Cart
    {
        $attributes = array_merge($this->defaults(), $this->attributes, $overrides);

        if (!empty($this->cartItems)) {
            $attributes['cart_data'] = $this->cartItems;
        }

        return Cart::query()->create($attributes);
    }

    /**
     * Generate a standard onetime digital cart item array (not persisted).
     */
    public static function makeCartItem(array $overrides = []): array
    {
        static $itemCounter = 0;
        $itemCounter++;

        $price = $overrides['unit_price'] ?? Helper::toCent(29.99);
        $quantity = $overrides['quantity'] ?? 1;
        $subtotal = $price * $quantity;

        return array_merge([
            'id'               => $itemCounter,
            'object_id'        => $itemCounter,
            'post_id'          => $itemCounter,
            'quantity'         => $quantity,
            'post_title'       => 'Test Product ' . $itemCounter,
            'title'            => 'Default Variant',
            'price'            => $price,
            'unit_price'       => $price,
            'line_total'       => $subtotal,
            'subtotal'         => $subtotal,
            'discount_total'   => 0,
            'coupon_discount'  => 0,
            'tax_amount'       => 0,
            'fulfillment_type' => 'digital',
            'variation_type'   => 'simple',
            'is_custom'        => false,
            'other_info'       => ['payment_type' => 'onetime'],
        ], $overrides);
    }

    /**
     * Generate a subscription cart item array (not persisted).
     */
    public static function makeSubscriptionItem(array $overrides = []): array
    {
        return self::makeCartItem(array_merge([
            'fulfillment_type' => 'digital',
            'other_info'       => [
                'payment_type'    => 'subscription',
                'repeat_interval' => 'monthly',
                'times'           => 0,
                'trial_days'      => 0,
                'signup_fee'      => 0,
            ],
        ], $overrides));
    }

    /**
     * Generate a physical cart item array (not persisted).
     */
    public static function makePhysicalItem(array $overrides = []): array
    {
        return self::makeCartItem(array_merge([
            'fulfillment_type' => 'physical',
        ], $overrides));
    }
}
