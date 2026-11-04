<?php
/**
 * Installments: the monthly schedule, plans started by month and the store's collection rate are FluentCart Pro
 * reports. The route sends them only when Pro asks; the summary, due soon and at-risk figures stay free.
 */

return [
    [
        'id'            => 'reports-installments-pro-forecast',
        'name'          => 'Installments route sends the schedule, starts and collection rate with Pro only',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            // No store row uses the ISO test currency: an empty report is enough
            $route = FcTest::rest('GET', 'reports/installments', ['params' => ['currency' => 'XTS']]);
            $data = (array) ($route['data'] ?? []);
            $pro = (bool) has_filter('fluent_cart/report/installment_forecast');

            FcTest::assertSame([200, $pro, $pro, $pro, true, true], [$route['status'], array_key_exists('schedule', $data),
                array_key_exists('starts', $data), array_key_exists('collection_rate', (array) ($data['summary'] ?? [])),
                array_key_exists('due_soon', $data), array_key_exists('scheduled', (array) ($data['summary'] ?? []))],
                'The forecast parts come with Pro; the summary and due soon always');
        },
    ],
];
