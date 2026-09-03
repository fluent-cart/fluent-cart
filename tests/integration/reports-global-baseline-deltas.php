<?php
/**
 * Phase 7 exact-value deltas for inert global/current/deprecated readers.
 *
 * Each global case captures a stable read-only baseline immediately before its
 * exact owned fixture. Any concurrent real write fails the numeric delta; no
 * existing row is selected, updated, or removed to compensate.
 */

$overviewTotals = function () {
    $result = FcTest::rest('GET', 'reports/overview', [
        'params' => ['currency' => 'USD'],
    ]);
    FcTest::assertHealthy($result, 'GET /reports/overview');

    return [
        'gross' => (int) $result['data']['data']['gross_summary']['total'],
        'net'   => (int) $result['data']['data']['net_summary']['total'],
    ];
};

$countryCounts = function () {
    $result = \FluentCart\App\Services\Report\DashBoardReportService::make()
        ->getCountryHeatMap();
    $counts = [];
    foreach ($result['countryHeatMap'] as $row) {
        $counts[(string) $row['name']] = (int) $row['value'];
    }

    return $counts;
};

return [
    [
        'id'            => 'reports-overview-baseline-delta',
        'name'          => 'Overview global aggregates change by the exact owned current Order delta',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($overviewTotals) {
            try {
                $before = $overviewTotals();
                $fixture = FcReportFixture::createCurrentOrderDataset();
                $after = $overviewTotals();

                FcTest::assertSame(
                    43210,
                    $after['gross'] - $before['gross'],
                    'GET /reports/overview gross summary changes by exact owned paid 43210'
                );
                FcTest::assertSame(
                    42990,
                    $after['net'] - $before['net'],
                    'GET /reports/overview net summary changes by exact owned net 42990'
                );
                FcTest::assertSame(
                    (int) $fixture['order']->id,
                    (int) FcFixture::reloadOrder((int) $fixture['order']->id)->id,
                    'overview delta remains tied to the exact owned current Order ID'
                );
            } finally {
                FcFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-quick-order-stats-baseline-delta',
        'name'          => 'Quick Order Stats counts a normal paid current Order',
        'kind'          => 'behavior',
        'known_failure' => true,
        'run'           => function () {
            try {
                $beforeResult = FcTest::rest(
                    'GET',
                    'reports/quick-order-stats',
                    ['day_range' => '-0 days']
                );
                FcTest::assertHealthy($beforeResult, 'GET /reports/quick-order-stats baseline');
                $fixture = FcReportFixture::createCurrentOrderDataset();
                $afterResult = FcTest::rest(
                    'GET',
                    'reports/quick-order-stats',
                    ['day_range' => '-0 days']
                );
                FcTest::assertHealthy($afterResult, 'GET /reports/quick-order-stats fixture');

                $before = $beforeResult['data']['stats'];
                $after = $afterResult['data']['stats'];
                $actual = [];
                foreach (array_keys($after) as $metric) {
                    $actual[$metric] = (int) $after[$metric]['current_count']
                        - (int) $before[$metric]['current_count'];
                }
                $correct = [
                    'total_orders'           => 1,
                    'paid_orders'            => 1,
                    'total_paid_order_items' => 1,
                    'total_paid_amounts'     => 40000,
                ];
                $knownDefect = [
                    'total_orders'           => 1,
                    'paid_orders'            => 0,
                    'total_paid_order_items' => 0,
                    'total_paid_amounts'     => 0,
                ];

                if ($actual === $correct) {
                    FcTest::fail(
                        'KNOWN-FAILURE unexpectedly passed; reclassify '
                        . 'GET /reports/quick-order-stats as value-covered.'
                    );
                } elseif ($actual !== $knownDefect) {
                    FcTest::fail(
                        'GET /reports/quick-order-stats changed outside the documented defect.'
                        . "\n  expected correct: " . wp_json_encode($correct)
                        . "\n  documented defect: " . wp_json_encode($knownDefect)
                        . "\n  actual: " . wp_json_encode($actual)
                    );
                } else {
                    FcTest::skip(
                        'KNOWN-FAILURE — OrdersReport.php:30-40 filters Order.payment_status '
                        . 'with transaction statuses; exact paid fixture delta='
                        . wp_json_encode($actual)
                    );
                }
            } finally {
                FcFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-deprecated-overview-filtered',
        'name'          => 'Deprecated Report Overview returns exact filtered owned aggregates',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            try {
                FcReportFixture::createDataset();
                $window = FcFixture::reportWindow();
                $result = FcTest::rest('GET', 'reports/report-overview', [
                    'params' => [
                        'created_at' => [
                            'column'   => 'created_at',
                            'operator' => 'between',
                            'value'    => [$window['start'], $window['end']],
                        ],
                        'payment_status' => [
                            'column'   => 'payment_status',
                            'operator' => 'in',
                            'value'    => ['paid'],
                        ],
                    ],
                ]);
                FcTest::assertHealthy($result, 'GET /reports/report-overview');
                $summary = $result['data']['data'];

                FcTest::assertSame(
                    30000,
                    (int) $summary->total_sales,
                    'GET /reports/report-overview returns exact total_sales 30000'
                );
                FcTest::assertSame(
                    28855,
                    (int) $summary->net_sales,
                    'GET /reports/report-overview returns exact net_sales 28855'
                );
                FcTest::assertSame(
                    700,
                    (int) $summary->total_shipping_tax,
                    'GET /reports/report-overview returns exact shipping total 700'
                );
                FcTest::assertSame(
                    15000.0,
                    (float) $summary->average_order_value,
                    'GET /reports/report-overview returns exact average 15000'
                );
                FcTest::assertSame(
                    2,
                    (int) $summary->customer_order_count,
                    'GET /reports/report-overview counts exactly two filtered Orders'
                );

                $payment = $result['data']['orders_by_payment_method']->first();
                FcTest::assertSame(
                    'phase7-card',
                    (string) $payment->payment_method,
                    'GET /reports/report-overview preserves the exact payment-method group'
                );
                FcTest::assertSame(
                    2,
                    (int) $payment->order_count,
                    'GET /reports/report-overview returns exact grouped Order count'
                );
                FcTest::assertSame(
                    30000,
                    (int) $payment->transactions,
                    'GET /reports/report-overview returns exact grouped transactions 30000'
                );
            } finally {
                FcFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-country-heat-map-baseline-delta',
        'name'          => 'Country Heat Map changes only by exact owned billing-address deltas',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($countryCounts) {
            try {
                $before = $countryCounts();
                FcReportFixture::createDataset();
                $after = $countryCounts();

                foreach (['Bangladesh' => 2, 'United States (US)' => 2] as $country => $delta) {
                    FcTest::assertSame(
                        $delta,
                        ($after[$country] ?? 0) - ($before[$country] ?? 0),
                        'GET /reports/country-heat-map ' . $country
                        . ' changes by exact owned billing-address delta ' . $delta
                    );
                }
                FcTest::assertSame(
                    0,
                    ($after['Antarctica'] ?? 0) - ($before['Antarctica'] ?? 0),
                    'GET /reports/country-heat-map excludes unrelated countries from historic fixture'
                );
            } finally {
                FcFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-dashboard-summary-baseline-delta',
        'name'          => 'Dashboard Summary changes only by the exact owned active Coupon delta',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            try {
                $before = \FluentCart\App\Services\Report\DashBoardReportService::getSummary();
                FcFixture::coupon([
                    'status'   => 'active',
                    'end_date' => null,
                ]);
                $after = \FluentCart\App\Services\Report\DashBoardReportService::getSummary();
                $before = $before['summaryData'];
                $after = $after['summaryData'];

                FcTest::assertSame(
                    1,
                    (int) $after['active_coupons'] - (int) $before['active_coupons'],
                    'GET /reports/get-dashboard-summary active_coupons changes by exact owned delta 1'
                );
                foreach (['expired_coupons', 'total_products', 'draft_products'] as $metric) {
                    FcTest::assertSame(
                        (int) $before[$metric],
                        (int) $after[$metric],
                        'GET /reports/get-dashboard-summary leaves exact baseline ' . $metric
                        . ' unchanged'
                    );
                }
            } finally {
                FcFixture::cleanupAll();
            }
        },
    ],
];
