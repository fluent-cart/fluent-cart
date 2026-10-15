<?php
/**
 * Exact-value coverage for the Refund report summary and buckets.
 */

return [
    [
        'id'            => 'reports-refund-aggregates',
        'name'          => 'Refund report averages over refunded orders only',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            try {
                $data = FcReportFixture::createDataset();
                $window = $data['window'];

                // Ten paid in-window orders: the two from createDataset,
                // six more with no refund, and two refunded (30.00 + 10.00).
                $base = [
                    'status'               => 'completed',
                    'payment_status'       => 'paid',
                    'payment_method'       => 'phase7-card',
                    'payment_method_title' => 'Phase 7 Card',
                    'currency'             => $window['currency'],
                    'subtotal'             => 5000,
                    'total_amount'         => 5000,
                    'total_paid'           => 5000,
                    'total_refund'         => 0,
                    'type'                 => 'payment',
                    'mode'                 => 'test',
                ];
                $rows = [
                    ['created_at' => '2001-02-04 11:00:00', 'total_refund' => 3000],
                    ['created_at' => '2001-02-04 12:00:00', 'total_refund' => 1000],
                    ['created_at' => '2001-02-04 13:00:00'],
                    ['created_at' => '2001-02-04 14:00:00'],
                    ['created_at' => '2001-02-04 15:00:00'],
                    ['created_at' => '2001-02-05 10:00:00'],
                    ['created_at' => '2001-02-05 11:00:00'],
                    ['created_at' => '2001-02-05 12:00:00'],
                ];
                foreach ($rows as $row) {
                    $row['completed_at'] = $row['created_at'];
                    $row['updated_at'] = $row['created_at'];
                    if (!empty($row['total_refund'])) {
                        $row['payment_status'] = 'partially_refunded';
                        $row['refunded_at'] = $row['created_at'];
                    }
                    FcFixture::reportOrder(array_merge($base, $row));
                }

                $params = FcReportFixture::params('daily');
                $params['paymentStatus'] = ['paid', 'partially_refunded'];

                $report = \FluentCart\App\Services\Report\RefundReportService::make()
                    ->getRefundData($params);
                $summary = $report['summary'];

                FcTest::assertSame(
                    2,
                    (int) $summary['refund_count'],
                    'GET /reports/refund-chart counts exactly two refunded Orders'
                );
                FcTest::assertSame(
                    40.0,
                    (float) $summary['refunded_amount'],
                    'GET /reports/refund-chart sums exact refunded amount 40.00'
                );
                FcTest::assertSame(
                    20.0,
                    (float) $summary['average_refunded_amount'],
                    'GET /reports/refund-chart average divides refunded amount by refunded Orders (40.00 / 2), not by all ten Orders'
                );

                $day = FcReportFixture::groupRow($report['grouped'], '2001-02-04');
                FcTest::assertSame(
                    2,
                    (int) $day['refund_count'],
                    'GET /reports/refund-chart 2001-02-04 bucket counts two refunded Orders'
                );
                FcTest::assertSame(
                    20.0,
                    (float) $day['average_refunded_amount'],
                    'GET /reports/refund-chart 2001-02-04 bucket average is 40.00 / 2, not 40.00 / 6'
                );
            } finally {
                FcFixture::cleanupAll();
            }
        },
    ],
];
