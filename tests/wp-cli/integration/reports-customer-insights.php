<?php
/**
 * Customers report: repeat rates over customers with an order in the range, lifetime value up to the range end,
 * and the invariant that all-history lifetime value equals Revenue net.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\CustomerReportService;
use FluentCart\App\Services\Report\RevenueReportService;

// No store row uses the ISO test currency, so the report scoped to it reads only these fixtures.
$fcInsightsCurrency = 'XTS';

return [
    [
        'id'            => 'reports-customer-insights',
        'name'          => 'Customers report: repeat rates, lifetime value, top customers, and Σ lifetime value = Revenue net',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcInsightsCurrency) {
            $db = App::db();
            $extraCustomers = [];
            try {
                $foreign = (int) $db->table('fct_orders')->where('currency', $fcInsightsCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' orders already use currency ' . $fcInsightsCurrency);
                }
                $window = FcFixture::reportWindow();
                $start = strtotime($window['start']);
                $order = function ($customerId, array $attributes, $at) use ($fcInsightsCurrency) {
                    return FcFixture::reportOrder(array_merge([
                        'status'         => Status::ORDER_COMPLETED,
                        'payment_status' => Status::PAYMENT_PAID,
                        'currency'       => $fcInsightsCurrency,
                        'type'           => Status::ORDER_TYPE_PAYMENT,
                        'customer_id'    => $customerId,
                        'subtotal'       => 1000,
                        'tax_total'      => 0,
                        'total_amount'   => 1000,
                        'total_paid'     => 1000,
                        'total_refund'   => 0,
                        'created_at'     => $at,
                        'updated_at'     => $at,
                    ], $attributes));
                };
                $before = gmdate('Y-m-d H:i:s', $start - 30 * DAY_IN_SECONDS);
                $inside = gmdate('Y-m-d H:i:s', $start + HOUR_IN_SECONDS);
                $after = gmdate('Y-m-d H:i:s', strtotime($window['end']) + 30 * DAY_IN_SECONDS);

                $loyal = (int) FcFixture::customer()->id;
                $renewer = $extraCustomers[] = (int) $db->table('fct_customers')->insertGetId([
                    'email' => 'fc-insights-renewer-' . substr(md5(FcFixture::identity()), 0, 8) . '@example.test',
                    'first_name' => 'Renewer', 'status' => 'active', 'notes' => '', 'created_at' => $before, 'updated_at' => $before,
                ]);
                $refunded = $extraCustomers[] = (int) $db->table('fct_customers')->insertGetId([
                    'email' => 'fc-insights-refunded-' . substr(md5(FcFixture::identity()), 0, 8) . '@example.test',
                    'first_name' => 'Refunded', 'status' => 'active', 'notes' => '', 'created_at' => $before, 'updated_at' => $before,
                ]);

                // Two purchases: one before the range, one inside it; an order after the range is not history yet
                $order($loyal, [], $before);
                $order($loyal, ['total_paid' => 2000, 'total_amount' => 2000], $inside);
                $order($loyal, [], $after);
                // A purchase and a renewal: repeat on any order, not on new purchases
                $parent = $order($renewer, [], $before);
                $order($renewer, ['type' => Status::ORDER_TYPE_RENEWAL, 'parent_id' => (int) $parent->id], $inside);
                // A fully refunded order still makes a customer, worth 0
                $order($refunded, ['payment_status' => Status::PAYMENT_REFUNDED, 'total_refund' => 1000], $inside);

                $service = CustomerReportService::make();
                $range = ['startDate' => new DateTime($window['start']), 'endDate' => new DateTime($window['end']), 'currency' => $fcInsightsCurrency];
                $insights = $service->getInsights($range);

                FcTest::assertSame(
                    ['customers' => 3, 'repeat_any' => 2, 'repeat_any_rate' => 66.67, 'repeat_new' => 1, 'repeat_new_rate' => 33.33,
                        'lifetime_net' => 50.0, 'average_ltv' => 16.67, 'range_net' => 30.0, 'average_range_net' => 10.0],
                    $insights['summary'],
                    'Customers with an order in the range, their history up to the range end'
                );
                FcTest::assertSame([[$loyal, 30.0, 2], [$renewer, 20.0, 2], [$refunded, 0.0, 1]], array_map(function ($row) {
                    return [$row['customer_id'], $row['lifetime_net'], $row['orders']];
                }, $insights['top_customers']), 'Top customers by lifetime value');

                // All history: every customer's lifetime value adds up to the Revenue report's net
                $allTime = ['startDate' => new DateTime('2000-01-01 00:00:00'), 'endDate' => new DateTime('2035-12-31 23:59:59'),
                    'currency' => $fcInsightsCurrency, 'groupKey' => 'yearly', 'paymentStatus' => Status::getReportStatuses()];
                $revenue = RevenueReportService::make()->getRevenueData($allTime);
                FcTest::assertSame(
                    round((float) $revenue['summary']['net_revenue'], 2),
                    $service->getInsights($allTime)['summary']['lifetime_net'],
                    'Σ lifetime value over all history = Revenue net'
                );
            } finally {
                // Own rows first, by exact id: the shared cleanup only knows the fixture customer
                if ($extraCustomers) {
                    $db->table('fct_orders')->whereIn('customer_id', $extraCustomers)->where('currency', $fcInsightsCurrency)->delete();
                    $db->table('fct_customers')->whereIn('id', $extraCustomers)->delete();
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
