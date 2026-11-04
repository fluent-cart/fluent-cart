<?php
/**
 * Refunds are tax-inclusive, so net revenue and Taxes drop refunded tax once, not twice.
 */

return [
    [
        'id'            => 'reports-refund-tax-net',
        'name'          => 'Revenue and Order reports subtract only the unrefunded share of tax from net revenue',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            try {
                FcFixture::assertReportRangesEmpty();
                $window = FcFixture::reportWindow();
                FcFixture::customer([
                    'first_name' => 'Phase Seven',
                    'last_name'  => 'Refund Tax',
                ]);

                // 10.00 + 2.10 VAT, refunded in full on 2001-02-03 and by half on 2001-02-04
                $rows = [
                    ['2001-02-03 10:00:00', 'refunded', 1210],
                    ['2001-02-04 10:00:00', 'partially_refunded', 605],
                ];
                foreach ($rows as $row) {
                    FcFixture::reportOrder([
                        'status'               => 'completed',
                        'payment_status'       => $row[1],
                        'payment_method'       => 'phase7-card',
                        'payment_method_title' => 'Phase 7 Card',
                        'currency'             => $window['currency'],
                        'subtotal'             => 1000,
                        'tax_total'            => 210,
                        'shipping_tax'         => 0,
                        'total_amount'         => 1210,
                        'total_paid'           => 1210,
                        'total_refund'         => $row[2],
                        'type'                 => 'payment',
                        'mode'                 => 'test',
                        'created_at'           => $row[0],
                        'completed_at'         => $row[0],
                        'updated_at'           => $row[0],
                    ]);
                }

                $params = FcReportFixture::params('daily');
                $params['paymentStatus'] = ['refunded', 'partially_refunded'];

                $revenue = \FluentCart\App\Services\Report\RevenueReportService::make()->getRevenueData($params);
                $orders = \FluentCart\App\Services\Report\OrderReportService::make()->getOrderLineChart($params);

                $cases = [
                    '2001-02-03' => [0.0, 0.0],
                    '2001-02-04' => [5.0, 1.05],
                ];
                foreach ($cases as $day => $expected) {
                    $row = FcReportFixture::groupRow($revenue['groups'], $day);
                    FcTest::assertSame(
                        $expected,
                        [round((float) $row['net_revenue'], 2), round((float) $row['total_tax'], 2)],
                        "GET /reports/revenue {$day} net and Taxes drop refunded tax once (12.10 paid, " . ($day === '2001-02-03' ? 'fully' : 'half') . ' refunded)'
                    );

                    $row = FcReportFixture::groupRow($orders['chartData'], $day);
                    FcTest::assertSame(
                        $expected,
                        [round((float) $row['net_revenue'], 2), round((float) $row['tax_total'], 2)],
                        "GET /reports/order-chart {$day} net and Taxes drop refunded tax once"
                    );
                }
            } finally {
                FcFixture::cleanupAll();
            }
        },
    ],
];
