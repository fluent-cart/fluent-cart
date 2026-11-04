<?php
/**
 * With Auto grouping, a report's compared period uses the current period's buckets. A range running into the
 * future is grouped by the requested length, while its compared range is sized to the elapsed days; grouped on its
 * own, it could turn daily against monthly points, and the charts pair the two by position.
 */

return [
    [
        'id'            => 'reports-compare-grouping',
        'name'          => 'Orders, Revenue and Refunds compare charts use the current period\'s Auto grouping',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC',
        'run'           => function () {
            // 100 days requested (monthly), 40 elapsed: the compared range alone would be daily
            $params = ['params' => [
                'startDate'   => gmdate('Y-m-d 00:00:00', strtotime('-40 days')),
                'endDate'     => gmdate('Y-m-d 23:59:59', strtotime('+59 days')),
                // No store row uses the ISO test currency: empty buckets are enough
                'currency'    => 'XTS',
                'compareType' => 'previous_period',
                'compareDate' => gmdate('Y-m-d 23:59:59'),
                'groupKey'    => 'default',
            ]];
            $monthly = function (array $points, string $key = 'group') {
                foreach ($points as $point) {
                    if (strlen((string) ((array) $point)[$key]) !== 7) {
                        return false;
                    }
                }

                return (bool) $points;
            };

            $orders = FcTest::rest('GET', 'reports/order-chart', $params)['data'] ?? [];
            $revenue = FcTest::rest('GET', 'reports/revenue', $params)['data'] ?? [];
            $refunds = FcTest::rest('GET', 'reports/refunds-overview', $params)['data'] ?? [];

            FcTest::assertSame(
                [true, true, true, true, true, true],
                [
                    $monthly($orders['orderChartData'] ?? []), $monthly($orders['previousChartData'] ?? []),
                    $monthly($revenue['revenueReport'] ?? []), $monthly($revenue['previousMetrics'] ?? []),
                    $monthly($refunds['chart'] ?? []), $monthly($refunds['previousChart'] ?? []),
                ],
                'Current and compared points are both monthly on Orders, Revenue and Refunds'
            );
        },
    ],
];
