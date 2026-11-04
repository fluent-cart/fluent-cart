<?php
/**
 * Payment methods overview: payment success rate (paid orders against declined charges), disputed orders and renewal sales per gateway, the previous period's
 * totals, net sales by gateway over time, and sales by card brand or method where the gateway records one.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\PaymentMethodReportService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcMethodOverviewCurrency = 'XTS';

return [
    [
        'id'            => 'reports-payment-methods-overview',
        'name'          => 'Payment methods overview: success rate, dispute rate, renewals, previous period, chart, card brands',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC',
        'run'           => function () use ($fcMethodOverviewCurrency) {
            $db = App::db();
            $orderIds = [];
            try {
                $foreign = (int) $db->table('fct_orders')->where('currency', $fcMethodOverviewCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' rows already use currency ' . $fcMethodOverviewCurrency);
                }
                FcFixture::customer();

                $order = function ($gateway, $createdAt, $paid, array $attributes = []) use ($fcMethodOverviewCurrency, &$orderIds) {
                    $row = FcFixture::reportOrder(array_merge([
                        'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'payment_method' => $gateway,
                        'currency' => $fcMethodOverviewCurrency, 'type' => Status::ORDER_TYPE_PAYMENT, 'subtotal' => $paid, 'tax_total' => 0,
                        'total_amount' => $paid, 'total_paid' => $paid, 'total_refund' => 0, 'created_at' => $createdAt, 'updated_at' => $createdAt,
                    ], $attributes));
                    $orderIds[] = (int) $row->id;

                    return $row;
                };
                $transaction = function ($row, $type, $status, $brand = null, $methodType = '', array $meta = []) use ($db, $fcMethodOverviewCurrency) {
                    $db->table('fct_order_transactions')->insert([
                        'order_id' => $row->id, 'order_type' => 'payment', 'transaction_type' => $type, 'payment_method' => $row->payment_method,
                        'payment_mode' => 'test', 'status' => $status, 'currency' => $fcMethodOverviewCurrency, 'total' => $row->total_paid,
                        'rate' => 1, 'card_brand' => $brand, 'payment_method_type' => $methodType, 'uuid' => md5(uniqid('', true)),
                        'meta' => wp_json_encode($meta ?: new stdClass()), 'created_at' => $row->created_at, 'updated_at' => $row->created_at,
                    ]);
                };

                // March, Stripe: a Visa order, a Mastercard order now disputed, a Cash App renewal, and a failed order
                $transaction($order('stripe', '2001-03-02 10:00:00', 1000), 'charge', Status::TRANSACTION_SUCCEEDED, 'visa', 'card');
                $transaction($order('stripe', '2001-03-03 10:00:00', 2000), 'dispute', 'failed', 'Mastercard', 'card');
                $transaction($order('stripe', '2001-03-04 10:00:00', 500, ['type' => Status::ORDER_TYPE_RENEWAL]), 'charge',
                    Status::TRANSACTION_SUCCEEDED, null, 'cashapp');
                // ...and a declined charge on an order left unpaid: the decline counts, the order's status does not
                $transaction($order('stripe', '2001-03-05 10:00:00', 0, ['payment_status' => Status::PAYMENT_PENDING]), 'charge', Status::TRANSACTION_FAILED);
                // A dispute the store won: the gateway turned the transaction back into a charge
                $transaction($order('stripe', '2001-03-07 10:00:00', 800), 'charge', Status::TRANSACTION_SUCCEEDED, 'visa', 'card',
                    ['dispute_id' => 'du_won', 'dispute_status' => 'won']);
                // A dispute the store lost on the full charge: the order is now failed, outside the report statuses
                $transaction($order('stripe', '2001-03-08 10:00:00', 0, ['payment_status' => Status::PAYMENT_FAILED]), 'dispute', 'dispute_lost',
                    'visa', 'card', ['dispute_id' => 'du_lost', 'dispute_status' => 'lost']);
                // March, PayPal: the type field holds a transaction id, not a method
                $transaction($order('paypal', '2001-03-06 10:00:00', 1500), 'charge', Status::TRANSACTION_SUCCEEDED, null, '7S641211LE124673A');
                // February: one Stripe order for the previous period
                $order('stripe', '2001-02-10 10:00:00', 400);

                $params = [
                    'startDate' => new DateTime('2001-03-01 00:00:00'), 'endDate' => new DateTime('2001-03-31 23:59:59'),
                    'comparePeriod' => [new DateTime('2001-02-01 00:00:00'), new DateTime('2001-02-28 23:59:59')],
                    'currency' => $fcMethodOverviewCurrency, 'paymentStatus' => Status::getReportStatuses(), 'groupKey' => 'monthly', 'variationIds' => [],
                ];
                $report = PaymentMethodReportService::make()->getReport($params);
                $rows = array_column($report['rows'], null, 'gateway');
                $pick = function ($row) {
                    return [$row['orders'], $row['net_sales'], $row['renewal_net'], $row['success_rate']];
                };

                FcTest::assertSame([4, 43.0, 5.0, 80.0], $pick($rows['stripe']),
                    'Stripe: 4 paid orders and 1 declined charge, 5.00 of renewals');
                FcTest::assertSame([1, 15.0, 0.0, 100.0], $pick($rows['paypal']), 'PayPal: one paid order');
                FcTest::assertSame([5, 58.0, 5.0, 83.33, 50.0], array_merge($pick($report['totals']), [$report['totals']['dispute_rate']]),
                    'Totals: 5 paid orders and 1 declined charge; the store\'s dispute rate (3 of 6 orders) stays for Needs Attention');
                if (class_exists(\FluentCartPro\App\Modules\Reports\Services\PaymentInsightsService::class)) {
                    $disputes = \FluentCartPro\App\Modules\Reports\Services\PaymentInsightsService::make()->getDisputes($params);
                    $byGateway = array_column($disputes['rows'], null, 'gateway');
                    FcTest::assertSame([[3, 60.0], [0, 0.0], [3, 50.0]], [
                        [$byGateway['stripe']['disputed_orders'], $byGateway['stripe']['dispute_rate']],
                        [$byGateway['paypal']['disputed_orders'], $byGateway['paypal']['dispute_rate']],
                        [$disputes['totals']['disputed_orders'], $disputes['totals']['dispute_rate']],
                    ], 'Pro: 3 of 5 Stripe orders disputed (open, won and lost), none on PayPal, 3 of 6 in all');
                }
                FcTest::assertSame([1, 4.0], [$report['previous']['orders'], $report['previous']['net_sales']], 'February: one order, 4.00');

                $march = array_column($report['chart'], null, 'group')['2001-03'];
                FcTest::assertSame(['paypal' => 15.0, 'stripe' => 43.0], $march['gateways'], 'The chart\'s March point: net sales by gateway');

                // Card brands and methods are a Pro report
                if (!class_exists(\FluentCartPro\App\Modules\Reports\Services\PaymentInsightsService::class)) {
                    FcTest::assertSame(false, isset($report['methods']), 'Pro absent: no card brands in the free response');

                    return;
                }
                $report['methods'] = \FluentCartPro\App\Modules\Reports\Services\PaymentInsightsService::make()->getMethods($params);
                $methods = array_column($report['methods']['rows'], null, 'method');
                FcTest::assertSame(['mastercard', 'visa', 'cashapp'], array_keys($methods), 'Recorded methods by net sales; the PayPal id is not a method');
                FcTest::assertSame([1, 20.0, 'Mastercard'], [$methods['mastercard']['orders'], $methods['mastercard']['net_sales'], $methods['mastercard']['label']],
                    'A brand is matched in any letter case');
                FcTest::assertSame([2, 18.0], [$methods['visa']['orders'], $methods['visa']['net_sales']], 'Visa: two paid orders');
                FcTest::assertSame([4, 80.0], [$report['methods']['orders'], $report['methods']['coverage']], 'A method is recorded for 4 of the 5 orders');
            } finally {
                if ($orderIds) {
                    $db->table('fct_order_transactions')->whereIn('order_id', $orderIds)->delete();
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
