<?php
/**
 * Phase 7 exact-value coverage for date-filtered dashboard aggregates.
 */

return [
    [
        'id'            => 'reports-dashboard-range-aggregates',
        'name'          => 'Dashboard range aggregates return exact current and zero comparison values',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            try {
                FcReportFixture::createDataset();
                $dateClass = 'FluentCart\\App\\Services\\DateTime\\DateTime';
                $start = new $dateClass('2001-02-03 00:00:00');
                $end = new $dateClass('2001-02-05 23:59:59');
                $previousStart = new $dateClass('2001-01-31 00:00:00');
                $previousEnd = new $dateClass('2001-02-02 23:59:59');

                $service = \FluentCart\App\Services\Report\DashBoardReportService::make([
                    'currency' => 'USD',
                ]);
                $stats = $service->getDashBoardStats(
                    $start,
                    $end,
                    $previousStart,
                    $previousEnd
                )['dashBoardStats'];
                FcTest::assertSame(
                    3,
                    (int) $stats['total_orders']['current_count'],
                    'GET /reports/dashboard-stats counts exact paid plus pending in-window Orders'
                );
                FcTest::assertSame(
                    2,
                    (int) $stats['paid_orders']['current_count'],
                    'GET /reports/dashboard-stats counts exactly two paid in-window Orders'
                );
                FcTest::assertSame(
                    2,
                    (int) $stats['total_paid_order_items']['current_count'],
                    'GET /reports/dashboard-stats returns exact paid OrderItem row count'
                );
                FcTest::assertSame(
                    20000,
                    (int) $stats['total_paid_amounts']['current_count'],
                    'GET /reports/dashboard-stats returns exact paid cents 20000'
                );
                foreach ($stats as $metric) {
                    FcTest::assertSame(
                        0,
                        (int) $metric['compare_count'],
                        'GET /reports/dashboard-stats preserves exact zero comparison value'
                    );
                }

                foreach ([
                    'daily'   => ['2001-02-04', '2001-02-05'],
                    'monthly' => ['2001-02'],
                    'yearly'  => ['2001'],
                ] as $groupKey => $nonzeroGroups) {
                    $chart = $service->getSalesGrowthChart(
                        FcReportFixture::params($groupKey)
                    );
                    $grossOrders = 0;
                    $netRevenue = 0.0;
                    foreach ($chart as $row) {
                        if (in_array((string) $row['group'], $nonzeroGroups, true)) {
                            $grossOrders += (int) $row['orders'];
                            $netRevenue += (float) $row['net_revenue'];
                        }
                    }
                    FcTest::assertSame(
                        2,
                        $grossOrders,
                        'GET /reports/sales-growth-chart ' . $groupKey
                        . ' variation counts exact paid Orders'
                    );
                    FcTest::assertSame(
                        195.0,
                        $netRevenue,
                        'GET /reports/sales-growth-chart ' . $groupKey
                        . ' variation returns exact net 195.00'
                    );
                }
            } finally {
                FcFixture::cleanupAll();
            }
        },
    ],
];
