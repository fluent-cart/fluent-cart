<?php
/**
 * Reports tell installment plans apart from subscriptions: an installment plan (bill_times > 0) is not recurring,
 * so its first payment is an "installment" sign-up and its later payments are "installment_payment", in the order
 * mix, net sales by order type, New vs Returning and the Order Type filter.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\OrderReportService;
use FluentCart\App\Services\Report\RevenueReportService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcSaleCurrency = 'XTS';

return [
    [
        'id'            => 'reports-sale-types',
        'name'          => 'Installment sign-ups and installment payments are their own sale types across reports and the Order Type filter',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC', // fixtures are UTC days; reports-site-timezone covers other zones
        'run'           => function () use ($fcSaleCurrency) {
            try {
                $db = App::db();
                $foreign = (int) $db->table('fct_orders')->where('currency', $fcSaleCurrency)->count()
                    + (int) $db->table('fct_subscriptions')->where('currency', $fcSaleCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' rows already use currency ' . $fcSaleCurrency);
                }
                FcFixture::customer();
                $order = function (array $attributes) use ($fcSaleCurrency) {
                    return FcFixture::reportOrder(array_merge([
                        'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'currency' => $fcSaleCurrency,
                        'type' => Status::ORDER_TYPE_PAYMENT, 'subtotal' => 1000, 'total_amount' => 1000, 'total_paid' => 1000,
                        'created_at' => '2025-07-03 10:00:00', 'updated_at' => '2025-07-03 10:00:00',
                    ], $attributes));
                };
                $baseKey = 1800000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 4;
                $plan = function ($parent, $billTimes, $offset) use ($baseKey, $fcSaleCurrency) {
                    FcDomainFixture::subscription($parent, $baseKey + $offset, $baseKey + $offset + 1, Status::SUBSCRIPTION_ACTIVE,
                        'sale-types-' . $offset, [
                            'currency' => $fcSaleCurrency, 'billing_interval' => 'monthly', 'recurring_amount' => 1000,
                            'recurring_total' => 1000, 'bill_times' => $billTimes, 'bill_count' => 2,
                        ]);
                };

                $order([]); // one-time
                $subscription = $order(['type' => Status::ORDER_TYPE_SUBSCRIPTION]);
                $plan($subscription, 0, 0);
                $order(['type' => Status::ORDER_TYPE_RENEWAL, 'parent_id' => (int) $subscription->id]);
                $installment = $order(['type' => Status::ORDER_TYPE_SUBSCRIPTION]);
                $plan($installment, 3, 2);
                $order(['type' => Status::ORDER_TYPE_RENEWAL, 'parent_id' => (int) $installment->id]);
                $order(['type' => Status::ORDER_TYPE_RENEWAL, 'parent_id' => (int) $installment->id]);

                $params = [
                    'startDate' => new DateTime('2025-07-01 00:00:00'), 'endDate' => new DateTime('2025-07-31 23:59:59'),
                    'currency' => $fcSaleCurrency, 'paymentStatus' => Status::getReportStatuses(), 'variationIds' => [],
                    'groupKey' => 'monthly',
                ];
                $expected = ['installment_payment' => 2, 'installment' => 1, 'payment' => 1, 'renewal' => 1, 'subscription' => 1];

                $mix = OrderReportService::make()->getOrderMix($params);
                FcTest::assertSame($expected, array_column($mix['types'], 'orders', 'key'), 'Order mix: five sale types');

                $byType = RevenueReportService::make()->getRevenueByOrderType($params);
                FcTest::assertSame($expected, array_column($byType, 'orders', 'key'), 'Net sales by order type: five sale types');

                $filtered = function (array $types) use ($params) {
                    return (int) OrderReportService::make()->getOrderLineChart($params + ['orderTypes' => $types])['summary']['order_count'];
                };
                FcTest::assertSame([1, 1, 2, 3], [
                    $filtered(['renewal']), $filtered(['installment']), $filtered(['installment_payment']),
                    $filtered(['installment', 'installment_payment']),
                ], 'Order Type filter: renewal leaves out installment payments; installment types filter on their own');

                $returning = array_column(OrderReportService::make()->getNewVsReturningCustomer($params), 'order_count', 'customer_type');
                FcTest::assertSame([1, 2], [(int) ($returning['Renewal'] ?? -1), (int) ($returning['Installment payment'] ?? -1)],
                    'New vs Returning keeps installment payments apart from renewals');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
