<?php

namespace Tests\Support\Factory;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;

class OrderTransactionFactory
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
            'order_type'          => Status::ORDER_TYPE_PAYMENT,
            'payment_method'      => 'offline_payment',
            'payment_mode'        => Status::ORDER_MODE_LIVE,
            'payment_method_type' => 'offline_payment',
            'currency'            => 'USD',
            'transaction_type'    => Status::TRANSACTION_TYPE_CHARGE,
            'status'              => Status::TRANSACTION_PENDING,
            'total'               => Helper::toCent(100),
            'meta'                => [],
        ];
    }

    /**
     * Create a transaction. Requires order_id.
     */
    public function create(int $orderId, array $overrides = []): OrderTransaction
    {
        $attributes = array_merge($this->defaults(), $this->attributes, $overrides);
        $attributes['order_id'] = $orderId;

        return OrderTransaction::query()->create($attributes);
    }
}
