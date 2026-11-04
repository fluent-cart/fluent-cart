<?php

namespace Tests\Support\Factory;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\OrderItem;

class OrderItemFactory
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
            'post_title'       => 'Test Product ' . self::$counter,
            'title'            => 'Default Variant',
            'quantity'         => 1,
            'unit_price'       => Helper::toCent(29.99),
            'cost'             => 0,
            'subtotal'         => Helper::toCent(29.99),
            'tax_amount'       => 0,
            'discount_total'   => 0,
            'refund_total'     => 0,
            'line_total'       => Helper::toCent(29.99),
            'payment_type'     => 'onetime',
            'fulfillment_type' => 'digital',
            'other_info'       => ['payment_type' => 'onetime'],
        ];
    }

    /**
     * Create an order item. Requires order_id.
     * Optionally accepts post_id (product) and object_id (variation).
     */
    public function create(int $orderId, array $overrides = []): OrderItem
    {
        $attributes = array_merge($this->defaults(), $this->attributes, $overrides);
        $attributes['order_id'] = $orderId;

        return OrderItem::query()->create($attributes);
    }
}
