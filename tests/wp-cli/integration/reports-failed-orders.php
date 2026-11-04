<?php
/**
 * Orders report failed orders: the count and the latest list for the range, including orders whose
 * order status is failed (the report defaults leave those out of every other figure).
 */

use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\OrderReportService;

// No store row uses the ISO test currency, so the report scoped to it reads only these fixtures.
$fcFailedCurrency = 'XTS';

return [
    [
        'id'            => 'reports-failed-orders',
        'name'          => 'Orders report counts and lists the failed orders of the range',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcFailedCurrency) {
            try {
                $foreign = (int) \FluentCart\App\App::db()->table('fct_orders')->where('currency', $fcFailedCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' orders already use currency ' . $fcFailedCurrency);
                }
                FcFixture::customer();
                $window = FcFixture::reportWindow();
                $start = strtotime($window['start']);
                $order = function (array $attributes, $hours) use ($fcFailedCurrency, $start) {
                    $at = gmdate('Y-m-d H:i:s', $start + $hours * HOUR_IN_SECONDS);

                    return FcFixture::reportOrder(array_merge([
                        'status'         => Status::ORDER_COMPLETED,
                        'payment_status' => Status::PAYMENT_PAID,
                        'currency'       => $fcFailedCurrency,
                        'type'           => Status::ORDER_TYPE_PAYMENT,
                        'subtotal'       => 1000,
                        'total_amount'   => 1000,
                        'total_paid'     => 1000,
                        'created_at'     => $at,
                        'updated_at'     => $at,
                    ], $attributes));
                };

                $order([], 1);
                $older = $order(['payment_status' => Status::PAYMENT_FAILED, 'status' => Status::ORDER_FAILED, 'total_paid' => 0], 2);
                $newer = $order(['payment_status' => Status::PAYMENT_FAILED, 'total_paid' => 0, 'total_amount' => 2500], 3);
                $order(['payment_status' => Status::PAYMENT_FAILED, 'total_paid' => 0, 'created_at' => '1999-01-01 00:00:00'], 0);

                $failed = OrderReportService::make()->getFailedOrders([
                    'startDate'   => new DateTime($window['start']),
                    'endDate'     => new DateTime($window['end']),
                    'currency'    => $fcFailedCurrency,
                    'orderStatus' => [Status::ORDER_ON_HOLD, Status::ORDER_FAILED],
                ]);

                FcTest::assertSame(2, $failed['count'], 'Failed orders in the range, an order with status failed included');
                FcTest::assertSame([[(int) $newer->id, 25.0], [(int) $older->id, 10.0]], array_map(function ($row) {
                    return [$row['order_id'], $row['total_amount']];
                }, $failed['orders']), 'Latest first, with the order total');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
