<?php
/**
 * Coupons: coupon vs full price, the value of the customers a coupon won and its refund rate are FluentCart Pro
 * reports. The routes send them only when Pro asks; uses, discount and sales stay free.
 */

return [
    [
        'id'            => 'reports-coupons-pro-quality',
        'name'          => 'Coupon routes send the comparison, won value and refund rate with Pro only',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            $pro = (bool) has_filter('fluent_cart/report/coupon_quality');
            // No store row uses the ISO test currency: empty reports are enough
            $params = ['params' => ['currency' => 'XTS', 'startDate' => '2001-03-01 00:00:00', 'endDate' => '2001-03-31 23:59:59']];

            $overview = FcTest::rest('GET', 'reports/coupon-overview', $params);
            FcTest::assertSame([200, $pro, true], [$overview['status'], array_key_exists('comparison', (array) ($overview['data'] ?? [])),
                array_key_exists('summary', (array) ($overview['data'] ?? []))], 'Coupon vs full price comes with Pro; the summary always');

            $table = FcTest::rest('GET', 'reports/coupons', $params);
            $totals = (array) ($table['data']['totals'] ?? []);
            FcTest::assertSame([200, $pro, $pro, true], [$table['status'], array_key_exists('won_ltv', $totals), array_key_exists('refund_rate', $totals),
                array_key_exists('discount', $totals)], 'Won value and refund rate come with Pro; discount always');
        },
    ],
];
