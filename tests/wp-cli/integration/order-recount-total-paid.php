<?php
/**
 * Order::recountTotalPaid() keeps total_paid gross, as every other writer does.
 */

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;

return [
    [
        'id'            => 'order-recount-total-paid-gross',
        'name'          => 'Order recount stores the charged total in total_paid without subtracting refunds',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            try {
                FcFixture::customer();
                $order = FcFixture::order([
                    'subtotal'     => 5000,
                    'tax_total'    => 0,
                    'total_amount' => 5000,
                ]);
                $orderId = (int) $order->id;
                Order::query()->where('id', $orderId)->update([
                    'total_paid'     => 5000,
                    'total_refund'   => 2000,
                    'payment_status' => Status::PAYMENT_PARTIALLY_REFUNDED,
                ]);
                $base = [
                    'order_id'            => $orderId,
                    'order_type'          => (string) $order->type,
                    'payment_method'      => 'stripe',
                    'payment_mode'        => 'test',
                    'payment_method_type' => '',
                    'currency'            => 'USD',
                    'rate'                => 1,
                ];
                $charge = OrderTransaction::query()->create(array_merge($base, [
                    'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
                    'status'           => Status::TRANSACTION_SUCCEEDED,
                    'total'            => 5000,
                    'meta'             => [],
                ]));
                $refund = OrderTransaction::query()->create(array_merge($base, [
                    'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
                    'status'           => Status::TRANSACTION_REFUNDED,
                    'total'            => 2000,
                    'meta'             => ['parent_id' => (int) $charge->id],
                ]));
                FcTest::assert((int) $charge->id > 0 && (int) $refund->id > 0, 'owned transaction IDs are captured');

                Order::query()->find($orderId)->recountTotalPaid();

                $stored = FcFixture::reloadOrder($orderId);
                FcTest::assertSame(
                    [5000, 2000],
                    [(int) $stored->total_paid, (int) $stored->total_refund],
                    'recount stores gross total_paid (the charge) and leaves total_refund unchanged'
                );
            } finally {
                FcFixture::cleanupAll();
            }
        },
    ],
];
