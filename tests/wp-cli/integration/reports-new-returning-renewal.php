<?php

return [
    [
        'id'            => 'reports-new-returning-renewal',
        'name'          => 'New vs Returning classifies each Order as New, Returning, Renewal or Guest and the classes sum to the headline Order count',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            $guest = null;
            $customerId = null;
            try {
                $data = FcReportFixture::createDataset();
                $customerId = (int) $data['customer']->id;
                $window = $data['window'];
                $paidA = $data['orders']['paid_a'];

                $paid = [
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
                FcFixture::reportOrder(array_merge($paid, [
                    'type'         => 'renewal',
                    'parent_id'    => (int) $paidA->id,
                    'created_at'   => '2001-02-05 10:00:00',
                    'completed_at' => '2001-02-05 10:00:00',
                    'updated_at'   => '2001-02-05 10:00:00',
                ]));
                $guest = FcFixture::reportOrder(array_merge($paid, [
                    'customer_id'  => null,
                    'created_at'   => '2001-02-05 11:00:00',
                    'completed_at' => '2001-02-05 11:00:00',
                    'updated_at'   => '2001-02-05 11:00:00',
                ]));

                $service = \FluentCart\App\Services\Report\OrderReportService::make();
                $params = FcReportFixture::params('daily');
                $rows = array_map(function ($row) {
                    return (array) $row;
                }, $service->getNewVsReturningCustomer($params));

                FcTest::assertSame(
                    ['New', 'Returning', 'Renewal', 'Guest'],
                    array_map('strval', array_column($rows, 'customer_type')),
                    'GET /reports/fetch-new-vs-returning-customer returns the four classes in fixed order'
                );
                $orderCounts = array_map('intval', array_column($rows, 'order_count'));
                FcTest::assertSame(
                    [1, 1, 1, 1],
                    $orderCounts,
                    'GET /reports/fetch-new-vs-returning-customer counts the first paid Order as New, the later one as Returning, the renewal as Renewal and the customerless Order as Guest'
                );
                FcTest::assertSame(
                    [1, 1, 1, 0],
                    array_map('intval', array_column($rows, 'customer_count')),
                    'GET /reports/fetch-new-vs-returning-customer counts distinct customers per class, none for Guest'
                );
                $headline = (int) $service->getOrderLineChart($params)['summary']['order_count'];
                FcTest::assertSame(
                    [4, 4],
                    [array_sum($orderCounts), $headline],
                    'GET /reports/fetch-new-vs-returning-customer classes sum to the headline Order count'
                );
            } finally {
                // Fixture cleanup refuses an Order whose customer_id differs from the owned Customer.
                if ($guest) {
                    global $wpdb;
                    $wpdb->update(
                        $wpdb->prefix . 'fct_orders',
                        ['customer_id' => $customerId],
                        ['id' => (int) $guest->id, 'customer_id' => null]
                    );
                }
                FcFixture::cleanupAll();
            }
        },
    ],
];
