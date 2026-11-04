<?php
/**
 * Orders report figures that match the other reports: new customers by the shared first-purchase rule (a free order
 * is its own type), and net sales as the Revenue report counts them (shipping out) for the chart and the average.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\OrderReportService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcOrdersConsistencyCurrency = 'XTS';

return [
    [
        'id'            => 'reports-orders-consistency',
        'name'          => 'Orders report: shared new-customer rule, a free-order type, Revenue net sales in the chart and the average',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC',
        'run'           => function () use ($fcOrdersConsistencyCurrency) {
            $db = App::db();
            $owner = null;
            $orderIds = [];
            try {
                $foreign = (int) $db->table('fct_orders')->where('currency', $fcOrdersConsistencyCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' rows already use currency ' . $fcOrdersConsistencyCurrency);
                }
                $owner = (int) FcFixture::customer()->id;
                $base = 1590000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 10;
                $order = function ($customer, $createdAt, $paid, $shipping = 0) use ($fcOrdersConsistencyCurrency, &$orderIds) {
                    $row = FcFixture::reportOrder([
                        'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'currency' => $fcOrdersConsistencyCurrency,
                        'customer_id' => $customer, 'type' => Status::ORDER_TYPE_PAYMENT, 'subtotal' => $paid - $shipping,
                        'shipping_total' => $shipping, 'tax_total' => 0, 'total_amount' => $paid, 'total_paid' => $paid, 'total_refund' => 0,
                        'created_at' => $createdAt, 'updated_at' => $createdAt,
                    ]);
                    $orderIds[] = (int) $row->id;
                };

                // Alice: a free order in January, her first paid purchase in March: new, as on the Customers report
                $order($base, '2001-01-05 10:00:00', 0);
                $order($base, '2001-03-02 10:00:00', 1000);
                // Bob bought in February: returning
                $order($base + 1, '2001-02-05 10:00:00', 500);
                $order($base + 1, '2001-03-03 10:00:00', 800);
                // Carol: only a free order
                $order($base + 2, '2001-03-04 10:00:00', 0);
                // Dave: 12.00 paid, 2.00 of it shipping; his item row is dated the day before his order
                $order($base + 3, '2001-03-05 10:00:00', 1200, 200);
                FcFixture::reportOrderItem(end($orderIds), ['quantity' => 2, 'unit_price' => 500, 'subtotal' => 1000, 'line_total' => 1000,
                    'created_at' => '2001-02-28 23:00:00']);

                $params = [
                    'startDate' => new DateTime('2001-03-01 00:00:00'), 'endDate' => new DateTime('2001-03-31 23:59:59'),
                    'currency' => $fcOrdersConsistencyCurrency, 'paymentStatus' => Status::getReportStatuses(), 'groupKey' => 'monthly', 'variationIds' => [],
                ];
                $service = OrderReportService::make();

                $types = [];
                $net = [];
                foreach ($service->getNewVsReturningCustomer($params) as $row) {
                    $types[$row->customer_type] = [$row->customer_count, $row->order_count];
                    $net[$row->customer_type] = round((float) $row->net_sales, 2);
                }
                FcTest::assertSame([[2, 2], [1, 1], [1, 1]], [$types['New'] ?? null, $types['Returning'] ?? null, $types['Free order'] ?? null],
                    'New: Alice and Dave; returning: Bob; Carol\'s free order is its own type');
                FcTest::assertSame(20.0, $net['New'], 'New customers\' net sales leave Dave\'s shipping out: 10.00 + 10.00');

                $chart = $service->getOrderLineChart($params);
                $march = array_column($chart['chartData'], null, 'group')['2001-03'];
                FcTest::assertSame([28.0, 7.0, 7.0], [round((float) $chart['summary']['net_sales'], 2), round((float) $chart['summary']['average_net'], 2),
                    round((float) $march['average_net'], 2)],
                    'Net sales 28.00 (shipping left out) over 4 orders: an average of 7.00, as on the Overview');
                FcTest::assertSame(28.0, round((float) $march['net_sales'], 2), 'The chart point carries the same net sales');

                if (class_exists(\FluentCartPro\App\Modules\Reports\Services\OrderPatternsService::class)) {
                    // Item count is a Pro chart
                    $items = array_map(function ($row) {
                        return [(int) $row->item_count, (int) $row->order_count];
                    }, \FluentCartPro\App\Modules\Reports\Services\OrderPatternsService::make()->getItemCountDistribution($params));
                    FcTest::assertSame([[2, 1]], $items, 'Items count by their order\'s date, as the chart does: Dave\'s order with 2 items');
                }
            } finally {
                if ($owner && $orderIds) {
                    $db->table('fct_orders')->whereIn('id', $orderIds)->update(['customer_id' => $owner]);
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
