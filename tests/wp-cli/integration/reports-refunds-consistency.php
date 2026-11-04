<?php
/**
 * Refunds report figures that match the other reports: the headline refund rate is the share of the period's orders
 * refunded in part or full; its change is in points; the weeks-to-refund chart counts first refunds made in the
 * period on orders of any date, as the Days to Refund card does.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\RefundReportService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcRefundsConsistencyCurrency = 'XTS';

return [
    [
        'id'            => 'reports-refunds-consistency',
        'name'          => 'Refunds report: cohort refund rate headline in points, weeks to refund by first refund in the period',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC',
        'run'           => function () use ($fcRefundsConsistencyCurrency) {
            $db = App::db();
            $orderIds = [];
            try {
                $foreign = (int) $db->table('fct_orders')->where('currency', $fcRefundsConsistencyCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' rows already use currency ' . $fcRefundsConsistencyCurrency);
                }
                FcFixture::customer();
                $order = function ($createdAt, $refund = 0) use ($fcRefundsConsistencyCurrency, &$orderIds) {
                    $row = FcFixture::reportOrder([
                        'status' => Status::ORDER_COMPLETED, 'payment_status' => $refund ? Status::PAYMENT_PARTIALLY_REFUNDED : Status::PAYMENT_PAID,
                        'currency' => $fcRefundsConsistencyCurrency, 'type' => Status::ORDER_TYPE_PAYMENT, 'subtotal' => 1000, 'tax_total' => 0,
                        'total_amount' => 1000, 'total_paid' => 1000, 'total_refund' => $refund, 'created_at' => $createdAt, 'updated_at' => $createdAt,
                    ]);
                    $orderIds[] = (int) $row->id;

                    return (int) $row->id;
                };
                $refund = function ($orderId, $at, $total) use ($db, $fcRefundsConsistencyCurrency) {
                    $db->table('fct_order_transactions')->insert([
                        'order_id' => $orderId, 'order_type' => 'payment', 'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
                        'payment_method' => 'stripe', 'payment_mode' => 'test', 'status' => Status::TRANSACTION_REFUNDED,
                        'currency' => $fcRefundsConsistencyCurrency, 'total' => $total, 'rate' => 1, 'uuid' => md5(uniqid('', true)),
                        'meta' => '[]', 'created_at' => $at, 'updated_at' => $at,
                    ]);
                };

                // January orders refunded in March: refunds in the period, not orders of it
                $refund($order('2001-01-05 10:00:00', 1000), '2001-03-10 10:00:00', 1000);
                $refund($order('2001-01-20 10:00:00', 1000), '2001-03-12 10:00:00', 1000);
                // March: four orders; one refunded in March, one in April
                $refund($order('2001-03-02 10:00:00', 1000), '2001-03-20 10:00:00', 1000);
                $order('2001-03-03 10:00:00');
                $refund($order('2001-03-04 10:00:00', 100), '2001-04-05 10:00:00', 100);
                $order('2001-03-05 10:00:00');
                // February: four orders, one refunded
                $refund($order('2001-02-02 10:00:00', 100), '2001-02-15 10:00:00', 100);
                $order('2001-02-03 10:00:00');
                $order('2001-02-04 10:00:00');
                $order('2001-02-05 10:00:00');

                $params = [
                    'startDate' => new DateTime('2001-03-01 00:00:00'), 'endDate' => new DateTime('2001-03-31 23:59:59'),
                    'currency' => $fcRefundsConsistencyCurrency, 'paymentStatus' => Status::getReportStatuses(), 'groupKey' => 'monthly', 'variationIds' => [],
                ];
                $summary = RefundReportService::make()->getOverview($params)['summary'];
                FcTest::assertSame([50.0, 75.0], [round((float) $summary['refund_rate_cohort'], 2), round((float) $summary['refund_rate_orders'], 2)],
                    'Headline: 2 of March\'s 4 orders refunded; refunds per order placed: 3 refunded orders over 4');

                $response = FcTest::rest('GET', 'reports/refunds-overview', ['params' => [
                    'startDate' => '2001-03-01 00:00:00', 'endDate' => '2001-03-31 23:59:59', 'currency' => $fcRefundsConsistencyCurrency,
                    'compareType' => 'previous_month', 'compareDate' => '2001-03-31 23:59:59',
                ]]);
                FcTest::assertSame(25.0, round((float) ($response['data']['fluctuations']['refund_rate_cohort'] ?? -1), 2),
                    'The rate\'s change is in points: 50% against February\'s 25%');

                if (class_exists(\FluentCartPro\App\Modules\Reports\Services\RefundTimingService::class)) {
                    // Weeks between order and refund is a Pro chart
                    $weeks = array_map(function ($row) {
                        return [(int) $row->weekBetween, (int) $row->occurrence];
                    }, \FluentCartPro\App\Modules\Reports\Services\RefundTimingService::make()->weeksBetweenRefund($params));
                    FcTest::assertSame([[3, 1], [8, 1], [10, 1]], $weeks,
                        'First refunds made in March, whatever the order date: 18, 51 and 64 days after the order');
                }
            } finally {
                if ($orderIds) {
                    $db->table('fct_order_transactions')->whereIn('order_id', $orderIds)->delete();
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
