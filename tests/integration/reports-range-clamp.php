<?php
/**
 * Report ranges that end in the future stop at today.
 */

$fcRangeClampParams = function ($startDate, $endDate) {
    return [
        'startDate'     => $startDate,
        'endDate'       => $endDate,
        'groupKey'      => 'monthly',
        'currency'      => 'USD',
        'paymentStatus' => ['paid'],
        'orderStatus'   => ['on-hold', 'failed'],
        'orderTypes'    => null,
        'variationIds'  => [],
        'comparePeriod' => null,
    ];
};

$fcRangeClampDate = function ($time = 'now') {
    return new \FluentCart\App\Services\DateTime\DateTime($time);
};

$fcRangeClampFutureMonths = function (array $months, $currentMonth) {
    return array_values(array_filter($months, function ($month) use ($currentMonth) {
        return $month > $currentMonth;
    }));
};

return [
    [
        'id'            => 'reports-range-clamp-order-chart-buckets',
        'name'          => 'Order chart range ending next month excludes a future-dated order and has no bucket after the current month',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcRangeClampParams, $fcRangeClampDate, $fcRangeClampFutureMonths) {
            try {
                $currentMonth = $fcRangeClampDate()->format('Y-m');
                $futureAt = $fcRangeClampDate('first day of next month')->setTime(12, 0, 0)->format('Y-m-d H:i:s');

                FcFixture::customer();
                $order = FcFixture::reportOrder([
                    'status'         => 'completed',
                    'payment_status' => 'paid',
                    'currency'       => 'USD',
                    'subtotal'       => 5000,
                    'total_amount'   => 5000,
                    'total_paid'     => 5000,
                    'total_refund'   => 0,
                    'type'           => 'payment',
                    'mode'           => 'test',
                    'created_at'     => $futureAt,
                    'completed_at'   => $futureAt,
                    'updated_at'     => $futureAt,
                ]);
                FcFixture::reportOrderItem($order->id, ['created_at' => $futureAt]);

                $params = $fcRangeClampParams(
                    $fcRangeClampDate('first day of January this year')->startOfDay(),
                    $fcRangeClampDate()->addMonth()->endOfDay()
                );

                $report = \FluentCart\App\Services\Report\OrderReportService::make()
                    ->getOrderLineChart($params);

                $months = array_map(function ($row) {
                    return (string) $row['group'];
                }, $report['chartData']);

                FcTest::assertSame(
                    [],
                    $fcRangeClampFutureMonths($months, $currentMonth),
                    'GET /reports/order-chart monthly range ending next month excludes a paid order dated next month and returns no group after ' . $currentMonth
                );
            } finally {
                FcFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-range-clamp-order-monthly-average',
        'name'          => 'Order chart Average Monthly divides by months elapsed, not months requested',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcRangeClampParams, $fcRangeClampDate) {
            try {
                FcReportFixture::createCurrentOrderDataset();

                $monthsElapsed = (int) $fcRangeClampDate()->format('n');
                $params = $fcRangeClampParams(
                    $fcRangeClampDate('first day of January this year')->startOfDay(),
                    $fcRangeClampDate()->addMonth()->endOfDay()
                );

                $summary = \FluentCart\App\Services\Report\OrderReportService::make()
                    ->getOrderLineChart($params)['summary'];

                FcTest::assertSame(
                    (float) $summary['net_revenue'] / $monthsElapsed,
                    (float) $summary['monthly_net'],
                    'GET /reports/order-chart monthly_net divides net by ' . $monthsElapsed . ' months elapsed this year'
                );
            } finally {
                FcFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-range-clamp-retention-months',
        'name'          => 'Subscription retention range ending in December has no month after today',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcRangeClampParams, $fcRangeClampDate, $fcRangeClampFutureMonths) {
            $today = $fcRangeClampDate();
            $currentMonth = $today->format('Y-m');
            $endYear = (int) $today->format('Y') + ($today->format('n') === '12' ? 1 : 0);

            $params = $fcRangeClampParams(
                $fcRangeClampDate('first day of January this year')->startOfDay(),
                $fcRangeClampDate($endYear . '-12-31')->endOfDay()
            );

            $rows = \FluentCart\App\Services\Report\SubscriptionReportService::make()
                ->getRetentionData($params);

            $months = array_map(function ($row) {
                return substr((string) $row['day'], 0, 7);
            }, $rows);

            FcTest::assertSame(
                [],
                $fcRangeClampFutureMonths($months, $currentMonth),
                'GET /reports/subscription-retention January to December returns no month after ' . $currentMonth
            );
        },
    ],
];
