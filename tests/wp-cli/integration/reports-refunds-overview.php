<?php
/**
 * Refunds overview: money out on the refund date (cards, chart, full vs partial, sale type, payment method, reasons,
 * days to refund) and refund rates on the order date (orders placed in the period, and per product).
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\RefundReportService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcRefundCurrency = 'XTS';

return [
    [
        'id'            => 'reports-refunds-overview',
        'name'          => 'Refunds overview counts money out by refund date and refund rates by order date',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC', // fixtures are UTC days; reports-site-timezone covers other zones
        'run'           => function () use ($fcRefundCurrency) {
            $transactionIds = [];
            try {
                $foreign = (int) App::db()->table('fct_orders')->where('currency', $fcRefundCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' orders already use currency ' . $fcRefundCurrency);
                }
                FcFixture::customer();
                $variation = 1900000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 4;
                $order = function (array $attributes, array $line) use ($fcRefundCurrency) {
                    $row = FcFixture::reportOrder(array_merge([
                        'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'currency' => $fcRefundCurrency,
                        'type' => Status::ORDER_TYPE_PAYMENT, 'payment_method' => 'stripe', 'total_refund' => 0,
                        'created_at' => '2025-07-03 10:00:00', 'updated_at' => '2025-07-03 10:00:00',
                    ], $attributes));
                    FcFixture::reportOrderItem((int) $row->id, [
                        'post_id' => $line[0], 'object_id' => $line[0], 'unit_price' => $line[1], 'subtotal' => $line[1],
                        'line_total' => $line[1], 'refund_total' => $line[2],
                    ]);

                    return $row;
                };
                $refund = function ($order, $cents, $at, $reason) use (&$transactionIds, $fcRefundCurrency) {
                    $transactionIds[] = (int) OrderTransaction::query()->create([
                        'order_id' => (int) $order->id, 'order_type' => (string) $order->type, 'payment_method' => 'stripe',
                        'payment_mode' => 'test', 'payment_method_type' => '', 'currency' => $fcRefundCurrency, 'rate' => 1,
                        'transaction_type' => Status::TRANSACTION_TYPE_REFUND, 'vendor_charge_id' => '', 'status' => Status::TRANSACTION_REFUNDED,
                        'total' => $cents, 'meta' => ['reason' => $reason], 'created_at' => $at, 'updated_at' => $at,
                    ])->id;
                };
                $money = function ($cents, $refunded = 0) {
                    return ['subtotal' => $cents, 'total_amount' => $cents, 'total_paid' => $cents, 'total_refund' => $refunded,
                        'payment_status' => $refunded >= $cents ? Status::PAYMENT_REFUNDED : ($refunded ? Status::PAYMENT_PARTIALLY_REFUNDED : Status::PAYMENT_PAID)];
                };

                $a = $order($money(10000, 10000), [$variation, 10000, 10000]);
                $refund($a, 10000, '2025-07-10 09:00:00', 'Not as described');
                $b = $order($money(5000, 2000), [$variation + 1, 5000, 2000]);
                $refund($b, 2000, '2025-07-12 09:00:00', '');
                $order($money(4000), [$variation, 4000, 0]);
                // Placed in June, refunded in July: in the money out, not in July's refund rate
                $d = $order(array_merge($money(3000, 3000), ['type' => Status::ORDER_TYPE_RENEWAL,
                    'created_at' => '2025-06-25 10:00:00', 'updated_at' => '2025-06-25 10:00:00']), [$variation + 2, 3000, 3000]);
                $refund($d, 3000, '2025-07-05 09:00:00', 'Not as described');

                // B gets a second partial refund in August: in August's money, not July's
                $refund($b, 500, '2025-08-02 09:00:00', 'Second thoughts');

                $params = [
                    'startDate' => new DateTime('2025-07-01 00:00:00'), 'endDate' => new DateTime('2025-07-31 23:59:59'),
                    'currency' => $fcRefundCurrency, 'paymentStatus' => Status::getReportStatuses(), 'variationIds' => [],
                    'groupKey' => 'daily',
                ];
                $report = RefundReportService::make()->getOverview($params);
                $s = $report['summary'];

                FcTest::assertSame([150.0, 3, 3, 50.0], [round((float) $s['refunded_amount'], 2), $s['refunds'], $s['refunded_orders'],
                    round((float) $s['average_refund'], 2)], 'Money out by refund date, the June order included');
                FcTest::assertSame([[2, 130.0], [1, 20.0]], [[$s['full']['count'], round((float) $s['full']['amount'], 2)],
                    [$s['partial']['count'], round((float) $s['partial']['amount'], 2)]], 'Full and partial refunds');
                FcTest::assertSame([100.0, 78.95], [round((float) $s['refund_rate_orders'], 2), round((float) $s['refund_rate_amount'], 2)],
                    'Refund rates: three orders refunded in July per three placed, 150 refunded per 190 sold');
                FcTest::assertSame(9.0, (float) $s['median_days'], 'Median days from order to first refund: 7, 9, 10');

                $chart = array_column($report['chart'], 'amount', 'group');
                FcTest::assertSame([30.0, 100.0, 20.0], [round((float) ($chart['2025-07-05'] ?? -1), 2), round((float) ($chart['2025-07-10'] ?? -1), 2),
                    round((float) ($chart['2025-07-12'] ?? -1), 2)], 'Chart by refund date');
                // Refund rate per period: orders refunded in it / orders placed in it; no orders, no rate
                $daily = array_column($report['chart'], null, 'group');
                FcTest::assertSame([true, null, 0.0], [array_key_exists('refund_rate', $daily['2025-07-10'] ?? []), $daily['2025-07-10']['refund_rate'] ?? null,
                    (float) ($daily['2025-07-03']['refund_rate'] ?? -1)],
                    'Daily rate: a refund-only day has no rate; the order day had no refunds');
                $monthly = array_column(RefundReportService::make()->getOverview(array_merge($params, ['groupKey' => 'monthly']))['chart'], null, 'group');
                FcTest::assertSame([3, 3, 100.0], [(int) ($monthly['2025-07']['orders'] ?? -1), (int) ($monthly['2025-07']['refunded_orders'] ?? -1),
                    (float) ($monthly['2025-07']['refund_rate'] ?? -1)], 'July: 3 orders refunded, 3 placed');

                FcTest::assertSame(['payment' => 120.0, 'renewal' => 30.0], array_map(function ($v) {
                    return round((float) $v, 2);
                }, array_column($report['by_sale_type'], 'amount', 'key')), 'Refunds by sale type');
                FcTest::assertSame(['stripe' => 150.0], array_map(function ($v) {
                    return round((float) $v, 2);
                }, array_column($report['by_payment_method'], 'amount', 'key')), 'Refunds by payment method');

                FcTest::assertSame([[$variation, 71.43], [$variation + 1, 40.0]], array_map(function ($row) {
                    return [(int) $row['variation_id'], round((float) $row['refund_rate'], 2)];
                }, $report['by_product']), 'Products by refunded amount, July orders only');

                FcTest::assertSame([66.67, ['Not as described' => 2]], [round((float) $report['reasons']['coverage'], 2),
                    array_column($report['reasons']['top'], 'count', 'reason')], 'Reason coverage and top reasons');

                // Tax added on top: the refund holds the line's tax, so the sold side must too (100%, not 110%)
                $taxed = FcFixture::reportOrder([
                    'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_REFUNDED, 'currency' => $fcRefundCurrency,
                    'type' => Status::ORDER_TYPE_PAYMENT, 'tax_behavior' => 1, 'subtotal' => 10000, 'tax_total' => 1000,
                    'total_amount' => 11000, 'total_paid' => 11000, 'total_refund' => 11000,
                    'created_at' => '2025-11-03 10:00:00', 'updated_at' => '2025-11-03 10:00:00',
                ]);
                FcFixture::reportOrderItem((int) $taxed->id, ['post_id' => $variation + 9, 'object_id' => $variation + 9, 'unit_price' => 10000,
                    'subtotal' => 10000, 'line_total' => 10000, 'tax_amount' => 1000, 'refund_total' => 11000]);
                $november = RefundReportService::make()->getOverview(array_merge($params, [
                    'startDate' => new DateTime('2025-11-01 00:00:00'), 'endDate' => new DateTime('2025-11-30 23:59:59'),
                ]))['by_product'];
                FcTest::assertSame([100.0], array_column($november, 'refund_rate'), 'Tax-on-top line refunded in full: 100%');

                // Days to refund count from an order's first refund: refunded Sep 2 and again Oct 1, it belongs to September
                $e = $order(array_merge($money(6000, 2000), ['created_at' => '2025-09-01 10:00:00', 'updated_at' => '2025-09-01 10:00:00']), [$variation + 3, 6000, 2000]);
                $refund($e, 1000, '2025-09-02 09:00:00', '');
                $refund($e, 1000, '2025-10-01 09:00:00', '');
                $month = function ($from, $to) use ($params) {
                    return RefundReportService::make()->getOverview(array_merge($params, [
                        'startDate' => new DateTime($from . ' 00:00:00'), 'endDate' => new DateTime($to . ' 23:59:59'),
                    ]))['summary'];
                };
                FcTest::assertSame([1.0, 0.0, 1], [(float) $month('2025-09-01', '2025-09-30')['median_days'],
                    (float) $month('2025-10-01', '2025-10-31')['median_days'], $month('2025-10-01', '2025-10-31')['refunds']],
                    'September: 1 day to the first refund; October has a refund but no first refund');
                $october = $month('2025-10-01', '2025-10-31');
                FcTest::assertSame([null, null], [$october['refund_rate_orders'], $october['refund_rate_amount']],
                    'October has refunds but no orders placed: no refund rate');

                $route = FcTest::rest('GET', 'reports/refunds-overview', ['params' => [
                    'startDate' => '2025-07-01', 'endDate' => '2025-07-31', 'currency' => $fcRefundCurrency, 'groupKey' => 'daily',
                ]]);
                FcTest::assertSame([200, 3], [$route['status'], (int) ($route['data']['summary']['refunds'] ?? -1)], 'GET /reports/refunds-overview');
            } finally {
                if ($transactionIds) {
                    App::db()->table('fct_order_transactions')->whereIn('id', $transactionIds)->delete();
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
