<?php
/**
 * Exact-value coverage for the Overview Top Country status filter and the
 * sales-report items join bound.
 */

return [
    [
        'id'            => 'reports-order-status-join-hygiene',
        'name'          => 'Top Country counts partially refunded orders and the sales report keeps orders whose item rows predate the range',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            try {
                $data = FcReportFixture::createDataset();
                $window = $data['window'];

                $base = [
                    'status'               => 'completed',
                    'payment_method'       => 'phase7-card',
                    'payment_method_title' => 'Phase 7 Card',
                    'currency'             => $window['currency'],
                    'created_at'           => '2001-02-05 12:00:00',
                    'completed_at'         => '2001-02-05 12:00:00',
                    'updated_at'           => '2001-02-05 12:00:00',
                    'mode'                 => 'test',
                ];

                $partial = FcFixture::reportOrder(array_merge($base, [
                    'payment_status' => 'partially_refunded',
                    'subtotal'       => 5000,
                    'total_amount'   => 5000,
                    'total_paid'     => 5000,
                    'total_refund'   => 1000,
                    'type'           => 'payment',
                ]));
                FcFixture::reportOrderAddress((int) $partial->id, ['country' => 'BD']);

                $renewal = FcFixture::reportOrder(array_merge($base, [
                    'parent_id'      => (int) $data['orders']['paid_a']->id,
                    'payment_status' => 'paid',
                    'subtotal'       => 3000,
                    'total_amount'   => 3000,
                    'total_paid'     => 3000,
                    'total_refund'   => 0,
                    'type'           => 'renewal',
                ]));
                FcFixture::reportOrderItem((int) $renewal->id, [
                    'quantity'   => 1,
                    'unit_price' => 3000,
                    'subtotal'   => 3000,
                    'line_total' => 3000,
                    'created_at' => '2001-01-31 10:00:00',
                ]);

                $controller = new \FluentCart\App\Http\Controllers\Reports\OverviewReportController();
                $method = new ReflectionMethod($controller, 'getCountryWiseStatsImproved');
                $method->setAccessible(true);
                // The country query bounds with created_at < end, so the end is exclusive
                $country = $method->invoke(
                    $controller, $window['start'], '2001-02-06 00:00:00', 5, $window['currency']
                );
                $gross = 0;
                $net = 0;
                foreach ([$data['orders']['paid_a'], $data['orders']['paid_b'], $partial] as $order) {
                    $gross += (int) $order->total_paid;
                    $net += (int) $order->total_paid - (int) $order->total_refund
                        - (int) $order->tax_total - (int) $order->shipping_tax;
                }
                FcTest::assertSame(
                    [$gross, $net],
                    [
                        (int) $country['gross']['by_countries']['BD'],
                        (int) $country['net']['by_countries']['BD'],
                    ],
                    'GET /reports/get-overview Top Country BD sums the two paid Orders and the partially refunded Order'
                );

                $params = FcReportFixture::params('daily');
                $params['paymentStatus'] = \FluentCart\App\Helpers\Status::getReportStatuses();
                $sales = \FluentCart\App\Services\Report\DefaultReportService::make()
                    ->getAllGraphMetricsSeparate($params);
                FcTest::assertSame(
                    [4, 1, 6],
                    [
                        (int) $sales['summary']['order_count'],
                        (int) $sales['summary']['renewal_count'],
                        (int) $sales['summary']['total_item_count'],
                    ],
                    'GET /reports/sales-report counts the renewal whose item row predates the range (4 Orders, 1 renewal, 6 items)'
                );
            } finally {
                FcFixture::cleanupAll();
            }
        },
    ],
];
