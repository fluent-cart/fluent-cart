<?php
/**
 * Payment methods report: gateways add up to Revenue, a saved-card order counts under the gateway that charged it,
 * failures and disputes come from the transactions, and failing subscriptions show MRR at risk.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\PaymentMethodReportService;
use FluentCart\App\Services\Report\RevenueReportService;

// No store row uses the ISO test currency, so the report scoped to it reads only these fixtures.
$fcGatewayCurrency = 'XTS';

return [
    [
        'id'            => 'reports-payment-methods',
        'name'          => 'Payment methods add up to Revenue and count saved cards, failures, disputes and failing subscriptions per gateway',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC', // fixtures are UTC days; reports-site-timezone covers other zones
        'run'           => function () use ($fcGatewayCurrency) {
            $db = App::db();
            $orderIds = [];
            try {
                $foreign = (int) $db->table('fct_orders')->where('currency', $fcGatewayCurrency)->count()
                    + (int) $db->table('fct_subscriptions')->where('currency', $fcGatewayCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' rows already use currency ' . $fcGatewayCurrency);
                }
                FcFixture::customer();
                $window = FcFixture::reportWindow();
                $at = gmdate('Y-m-d H:i:s', strtotime($window['start']) + HOUR_IN_SECONDS);
                $order = function ($gateway, array $attributes = []) use ($fcGatewayCurrency, $at, &$orderIds) {
                    $row = FcFixture::reportOrder(array_merge([
                        'status'         => Status::ORDER_COMPLETED,
                        'payment_status' => Status::PAYMENT_PAID,
                        'payment_method' => $gateway,
                        'currency'       => $fcGatewayCurrency,
                        'type'           => Status::ORDER_TYPE_PAYMENT,
                        'subtotal'       => 1000,
                        'tax_total'      => 0,
                        'total_amount'   => 1000,
                        'total_paid'     => 1000,
                        'total_refund'   => 0,
                        'created_at'     => $at,
                        'updated_at'     => $at,
                    ], $attributes));
                    $orderIds[] = (int) $row->id;

                    return $row;
                };
                $transaction = function ($orderId, $gateway, $type, $status, $total) use ($db, $fcGatewayCurrency, $at) {
                    $db->table('fct_order_transactions')->insert([
                        'order_id' => $orderId, 'order_type' => 'payment', 'transaction_type' => $type, 'payment_method' => $gateway,
                        'payment_mode' => 'test', 'status' => $status, 'currency' => $fcGatewayCurrency, 'total' => $total, 'rate' => 1,
                        'uuid' => md5(uniqid('', true)), 'meta' => '[]', 'created_at' => $at, 'updated_at' => $at,
                    ]);
                };

                $stripe = $order('stripe');
                $transaction($stripe->id, 'stripe', 'charge', Status::TRANSACTION_FAILED, 1000);
                $transaction($stripe->id, 'stripe', 'dispute', 'dispute_lost', 300);
                $order('paypal', ['payment_status' => Status::PAYMENT_PARTIALLY_REFUNDED, 'total_paid' => 2000, 'total_amount' => 2000, 'total_refund' => 500]);
                $saved = $order('fct_saved_card', ['total_paid' => 500, 'total_amount' => 500]);
                $transaction($saved->id, 'stripe', 'charge', Status::TRANSACTION_SUCCEEDED, 500);
                $order('stripe', ['payment_status' => Status::PAYMENT_FAILED, 'total_paid' => 0]);
                // A gateway that is not registered, as imported orders carry
                $order('manual_purchases', ['total_paid' => 100, 'total_amount' => 100]);

                // A failing yearly subscription renewing through PayPal: 12.00 a year is 1.00 of MRR at risk
                $parent = $order('paypal', ['type' => Status::ORDER_TYPE_SUBSCRIPTION, 'total_paid' => 1200, 'total_amount' => 1200]);
                $productId = 1900000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 2;
                FcDomainFixture::subscription($parent, $productId, $productId + 1, Status::SUBSCRIPTION_FAILING, 'gateway-failing', [
                    'currency' => $fcGatewayCurrency, 'billing_interval' => 'yearly', 'recurring_amount' => 1200,
                    'recurring_total' => 1200, 'bill_times' => 0, 'current_payment_method' => 'paypal',
                ]);

                $params = ['startDate' => new DateTime($window['start']), 'endDate' => new DateTime($window['end']),
                    'currency' => $fcGatewayCurrency, 'paymentStatus' => Status::getReportStatuses(), 'variationIds' => [], 'groupKey' => 'yearly'];
                $report = PaymentMethodReportService::make()->getReport($params);
                $rows = array_column($report['rows'], null, 'gateway');
                $pick = function ($row) {
                    return array_intersect_key($row, array_flip(['orders', 'gross_sales', 'refunds', 'net_sales', 'failed_orders',
                        'failed_charges', 'failing_subscriptions', 'mrr_at_risk']));
                };

                FcTest::assertSame([
                    'orders' => 2, 'gross_sales' => 15.0, 'refunds' => 0.0, 'net_sales' => 15.0, 'failed_orders' => 1,
                    'failed_charges' => 1, 'failing_subscriptions' => 0, 'mrr_at_risk' => 0.0,
                ], $pick($rows['stripe'] ?? []), 'Stripe: its own order plus the saved-card order it charged, and its failures');
                FcTest::assertSame([
                    'orders' => 2, 'gross_sales' => 32.0, 'refunds' => 5.0, 'net_sales' => 27.0, 'failed_orders' => 0,
                    'failed_charges' => 0, 'failing_subscriptions' => 1, 'mrr_at_risk' => 1.0,
                ], $pick($rows['paypal'] ?? []), 'PayPal: the partly refunded order, the subscription order and the failing subscription');
                FcTest::assertSame(false, isset($rows['fct_saved_card']), 'No raw saved-card row');
                FcTest::assertSame([false, false], [isset($rows['stripe']['disputes_lost']), isset($report['totals']['disputes_lost'])],
                    'Disputes by gateway are a Pro report: the free response leaves them out');
                if (class_exists(\FluentCartPro\App\Modules\Reports\Services\PaymentInsightsService::class)) {
                    $disputes = array_column(\FluentCartPro\App\Modules\Reports\Services\PaymentInsightsService::make()->getDisputes($params)['rows'], null, 'gateway');
                    FcTest::assertSame([[1, 3.0], [0, 0.0]], [
                        [$disputes['stripe']['disputes_lost'] ?? -1, $disputes['stripe']['dispute_amount'] ?? -1],
                        [$disputes['paypal']['disputes_lost'] ?? -1, $disputes['paypal']['dispute_amount'] ?? -1],
                    ], 'Pro: Stripe lost one dispute of 3.00, PayPal none');
                }
                FcTest::assertSame('Manual Purchases', $rows['manual_purchases']['label'] ?? null, 'An unregistered gateway gets a readable label');

                $route = FcTest::rest('GET', 'reports/payment-methods', ['params' => [
                    'startDate' => $window['start'], 'endDate' => $window['end'], 'currency' => $fcGatewayCurrency,
                    'paymentStatus' => Status::getReportStatuses(), 'orderTypes' => [Status::ORDER_TYPE_SUBSCRIPTION],
                ]]);
                $totals = $route['data']['totals'] ?? [];
                FcTest::assertSame([200, 1, 0], [$route['status'], (int) ($totals['orders'] ?? -1), (int) ($totals['failed_charges'] ?? -1)],
                    'GET /reports/payment-methods applies the Order Type filter to orders and failed charges');
                $disputeRoute = FcTest::rest('GET', 'reports/payment-methods/disputes', ['params' => [
                    'startDate' => $window['start'], 'endDate' => $window['end'], 'currency' => $fcGatewayCurrency,
                    'paymentStatus' => Status::getReportStatuses(), 'orderTypes' => [Status::ORDER_TYPE_SUBSCRIPTION],
                ]]);
                if (class_exists(\FluentCartPro\App\Modules\Reports\Services\PaymentInsightsService::class)) {
                    FcTest::assertSame([200, 0], [$disputeRoute['status'], (int) ($disputeRoute['data']['totals']['disputes_lost'] ?? -1)],
                        'GET /reports/payment-methods/disputes applies the Order Type filter to disputes');
                } else {
                    FcTest::assertSame(404, $disputeRoute['status'], 'Pro absent: no disputes route');
                }

                $revenue = RevenueReportService::make()->getRevenueData($params)['summary'];
                FcTest::assertSame(
                    [(int) $revenue['order_count'], round((float) $revenue['gross_sale'], 2), round((float) $revenue['net_revenue'], 2)],
                    [$report['totals']['orders'], $report['totals']['gross_sales'], $report['totals']['net_sales']],
                    'The gateways add up to Revenue'
                );
            } finally {
                if ($orderIds) {
                    $db->table('fct_order_transactions')->whereIn('order_id', $orderIds)->delete();
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
