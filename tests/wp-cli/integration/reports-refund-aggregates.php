<?php
/**
 * Exact-value coverage for refunded orders and amounts in the report summary and buckets.
 */

return [
    [
        'id'            => 'reports-refund-aggregates',
        'name'          => 'Revenue report counts refunded orders and amounts',
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

                // The Revenue report reads the same column (o.total_refund) the removed refund chart summed
                $report = \FluentCart\App\Services\Report\RevenueReportService::make()
                    ->getRevenueData($params);
                $summary = $report['summary'];

                FcTest::assertSame(
                    2,
                    (int) $summary['refunded_orders'],
                    'GET /reports/revenue counts exactly two refunded Orders'
                );
                FcTest::assertSame(
                    40.0,
                    (float) $summary['total_refunded_amount'],
                    'GET /reports/revenue sums exact refunded amount 40.00'
                );

                $day = FcReportFixture::groupRow($report['groups'], '2001-02-04');
                FcTest::assertSame(
                    2,
                    (int) $day['refunded_orders'],
                    'GET /reports/revenue 2001-02-04 bucket counts two refunded Orders'
                );
            } finally {
                FcFixture::cleanupAll();
            }
        },
    ],
];
