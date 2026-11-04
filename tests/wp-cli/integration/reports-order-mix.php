<?php
/**
 * Orders report: the order mix (type, status, discounted, physical orders by shipping status) counts the same orders
 * as the Orders card, and the card row adds refunded orders and an orders-per-day / per-month rate.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\OrderReportService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcMixCurrency = 'XTS';

return [
    [
        'id'            => 'reports-order-mix',
        'name'          => 'Order mix splits the Orders card by type, status, discount and shipping; the card row adds refunds and a rate',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC', // fixtures are UTC days; reports-site-timezone covers other zones
        'run'           => function () use ($fcMixCurrency) {
            try {
                $foreign = (int) App::db()->table('fct_orders')->where('currency', $fcMixCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' orders already use currency ' . $fcMixCurrency);
                }
                FcFixture::customer();
                $order = function (array $attributes) use ($fcMixCurrency) {
                    $cents = $attributes['total_paid'] ?? 1000;

                    return FcFixture::reportOrder(array_merge([
                        'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'currency' => $fcMixCurrency,
                        'type' => Status::ORDER_TYPE_PAYMENT, 'fulfillment_type' => 'digital', 'shipping_status' => '',
                        'subtotal' => $cents, 'total_amount' => $cents, 'total_paid' => $cents, 'total_refund' => 0,
                        'created_at' => '2025-05-03 10:00:00', 'updated_at' => '2025-05-03 10:00:00',
                    ], $attributes));
                };
                $order(['coupon_discount_total' => 200]);
                $order(['type' => Status::ORDER_TYPE_SUBSCRIPTION, 'status' => Status::ORDER_PROCESSING, 'total_paid' => 2000]);
                $order(['type' => Status::ORDER_TYPE_RENEWAL, 'payment_status' => Status::PAYMENT_REFUNDED, 'total_paid' => 500, 'total_refund' => 500]);
                $order(['status' => Status::ORDER_ON_HOLD, 'fulfillment_type' => 'physical', 'shipping_status' => Status::SHIPPING_UNSHIPPED]);
                $order(['fulfillment_type' => 'physical', 'shipping_status' => Status::SHIPPING_SHIPPED]);
                // Unpaid: not in the Orders card, so not in the mix either
                $order(['payment_status' => Status::PAYMENT_PENDING, 'status' => Status::ORDER_PROCESSING]);

                $params = [
                    'startDate' => new DateTime('2025-05-01 00:00:00'), 'endDate' => new DateTime('2025-05-10 23:59:59'),
                    'currency' => $fcMixCurrency, 'paymentStatus' => Status::getReportStatuses(), 'variationIds' => [],
                    'groupKey' => 'daily',
                ];
                $service = OrderReportService::make();
                $mix = $service->getOrderMix($params);
                $counts = function ($rows) {
                    return array_column($rows, 'orders', 'key');
                };

                FcTest::assertSame(5, $mix['total'], 'The mix counts the five paid orders');
                FcTest::assertSame(
                    [Status::ORDER_TYPE_PAYMENT => 3, Status::ORDER_TYPE_RENEWAL => 1, Status::ORDER_TYPE_SUBSCRIPTION => 1],
                    $counts($mix['types']),
                    'Order types, most orders first'
                );
                FcTest::assertSame(60.0, (float) array_column($mix['types'], 'share', 'key')[Status::ORDER_TYPE_PAYMENT], 'A type share is a percent of the orders');
                FcTest::assertSame(
                    [Status::ORDER_COMPLETED => 3, Status::ORDER_ON_HOLD => 1, Status::ORDER_PROCESSING => 1],
                    $counts($mix['statuses']),
                    'Order statuses, most orders first'
                );
                FcTest::assertSame(['orders' => 1, 'share' => 20.0], ['orders' => $mix['discounted']['orders'], 'share' => (float) $mix['discounted']['share']],
                    'Discounted orders');
                FcTest::assertSame(
                    [2, [Status::SHIPPING_SHIPPED => 1, Status::SHIPPING_UNSHIPPED => 1]],
                    [$mix['fulfillment']['orders'], $counts($mix['fulfillment']['statuses'])],
                    'Physical orders by shipping status'
                );

                $summary = $service->getOrderLineChart($params)['summary'];
                FcTest::assertSame([5, 1, 20.0, 0.5, 5.0], [
                    (int) $summary['order_count'], (int) $summary['refunded_orders'], round((float) $summary['refund_rate'], 2),
                    round((float) $summary['orders_per_day'], 2), round((float) $summary['orders_per_month'], 2),
                ], 'Card row: orders, refunded orders, refund rate, orders per day over 10 days, orders per month');

                $route = FcTest::rest('GET', 'reports/order-mix', ['params' => [
                    'startDate' => '2025-05-01', 'endDate' => '2025-05-10', 'currency' => $fcMixCurrency,
                ]]);
                FcTest::assertSame([200, 5], [$route['status'], (int) ($route['data']['total'] ?? -1)], 'GET /reports/order-mix');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
