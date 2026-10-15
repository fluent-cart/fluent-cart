<?php
/**
 * Exact-value coverage for the Order chart query and its bucket range.
 */

return [
    [
        'id'            => 'reports-order-chart-query',
        'name'          => 'Order chart averages items per bucket, keeps orders with older item rows, and buckets every month in range',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            try {
                $data = FcReportFixture::createDataset();
                $window = $data['window'];

                $base = [
                    'status'               => 'completed',
                    'payment_status'       => 'paid',
                    'payment_method'       => 'phase7-card',
                    'payment_method_title' => 'Phase 7 Card',
                    'currency'             => $window['currency'],
                    'subtotal'             => 5000,
                    'total_amount'         => 5000,
                    'total_paid'           => 5000,
                    'total_refund'         => 0,
                    'type'                 => 'payment',
                    'mode'                 => 'test',
                ];
                // Two orders alone in 2001-02-03 (1 + 3 items); in 2001-02-05 one
                // order whose item row predates the window and one with no item row.
                $rows = [
                    ['2001-02-03 10:00:00', 1, '2001-02-03 10:00:00'],
                    ['2001-02-03 11:00:00', 3, '2001-02-03 11:00:00'],
                    ['2001-02-05 10:00:00', 4, '2001-01-31 10:00:00'],
                    ['2001-02-05 11:00:00', null, null],
                ];
                foreach ($rows as $row) {
                    $order = FcFixture::reportOrder(array_merge($base, [
                        'created_at'   => $row[0],
                        'completed_at' => $row[0],
                        'updated_at'   => $row[0],
                    ]));
                    if ($row[1] === null) {
                        continue;
                    }
                    FcFixture::reportOrderItem((int) $order->id, [
                        'quantity'   => $row[1],
                        'unit_price' => 5000,
                        'subtotal'   => 5000,
                        'line_total' => 5000,
                        'created_at' => $row[2],
                    ]);
                }

                $service = \FluentCart\App\Services\Report\OrderReportService::make();
                $daily = $service->getOrderLineChart(FcReportFixture::params('daily'));

                $day = FcReportFixture::groupRow($daily['chartData'], '2001-02-03');
                FcTest::assertSame(
                    2.0,
                    (float) $day['average_order_items_count'],
                    'GET /reports/order-chart 2001-02-03 bucket averages (1 + 3) items over 2 Orders'
                );

                $day = FcReportFixture::groupRow($daily['chartData'], '2001-02-05');
                FcTest::assertSame(
                    [3, 7],
                    [(int) $day['order_count'], (int) $day['total_item_count']],
                    'GET /reports/order-chart 2001-02-05 bucket counts the Order whose item row predates the window and the itemless Order (3 Orders, 3 + 4 items)'
                );

                $dateClass = 'FluentCart\\App\\Services\\DateTime\\DateTime';
                $params = FcReportFixture::params('monthly');
                $params['startDate'] = new $dateClass('2001-01-20 00:00:00');
                $params['endDate'] = new $dateClass('2001-03-10 23:59:59');
                $monthly = $service->getOrderLineChart($params);
                FcTest::assertSame(
                    ['2001-01', '2001-02', '2001-03'],
                    array_map(function ($row) {
                        return (string) ((array) $row)['group'];
                    }, $monthly['chartData']),
                    'GET /reports/order-chart monthly 2001-01-20..2001-03-10 buckets Jan, Feb and Mar'
                );
            } finally {
                FcFixture::cleanupAll();
            }
        },
    ],
];
