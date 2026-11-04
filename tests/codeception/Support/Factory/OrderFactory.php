<?php

namespace Tests\Support\Factory;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\OrderAddress;

class OrderFactory
{
    private array $attributes = [];
    private array $items = [];
    private bool $withTransaction = false;
    private array $transactionOverrides = [];
    private bool $withAddresses = false;

    private static int $counter = 0;

    public function withAttributes(array $attributes): self
    {
        $clone = clone $this;
        $clone->attributes = array_merge($clone->attributes, $attributes);
        return $clone;
    }

    public function withItems(array $items): self
    {
        $clone = clone $this;
        $clone->items = $items;
        return $clone;
    }

    public function withTransaction(array $overrides = []): self
    {
        $clone = clone $this;
        $clone->withTransaction = true;
        $clone->transactionOverrides = $overrides;
        return $clone;
    }

    public function withAddresses(): self
    {
        $clone = clone $this;
        $clone->withAddresses = true;
        return $clone;
    }

    protected function defaults(): array
    {
        self::$counter++;
        return [
            'status'                => Status::ORDER_ON_HOLD,
            'payment_status'        => Status::PAYMENT_PENDING,
            'type'                  => Status::ORDER_TYPE_PAYMENT,
            'fulfillment_type'      => 'digital',
            'currency'              => 'USD',
            'subtotal'              => Helper::toCent(100),
            'total_amount'          => Helper::toCent(100),
            'total_paid'            => 0,
            'total_refund'          => 0,
            'tax_total'             => 0,
            'shipping_total'        => 0,
            'fee_total'             => 0,
            'discount_tax'          => 0,
            'manual_discount_total' => 0,
            'coupon_discount_total' => 0,
            'shipping_tax'          => 0,
            'mode'                  => Status::ORDER_MODE_LIVE,
            'shipping_status'       => Status::SHIPPING_UNSHIPPED,
            'payment_method'        => 'offline_payment',
            'ip_address'            => '127.0.0.1',
            'config'                => [],
        ];
    }

    /**
     * Create an order. Requires customer_id in overrides or attributes.
     */
    public function create(array $overrides = []): Order
    {
        $attributes = array_merge($this->defaults(), $this->attributes, $overrides);

        if (empty($attributes['customer_id'])) {
            throw new \RuntimeException('OrderFactory requires customer_id');
        }

        $order = Order::query()->create($attributes);

        if (!empty($this->items)) {
            foreach ($this->items as $itemData) {
                $itemData['order_id'] = $order->id;
                OrderItem::query()->create($itemData);
            }
        }

        if ($this->withTransaction) {
            $txnDefaults = [
                'order_id'            => $order->id,
                'order_type'          => $attributes['type'],
                'payment_method'      => $attributes['payment_method'],
                'payment_mode'        => $attributes['mode'],
                'payment_method_type' => $attributes['payment_method'],
                'currency'            => $attributes['currency'],
                'transaction_type'    => Status::TRANSACTION_TYPE_CHARGE,
                'status'              => Status::TRANSACTION_PENDING,
                'total'               => $attributes['total_amount'],
            ];
            OrderTransaction::query()->create(
                array_merge($txnDefaults, $this->transactionOverrides)
            );
        }

        if ($this->withAddresses) {
            OrderAddress::query()->create([
                'order_id'  => $order->id,
                'type'      => 'billing',
                'name'      => 'Test User',
                'address_1' => '123 Main St',
                'city'      => 'New York',
                'state'     => 'NY',
                'postcode'  => '10001',
                'country'   => 'US',
            ]);
            OrderAddress::query()->create([
                'order_id'  => $order->id,
                'type'      => 'shipping',
                'name'      => 'Test User',
                'address_1' => '456 Oak Ave',
                'city'      => 'Los Angeles',
                'state'     => 'CA',
                'postcode'  => '90001',
                'country'   => 'US',
            ]);
        }

        return Order::query()->find($order->id);
    }
}
