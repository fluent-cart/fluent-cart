<?php
/**
 * A saved preset ("Last 7 days") is resolved on the server from today's UTC day, so a tab or a saved filter from
 * yesterday still reads the current days. A custom range keeps the dates it was sent, with or without Pro
 * (run with --axis=pro-absent for the free store).
 */

use FluentCart\App\Services\Report\ReportHelper;

return [
    [
        'id'            => 'reports-range-presets',
        'name'          => 'Report range presets resolve from today on the server; custom ranges keep their dates',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC', // fixtures are UTC days; reports-site-timezone covers other zones
        'run'           => function () {
            $range = function (array $params) {
                $processed = ReportHelper::processParams($params);

                return [$processed['startDate']->format('Y-m-d H:i:s'), $processed['endDate']->format('Y-m-d H:i:s')];
            };
            $day = function ($modify) {
                return gmdate('Y-m-d', strtotime($modify, strtotime(gmdate('Y-m-d') . ' 12:00:00 UTC')));
            };
            // Stale dates, as a tab left open since an earlier day would send them
            $stale = ['startDate' => '2001-01-01 00:00:00', 'endDate' => '2001-01-02 23:59:59'];

            $expected = [
                'today'          => [$day('today'), $day('today')],
                'last_7'         => [$day('-6 days'), $day('today')],
                'last_30'        => [$day('-29 days'), $day('today')],
                'this_month'     => [$day('first day of this month'), $day('last day of this month')],
                'last_month'     => [$day('first day of last month'), $day('last day of last month')],
                'last_12_months' => [$day('first day of -11 months'), $day('today')],
                'year_to_date'   => [gmdate('Y') . '-01-01', $day('today')],
            ];
            foreach ($expected as $key => $days) {
                FcTest::assertSame([$days[0] . ' 00:00:00', $days[1] . ' 23:59:59'], $range($stale + ['rangeKey' => $key]),
                    'Preset ' . $key . ' resolves from today, not from the sent dates');
            }

            FcTest::assertSame(['2001-01-01 00:00:00', $day('today') . ' 23:59:59'], $range($stale + ['rangeKey' => 'all_time']),
                'All time keeps the sent first-order date and ends today');
            FcTest::assertSame(['2001-01-01 00:00:00', '2001-01-02 23:59:59'], $range($stale + ['rangeKey' => 'custom']),
                'A custom range keeps its dates');
            FcTest::assertSame(['2001-01-01 00:00:00', '2001-01-02 23:59:59'], $range($stale + ['rangeKey' => 'Last Week']),
                'An unknown key keeps the sent dates');
        },
    ],
];
