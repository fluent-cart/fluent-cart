<?php
/**
 * Reports count days in the WordPress site timezone: a picked range covers the store's days (sent with any offset),
 * an order late in the evening lands on the store's day in daily and monthly charts and in the totals, and compare
 * labels read as store days.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\Report\ReportHelper;
use FluentCart\App\Services\Report\RevenueReportService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcSiteTzCurrency = 'XTS';

return [
    [
        'id'            => 'reports-site-timezone',
        'name'          => 'Report ranges, daily and monthly buckets and compare labels use the WordPress timezone',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcSiteTzCurrency) {
            try {
                $foreign = (int) App::db()->table('fct_orders')->where('currency', $fcSiteTzCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' orders already use currency ' . $fcSiteTzCurrency);
                }
                FcFixture::customer();
                // New York is UTC-5 in January
                foreach ([
                    '2025-01-12 04:30:00' => 100,  // Jan 11 23:30 in New York: before the range
                    '2025-01-13 03:30:00' => 1000, // Jan 12 22:30
                    '2025-01-14 04:30:00' => 2000, // Jan 13 23:30
                    '2025-02-01 03:00:00' => 4000, // Jan 31 22:00
                ] as $at => $cents) {
                    FcFixture::reportOrder([
                        'status'         => Status::ORDER_COMPLETED,
                        'payment_status' => Status::PAYMENT_PAID,
                        'currency'       => $fcSiteTzCurrency,
                        'type'           => Status::ORDER_TYPE_PAYMENT,
                        'subtotal'       => $cents,
                        'total_amount'   => $cents,
                        'total_paid'     => $cents,
                        'created_at'     => $at,
                        'updated_at'     => $at,
                    ]);
                }

                FcReportFixture::inSiteTimezone('America/New_York', 0, function () use ($fcSiteTzCurrency) {
                    $utc = function ($date) {
                        return gmdate('Y-m-d H:i:s', $date->getTimestamp());
                    };
                    // The browser sends its own offset; only the picked calendar day counts
                    $params = ReportHelper::processParams([
                        'startDate' => '2025-01-12T00:00:00+06:00', 'endDate' => '2025-01-13T23:59:59+06:00',
                        'currency' => $fcSiteTzCurrency, 'groupKey' => 'daily',
                    ], ['paymentStatus']);
                    $params['paymentStatus'] = Status::getReportStatuses();
                    $params['variationIds'] = [];
                    FcTest::assertSame(['2025-01-12 05:00:00', '2025-01-14 04:59:59'], [$utc($params['startDate']), $utc($params['endDate'])],
                        'A picked range covers the store days, as UTC instants');

                    $daily = RevenueReportService::make()->getRevenueData($params);
                    FcTest::assertSame([10.0, 20.0, 30.0], [
                        (float) FcReportFixture::groupRow($daily['groups'], '2025-01-12')['total_sales'],
                        (float) FcReportFixture::groupRow($daily['groups'], '2025-01-13')['total_sales'],
                        round((float) $daily['summary']['gross_sale'], 2),
                    ], 'Late-evening orders land on the store day; the order before the range stays out');

                    // Whole store months, also across the November daylight-saving change (PHP < 8.1 diff() was off there)
                    $span = function ($from, $to) {
                        $range = ReportHelper::processParams(['startDate' => $from, 'endDate' => $to]);

                        return ReportHelper::monthSpan($range['startDate'], $range['endDate']);
                    };
                    FcTest::assertSame([1, 2, 1, 2], [$span('2025-11-01', '2025-11-30'), $span('2025-10-01', '2025-11-30'),
                        $span('2025-10-03', '2025-11-01'), $span('2025-01-31', '2025-03-01')],
                        'Month counts for November, October-November, a 30-day range and Jan 31 - Mar 1');

                    // The Dashboard cards read the same store days, and their previous period too
                    $cards = FcTest::rest('GET', 'reports/dashboard-stats', ['params' => [
                        'startDate' => '2025-01-12', 'endDate' => '2025-01-13', 'currency' => $fcSiteTzCurrency, 'paymentStatus' => 'all',
                    ]]);
                    $orders = $cards['data']['dashBoardStats']['total_orders'] ?? [];
                    FcTest::assertSame([200, 2, 1], [$cards['status'], (int) ($orders['current_count'] ?? -1), (int) ($orders['compare_count'] ?? -1)],
                        'Dashboard cards: Jan 12-13 hold two orders, Jan 10-11 the late Jan 11 one');

                    // Overview months are store months: 22:00 on a month's last day in New York is the next day in UTC
                    $overviewMonth = new \DateTime('first day of -2 months', new \DateTimeZone('America/New_York'));
                    $evening = (clone $overviewMonth)->modify('last day of this month')->setTime(22, 0);
                    FcFixture::reportOrder([
                        'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'currency' => $fcSiteTzCurrency,
                        'type' => Status::ORDER_TYPE_PAYMENT, 'subtotal' => 500, 'total_amount' => 500, 'total_paid' => 500,
                        'created_at' => $utc($evening), 'updated_at' => $utc($evening),
                    ]);
                    $overview = FcTest::rest('GET', 'reports/overview', ['params' => ['currency' => $fcSiteTzCurrency]]);
                    FcTest::assertSame([200, 500], [$overview['status'], (int) ($overview['data']['data']['gross_revenue'][$overviewMonth->format('Y-m')]['current'] ?? -1)],
                        'Overview counts the evening order in its store month');

                    // A preset counts from today in New York
                    $today = new \DateTime('today', new \DateTimeZone('America/New_York'));
                    $preset = ReportHelper::processParams(['startDate' => '2001-01-01', 'endDate' => '2001-01-02', 'rangeKey' => 'today']);
                    // The local end of day, not start + 24 h: a daylight-saving change day is 23 or 25 hours long
                    FcTest::assertSame([$utc($today), $utc((clone $today)->setTime(23, 59, 59))],
                        [$utc($preset['startDate']), $utc($preset['endDate'])], 'Today is the store day');

                    $ranges = ReportHelper::formatCompareRanges($params);
                    FcTest::assertSame(['start' => '2025-01-10 00:00:00', 'end' => '2025-01-11 23:59:59'], $ranges['previous_period'],
                        'Compare labels read as store days');

                    $month = ReportHelper::processParams([
                        'startDate' => '2025-01-01', 'endDate' => '2025-01-31', 'currency' => $fcSiteTzCurrency, 'groupKey' => 'monthly',
                    ]);
                    $month['paymentStatus'] = Status::getReportStatuses();
                    $month['variationIds'] = [];
                    $monthly = RevenueReportService::make()->getRevenueData($month);
                    FcTest::assertSame([71.0, ['2025-01']], [
                        (float) FcReportFixture::groupRow($monthly['groups'], '2025-01')['total_sales'],
                        array_values(array_unique(array_map(function ($row) {
                            return (string) ((array) $row)['group'];
                        }, $monthly['groups']))),
                    ], 'All four orders, including the evening of Jan 31 in New York, stay in January');

                });

                // One store month is one month for the Orders report's monthly averages, also east of UTC
                FcReportFixture::inSiteTimezone('Asia/Dhaka', 0, function () use ($fcSiteTzCurrency) {
                    FcFixture::reportOrder([
                        'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'currency' => $fcSiteTzCurrency,
                        'type' => Status::ORDER_TYPE_PAYMENT, 'subtotal' => 800, 'total_amount' => 800, 'total_paid' => 800,
                        'created_at' => '2025-03-15 06:00:00', 'updated_at' => '2025-03-15 06:00:00',
                    ]);
                    $march = ReportHelper::processParams(['startDate' => '2025-03-01', 'endDate' => '2025-03-31', 'currency' => $fcSiteTzCurrency, 'groupKey' => 'monthly']);
                    $march['paymentStatus'] = Status::getReportStatuses();
                    $march['variationIds'] = [];
                    $summary = \FluentCart\App\Services\Report\OrderReportService::make()->getOrderLineChart($march)['summary'];
                    FcTest::assertSame([8.0, 8.0], [round((float) $summary['gross_sale'], 2), round((float) $summary['monthly_gross'], 2)],
                        'March in Dhaka counts as one month');
                });
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-store-digest-site-timezone',
        'name'          => 'The store digest counts yesterday in the WordPress timezone, like the Reports',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            try {
                FcFixture::customer();
                FcReportFixture::inSiteTimezone('America/New_York', 0, function () {
                    $count = function () {
                        return (int) \FluentCart\App\Services\Email\StoreDigestService::buildPayload('daily')['metrics']['order_count'];
                    };
                    $before = $count();
                    // 22:00 yesterday in New York is already today in UTC
                    $evening = new \DateTime('yesterday 22:00', new \DateTimeZone('America/New_York'));
                    FcFixture::reportOrder([
                        'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID,
                        'currency' => \FluentCart\App\Modules\MultiCurrency\Services\BaseCurrency::get(),
                        'type' => Status::ORDER_TYPE_PAYMENT, 'subtotal' => 700, 'total_amount' => 700, 'total_paid' => 700,
                        'created_at' => gmdate('Y-m-d H:i:s', $evening->getTimestamp()), 'updated_at' => gmdate('Y-m-d H:i:s', $evening->getTimestamp()),
                    ]);
                    FcTest::assertSame(1, $count() - $before, 'The daily digest counts the order from yesterday evening');
                });
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
