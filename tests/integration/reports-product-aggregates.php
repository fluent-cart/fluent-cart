<?php
/**
 * Exact-value coverage for the Product report summary and buckets.
 */

$productReport = function (array $params) {
    return \FluentCart\App\Services\Report\ProductReportService::make()->getProductReportData($params);
};

$salesGross = function (array $params) {
    return round((float) \FluentCart\App\Services\Report\RevenueReportService::make()->getRevenueData($params)['summary']['gross_sale'], 4);
};

$bucket = function (array $report, string $column) {
    return round((float) FcReportFixture::groupRow($report['grouped'], '2001-02-03')[$column], 4);
};

return [
    [
        'id'            => 'reports-product-aggregates',
        'name'          => 'Product report sums money from Order items, not once per joined item row',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($productReport, $salesGross, $bucket) {
            try {
                $data = FcReportFixture::createDataset();

                $order = FcFixture::reportOrder([
                    'status'               => 'completed',
                    'payment_status'       => 'partially_refunded',
                    'payment_method'       => 'phase7-card',
                    'payment_method_title' => 'Phase 7 Card',
                    'currency'             => $data['window']['currency'],
                    'subtotal'             => 5000,
                    'tax_total'            => 500,
                    'total_amount'         => 5000,
                    'total_paid'           => 5000,
                    'total_refund'         => 1000,
                    'completed_at'         => '2001-02-03 10:00:00',
                    'refunded_at'          => '2001-02-03 11:00:00',
                    'created_at'           => '2001-02-03 10:00:00',
                    'updated_at'           => '2001-02-03 11:00:00',
                    'type'                 => 'payment',
                    'mode'                 => 'test',
                ]);
                FcFixture::reportOrderItem((int) $order->id, [
                    'post_id'      => (int) $data['product_id'],
                    'object_id'    => (int) $data['variation_id'],
                    'quantity'     => 1,
                    'unit_price'   => 3000,
                    'subtotal'     => 3000,
                    'tax_amount'   => 300,
                    'line_total'   => 3000,
                    'refund_total' => 1000,
                    'created_at'   => '2001-02-03 10:00:00',
                ]);
                FcFixture::reportOrderItem((int) $order->id, [
                    'post_id'      => (int) $data['product_id'],
                    'object_id'    => (int) $data['variation_id'] + 1,
                    'cart_index'   => 1,
                    'quantity'     => 2,
                    'unit_price'   => 1000,
                    'subtotal'     => 2000,
                    'tax_amount'   => 200,
                    'line_total'   => 2000,
                    'created_at'   => '2001-02-03 10:00:00',
                ]);

                $params = FcReportFixture::params('daily');
                $params['paymentStatus'] = ['paid', 'partially_refunded'];
                $report = $productReport($params);

                FcTest::assertSame(50.0, $bucket($report, 'gross_sale'), 'bucket gross is the 30.00 + 20.00 line totals, not the 50.00 Order total per item');
                FcTest::assertSame(10.0, $bucket($report, 'total_refunds'), 'bucket refunds are the 10.00 item refund, not the Order refund per item');
                FcTest::assertSame(35.0, $bucket($report, 'net_sale'), 'bucket net deducts the 5.00 item tax, not Order tax per item');
                FcTest::assertSame($salesGross($params), round((float) $report['summary']['gross_sale'], 4), 'summary gross equals the Sales report gross');
            } finally {
                FcFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-product-aggregates-order-discount',
        'name'          => 'Product report apportions an order-level discount across that Order\'s items',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($productReport, $salesGross, $bucket) {
            try {
                $data = FcReportFixture::createDataset();

                $order = FcFixture::reportOrder([
                    'status'                => 'completed',
                    'payment_status'        => 'paid',
                    'payment_method'        => 'phase7-card',
                    'payment_method_title'  => 'Phase 7 Card',
                    'currency'              => $data['window']['currency'],
                    'subtotal'              => 4000,
                    'manual_discount_total' => 400,
                    'tax_total'             => 400,
                    'total_amount'          => 3600,
                    'total_paid'            => 3600,
                    'completed_at'          => '2001-02-03 10:00:00',
                    'created_at'            => '2001-02-03 10:00:00',
                    'updated_at'            => '2001-02-03 10:00:00',
                    'type'                  => 'payment',
                    'mode'                  => 'test',
                ]);
                FcFixture::reportOrderItem((int) $order->id, [
                    'post_id'    => (int) $data['product_id'],
                    'object_id'  => (int) $data['variation_id'],
                    'quantity'   => 1,
                    'unit_price' => 3000,
                    'subtotal'   => 3000,
                    'tax_amount' => 300,
                    'line_total' => 3000,
                    'created_at' => '2001-02-03 10:00:00',
                ]);
                FcFixture::reportOrderItem((int) $order->id, [
                    'post_id'    => (int) $data['product_id'],
                    'object_id'  => (int) $data['variation_id'] + 1,
                    'cart_index' => 1,
                    'quantity'   => 1,
                    'unit_price' => 1000,
                    'subtotal'   => 1000,
                    'tax_amount' => 100,
                    'line_total' => 1000,
                    'created_at' => '2001-02-03 10:00:00',
                ]);

                $params = FcReportFixture::params('daily');
                $report = $productReport($params);

                FcTest::assertSame(36.0, $bucket($report, 'gross_sale'), 'bucket gross is the 36.00 paid, not the 40.00 undiscounted line totals');
                FcTest::assertSame($salesGross($params), round((float) $report['summary']['gross_sale'], 4), 'summary gross equals the Sales report gross when an Order carries a manual discount');

                $params['variationIds'] = [(int) $data['variation_id']];
                FcTest::assertSame(27.0, $bucket($productReport($params), 'gross_sale'), 'one variation takes its 3/4 share of the Order discount (30.00 - 3.00)');
            } finally {
                FcFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-product-aggregates-converted-discount',
        'name'          => 'Product report apportions an order-level discount in converted cents under the all-currencies mode',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($productReport, $salesGross, $bucket) {
            try {
                $data = FcReportFixture::createDataset();

                // Two 100-cent lines at rate 3 round to 33 + 33 per item. Converting the Order once gives
                // ROUND(198 / 3) = 66 with the 2-cent header discount and ROUND(200 / 3) = 67 without one
                foreach ([0 => 2, 2 => 0] as $offset => $discount) {
                    $order = FcFixture::reportOrder([
                        'status'                => 'completed',
                        'payment_status'        => 'paid',
                        'payment_method'        => 'phase7-card',
                        'payment_method_title'  => 'Phase 7 Card',
                        'currency'              => $data['window']['currency'] === 'EUR' ? 'GBP' : 'EUR',
                        'rate'                  => 3,
                        'subtotal'              => 200,
                        'manual_discount_total' => $discount,
                        'total_amount'          => 200 - $discount,
                        'total_paid'            => 200 - $discount,
                        'completed_at'          => '2001-02-03 10:00:00',
                        'created_at'            => '2001-02-03 10:00:00',
                        'updated_at'            => '2001-02-03 10:00:00',
                        'type'                  => 'payment',
                        'mode'                  => 'test',
                    ]);
                    foreach ([0, 1] as $index) {
                        FcFixture::reportOrderItem((int) $order->id, [
                            'post_id'    => (int) $data['product_id'],
                            'object_id'  => (int) $data['variation_id'] + $offset + $index,
                            'cart_index' => $index,
                            'quantity'   => 1,
                            'unit_price' => 100,
                            'subtotal'   => 100,
                            'line_total' => 100,
                            'created_at' => '2001-02-03 10:00:00',
                        ]);
                    }
                }

                $params = FcReportFixture::params('daily');
                $params['currency'] = \FluentCart\App\Modules\MultiCurrency\Services\ReportCurrency::ALL;
                $report = $productReport($params);

                FcTest::assertSame(1.33, $bucket($report, 'gross_sale'), 'bucket gross is each Order net converted once (0.66 + 0.67), not 1.31 from per-item rounding');
                FcTest::assertSame($salesGross($params), round((float) $report['summary']['gross_sale'], 4), 'summary gross equals the Sales report gross to the cent in the all-currencies mode');
                FcTest::assertSame(0.01, round((float) $report['summary']['total_discounts'], 4), 'summary discounts are the 0.67 converted subtotal less the 0.66 net of the discounted Order, nothing from the undiscounted one');

                $params['variationIds'] = [(int) $data['variation_id'] + 2];
                FcTest::assertSame(0.34, $bucket($productReport($params), 'gross_sale'), 'the first item of the undiscounted Order absorbs the leftover cent of its 0.67 net, not 0.33');

                $params['variationIds'] = [(int) $data['variation_id'] + 2, (int) $data['variation_id'] + 3];
                FcTest::assertSame(0.0, round((float) $productReport($params)['summary']['total_discounts'], 4), 'the undiscounted Order reports no discount in the all-currencies mode, not -0.01');
                $totals = \FluentCart\App\Services\Report\ProductReportService::make()->getProductCompare($params)['totals'];
                foreach ($params['variationIds'] as $variationId) {
                    FcTest::assertSame(0.0, round((float) ($totals[$variationId]['discount'] ?? -1), 4), 'Compare reports no discount for an undiscounted Order item in the all-currencies mode');
                }
            } finally {
                FcFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-product-aggregates-customer-count',
        'name'          => 'Product report summary counts a repeat customer once across buckets',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($productReport) {
            try {
                FcReportFixture::createDataset();

                FcTest::assertSame(
                    1,
                    (int) $productReport(FcReportFixture::params('daily'))['summary']['customer_count'],
                    'summary counts the one distinct Customer, not one per day'
                );
            } finally {
                FcFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-product-aggregates-discounts',
        'name'          => 'Product report and Compare return item discounts as item subtotals less item sales',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($productReport) {
            try {
                $data = FcReportFixture::createDataset();

                $order = FcFixture::reportOrder([
                    'status'                => 'completed',
                    'payment_status'        => 'paid',
                    'payment_method'        => 'phase7-card',
                    'payment_method_title'  => 'Phase 7 Card',
                    'currency'              => $data['window']['currency'],
                    'subtotal'              => 5000,
                    'coupon_discount_total' => 500,
                    'total_amount'          => 4500,
                    'total_paid'            => 4500,
                    'completed_at'          => '2001-02-03 10:00:00',
                    'created_at'            => '2001-02-03 10:00:00',
                    'updated_at'            => '2001-02-03 10:00:00',
                    'type'                  => 'payment',
                    'mode'                  => 'test',
                ]);
                foreach ([[3000, 300], [2000, 200]] as $index => [$subtotal, $discount]) {
                    FcFixture::reportOrderItem((int) $order->id, [
                        'post_id'        => (int) $data['product_id'],
                        'object_id'      => (int) $data['variation_id'] + $index,
                        'cart_index'     => $index,
                        'quantity'       => 1,
                        'unit_price'     => $subtotal,
                        'subtotal'       => $subtotal,
                        'discount_total' => $discount,
                        'line_total'     => $subtotal - $discount,
                        'created_at'     => '2001-02-03 10:00:00',
                    ]);
                }

                $params = FcReportFixture::params('daily');
                $summary = $productReport($params)['summary'];
                FcTest::assertSame(5.0, round((float) ($summary['total_discounts'] ?? -1), 4), 'summary discounts are the 50.00 item subtotals less the 45.00 item sales');

                $params['variationIds'] = [(int) $data['variation_id'], (int) $data['variation_id'] + 1];
                $totals = \FluentCart\App\Services\Report\ProductReportService::make()->getProductCompare($params)['totals'];
                $row = function ($variationId) use ($totals) {
                    $total = $totals[$variationId];
                    return [round((float) ($total['discount'] ?? -1), 4), (int) ($total['customers'] ?? -1)];
                };
                FcTest::assertSame([3.0, 1], $row((int) $data['variation_id']), 'first variation: its 3.00 coupon share, one Customer across its Orders');
                FcTest::assertSame([2.0, 1], $row((int) $data['variation_id'] + 1), 'second variation: its 2.00 coupon share, one Customer');
            } finally {
                FcFixture::cleanupAll();
            }
        },
    ],
];
