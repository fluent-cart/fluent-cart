<?php
/**
 * Cohorts: one page and one route. FluentCart Pro answers fluent_cart/report/cohort_options, which allows MRR
 * cohorts, the billing-interval filter and rebuy-adjusted retention; without Pro the route serves customer cohorts.
 */

return [
    [
        'id'            => 'reports-cohort-pro-options',
        'name'          => 'Cohorts route serves MRR with Pro, customer cohorts only without',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            $pro = (bool) has_filter('fluent_cart/report/cohort_options');
            // No store row uses the ISO test currency: an empty report is enough
            $ask = ['params' => ['currency' => 'XTS', 'groupBy' => 'year', 'metric' => 'mrr', 'billingInterval' => 'monthly', 'rebuyAdjusted' => 1]];

            $route = FcTest::rest('GET', 'reports/subscription-cohorts', $ask);
            FcTest::assertSame([200, $pro ? 'mrr' : 'subscribers', $pro], [$route['status'], $route['data']['metric'] ?? null, $route['data']['full'] ?? null],
                'An MRR request is served with Pro; without it the route answers with customer cohorts');

            // The free answer, also when Pro is loaded: a store without Pro's yes gets customer cohorts
            $no = function () {
                return false;
            };
            add_filter('fluent_cart/report/cohort_options', $no, PHP_INT_MAX);
            try {
                $free = FcTest::rest('GET', 'reports/subscription-cohorts', $ask);
            } finally {
                remove_filter('fluent_cart/report/cohort_options', $no, PHP_INT_MAX);
            }
            FcTest::assertSame([200, 'subscribers', false], [$free['status'], $free['data']['metric'] ?? null, $free['data']['full'] ?? null],
                'Without Pro\'s answer an MRR request gets customer cohorts');
            FcTest::assertSame(404, FcTest::rest('GET', 'reports/subscription-cohorts/details', $ask)['status'],
                'No separate Pro route: Pro answers through the core route');
        },
    ],
];
