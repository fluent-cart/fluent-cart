<?php
/**
 * Refunds: the most refunded products are a FluentCart Pro report. The route sends them only when Pro asks; the
 * cards, chart, breakdown and reasons stay free.
 */

return [
    [
        'id'            => 'reports-refunds-pro-products',
        'name'          => 'Refunds overview sends the most refunded products with Pro only',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            // No store row uses the ISO test currency: an empty report is enough
            $route = FcTest::rest('GET', 'reports/refunds-overview', ['params' => [
                'currency' => 'XTS', 'startDate' => '2001-03-01 00:00:00', 'endDate' => '2001-03-31 23:59:59',
            ]]);
            $data = (array) ($route['data'] ?? []);

            FcTest::assertSame([200, (bool) has_filter('fluent_cart/report/refund_products'), true],
                [$route['status'], array_key_exists('by_product', $data), array_key_exists('summary', $data)],
                'The refunded products come with Pro; the summary always');
        },
    ],
];
