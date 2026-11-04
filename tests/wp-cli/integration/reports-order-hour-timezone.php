<?php
/**
 * Spend by hour and the weekday heatmap read each order in the store timezone, with the offset of its own date,
 * so a daylight-saving store keeps its hours across the change and a manual-offset store shifts by that offset.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCartPro\App\Modules\Reports\Services\OrderPatternsService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcHourCurrency = 'XTS';

return [
    [
        'id'            => 'reports-order-hour-timezone',
        'name'          => 'Spend by hour and the weekday heatmap use the store timezone, daylight saving included',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcHourCurrency) {
            // A Pro chart: without Pro the route is gone and the Orders page shows a locked preview
            if (!class_exists(OrderPatternsService::class)) {
                $route = FcTest::rest('GET', 'reports/fetch-report-by-day-and-hour', ['params' => ['currency' => $fcHourCurrency]]);
                FcTest::assertSame(404, $route['status'], 'Pro absent: core no longer serves reports/fetch-report-by-day-and-hour');

                return;
            }
            try {
                $foreign = (int) App::db()->table('fct_orders')->where('currency', $fcHourCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' orders already use currency ' . $fcHourCurrency);
                }
                FcFixture::customer();
                // Both at 03:30 UTC on a Monday: 22:30 Sunday in winter (UTC-5), 23:30 Sunday in summer (UTC-4)
                foreach (['2025-01-13 03:30:00' => 1000, '2025-07-14 03:30:00' => 2000] as $at => $cents) {
                    FcFixture::reportOrder([
                        'status'         => Status::ORDER_COMPLETED,
                        'payment_status' => Status::PAYMENT_PAID,
                        'currency'       => $fcHourCurrency,
                        'type'           => Status::ORDER_TYPE_PAYMENT,
                        'subtotal'       => $cents,
                        'total_amount'   => $cents,
                        'total_paid'     => $cents,
                        'created_at'     => $at,
                        'updated_at'     => $at,
                    ]);
                }
                $params = ['startDate' => new DateTime('2025-01-01 00:00:00'), 'endDate' => new DateTime('2025-12-31 23:59:59'),
                    'currency' => $fcHourCurrency, 'paymentStatus' => Status::getReportStatuses()];
                $buckets = function () use ($params) {
                    $report = OrderPatternsService::make()->getReportByDayAndHour($params);
                    $hours = [];
                    foreach ($report['grossSaleByHour'] as $hour => $row) {
                        if ($row['order_count']) {
                            $hours[$hour] = (float) $row['gross_sale'];
                        }
                    }
                    $cells = [];
                    foreach ($report['orderByDayAndHour'] as $hour => $row) {
                        foreach ($row as $day => $count) {
                            if ($day !== 'hour' && $count) {
                                $cells[] = $day . ' ' . $hour;
                            }
                        }
                    }

                    return [$hours, $cells];
                };

                FcTest::assertSame([[22 => 10.0, 23 => 20.0], ['Sunday 22', 'Sunday 23']], FcReportFixture::inSiteTimezone('America/New_York', 0, $buckets),
                    'A daylight-saving store: each order in the offset of its own date');

                FcTest::assertSame([[9 => 30.0], ['Monday 9']], FcReportFixture::inSiteTimezone('', 6, $buckets), 'A manual-offset store: UTC+6');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
