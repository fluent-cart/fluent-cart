<?php
/**
 * Locations: one page and one route. FluentCart Pro answers fluent_cart/report/locations with the full report
 * (states, cities, lifetime value, movers); without Pro the route serves countries only and says so.
 */

return [
    [
        'id'            => 'reports-locations-pro-full',
        'name'          => 'Locations route serves states and lifetime value with Pro, countries only without',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            $pro = (bool) has_filter('fluent_cart/report/locations');
            // No store row uses the ISO test currency: an empty report is enough
            $route = FcTest::rest('GET', 'reports/locations', ['params' => [
                'currency' => 'XTS', 'startDate' => '2001-03-01 00:00:00', 'endDate' => '2001-03-31 23:59:59',
                'level' => 'state', 'country' => 'DE',
            ]]);
            $data = (array) ($route['data'] ?? []);

            FcTest::assertSame([200, $pro ? 'state' : 'country', $pro], [$route['status'], $data['level'] ?? null, $data['full'] ?? null],
                'A drill into Germany is served with Pro; without it the route stays at countries');

            // The free answer, also when Pro is loaded: without Pro's report the route stays at countries
            $none = function () {
                return null;
            };
            add_filter('fluent_cart/report/locations', $none, PHP_INT_MAX);
            try {
                $free = FcTest::rest('GET', 'reports/locations', ['params' => [
                    'currency' => 'XTS', 'startDate' => '2001-03-01 00:00:00', 'endDate' => '2001-03-31 23:59:59',
                    'level' => 'state', 'country' => 'DE',
                ]]);
            } finally {
                remove_filter('fluent_cart/report/locations', $none, PHP_INT_MAX);
            }
            FcTest::assertSame([200, 'country', false], [$free['status'], $free['data']['level'] ?? null, $free['data']['full'] ?? null],
                'Without Pro\'s report a drill-down stays at countries');
            FcTest::assertSame(404, FcTest::rest('GET', 'reports/locations/details', ['params' => ['currency' => 'XTS']])['status'],
                'No separate Pro route: Pro answers through the core route');
        },
    ],
];
