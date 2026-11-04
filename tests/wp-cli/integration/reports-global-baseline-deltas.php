<?php
/**
 * Phase 7 exact-value deltas for inert global/current readers.
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
];
