<?php
/**
 * Subscriptions: MRR by plan, MRR by billing interval and trial conversion are a FluentCart Pro report. The route
 * sends them only when Pro asks; the period figures and chart stay free.
 */

return [
    [
        'id'            => 'reports-subscriptions-mrr-breakdown-pro',
        'name'          => 'Subscriptions overview sends the MRR breakdown with Pro only',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            // No store row uses the ISO test currency: an empty report is enough
            $route = FcTest::rest('GET', 'reports/subscriptions-overview', ['params' => [
                'currency' => 'XTS', 'startDate' => '2001-03-01 00:00:00', 'endDate' => '2001-03-31 23:59:59',
            ]]);
            $data = (array) ($route['data'] ?? []);
            $current = (array) ($data['current'] ?? $data);
            $pro = (bool) has_filter('fluent_cart/report/mrr_breakdown');

            FcTest::assertSame([200, $pro, $pro, true], [$route['status'], array_key_exists('mrr_by_plan', $current),
                array_key_exists('trials', $current), array_key_exists('period', $current)],
                'The breakdown comes with Pro; the period figures always');
        },
    ],
];
