<?php
/**
 * Retention page: renewals per period, each lost renewal on its due date (the end of the last paid term), churn by
 * reason from the status a subscription left with, the billing interval filter, the yearly period and the flag on a
 * period still running.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\SubscriptionReportService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcRenewalCurrency = 'XTS';

return [
    [
        'id'            => 'reports-retention-renewals',
        'name'          => 'Retention renewals, churn reasons, interval filter, yearly period and running period',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC', // fixtures are UTC days; reports-site-timezone covers other zones
        'run'           => function () use ($fcRenewalCurrency) {
            try {
                $db = App::db();
                $foreign = (int) $db->table('fct_orders')->where('currency', $fcRenewalCurrency)->count()
                    + (int) $db->table('fct_subscriptions')->where('currency', $fcRenewalCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' rows already use currency ' . $fcRenewalCurrency);
                }
                FcFixture::customer();
                $base = 1650000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 20;
                $order = function (array $attributes) use ($fcRenewalCurrency) {
                    return FcFixture::reportOrder(array_merge([
                        'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'currency' => $fcRenewalCurrency,
                        'type' => Status::ORDER_TYPE_SUBSCRIPTION, 'subtotal' => 1000, 'total_amount' => 1000, 'total_paid' => 1000,
                    ], $attributes));
                };
                $sub = function ($n, $status, $createdAt, array $attributes, $updatedAt = null) use ($order, $base, $fcRenewalCurrency, $db) {
                    $parent = $order(['created_at' => $createdAt, 'updated_at' => $createdAt]);
                    $row = FcDomainFixture::subscription($parent, $base + $n * 2, $base + $n * 2 + 1, $status, 'retention-renewals-' . $n,
                        array_merge(['currency' => $fcRenewalCurrency, 'canceled_at' => null, 'recurring_total' => 1000], $attributes));
                    $db->table('fct_subscriptions')->where('id', (int) $row->id)
                        ->update(['created_at' => $createdAt, 'updated_at' => $updatedAt ?: $createdAt]);

                    return $parent;
                };
                $renewal = function ($parent, $at, $paidAt = null) use ($order) {
                    $order(['type' => Status::ORDER_TYPE_RENEWAL, 'parent_id' => (int) $parent->id, 'created_at' => $at, 'updated_at' => $at,
                        'completed_at' => $paidAt ?: $at]);
                };

                $a = $sub(1, Status::SUBSCRIPTION_ACTIVE, '2025-01-10 10:00:00', ['billing_interval' => 'monthly']);
                $b = $sub(2, Status::SUBSCRIPTION_CANCELED, '2025-01-05 10:00:00', ['billing_interval' => 'monthly', 'canceled_at' => '2025-02-20 10:00:00']);
                $sub(3, Status::SUBSCRIPTION_EXPIRED, '2024-03-01 10:00:00', ['billing_interval' => 'yearly', 'expire_at' => '2025-03-05 10:00:00']);
                $sub(4, Status::SUBSCRIPTION_PAST_DUE, '2024-02-15 10:00:00', ['billing_interval' => 'yearly', 'next_billing_date' => '2025-02-15 10:00:00']);
                $e = $sub(5, Status::SUBSCRIPTION_ACTIVE, '2024-03-20 10:00:00', ['billing_interval' => 'yearly']);
                $sub(6, Status::SUBSCRIPTION_PAUSED, '2025-01-01 10:00:00', ['billing_interval' => 'monthly'], '2025-03-25 10:00:00');
                // A plan that ran its course: it ended, but not because a payment failed
                $sub(7, Status::SUBSCRIPTION_COMPLETED, '2025-02-01 10:00:00', ['billing_interval' => 'yearly', 'expire_at' => '2025-03-12 10:00:00']);

                $renewal($a, '2025-02-10 10:00:00');
                // A store-billed invoice created ahead of its due date counts when paid
                $renewal($a, '2025-02-25 10:00:00', '2025-03-10 10:00:00');
                $renewal($b, '2025-02-05 10:00:00');
                $renewal($e, '2025-03-20 10:00:00');

                $params = function (array $extra = []) use ($fcRenewalCurrency) {
                    return array_merge([
                        'startDate' => new DateTime('2025-01-01 00:00:00'), 'endDate' => new DateTime('2025-03-31 23:59:59'),
                        'currency' => $fcRenewalCurrency, 'variationIds' => [], 'groupKey' => 'monthly',
                    ], $extra);
                };
                $service = SubscriptionReportService::make();
                $by = function (array $rows) {
                    return array_column($rows, null, 'group');
                };

                $rows = $by($service->getRetentionData($params()));
                $pick = function ($row) {
                    return [$row['renewals_paid'], $row['renewals_lost'], $row['renewals_retrying'], $row['renewal_rate'], $row['left_by_reason']];
                };
                FcTest::assertSame([0, 0, 0, null, ['canceled' => 0, 'payment_failed' => 0, 'retrying' => 0, 'paused' => 0, 'completed' => 0]], $pick($rows['2025-01']),
                    'January: no renewal due, so no renewal rate');
                FcTest::assertSame([2, 1, 1, 66.67, ['canceled' => 0, 'payment_failed' => 0, 'retrying' => 1, 'paused' => 0, 'completed' => 0]], $pick($rows['2025-02']),
                    'February: two paid; the paused plan was due on 1 Feb (lost); the past-due yearly is retrying (left out of the rate). '
                    . 'The plan canceled on 20 Feb had paid until 5 Mar, so it leaves then');
                FcTest::assertSame([2, 2, 0, 50.0, ['canceled' => 1, 'payment_failed' => 1, 'retrying' => 0, 'paused' => 1, 'completed' => 1]], $pick($rows['2025-03']),
                    'March: two paid; lost on their due dates: the plan canceled in February (5 Mar) and the yearly that expired unpaid (1 Mar). '
                    . 'The completed plan left as completed, not as a failed payment, and is no lost renewal');
                FcTest::assertSame([true, true, true], array_column(array_values($rows), 'is_complete'), 'Past months are complete');

                $monthly = $by($service->getRetentionData($params(['billingInterval' => 'monthly'])));
                FcTest::assertSame([[2, 66.67], [1, 50.0]], [
                    [$monthly['2025-02']['renewals_paid'], $monthly['2025-02']['renewal_rate']],
                    [$monthly['2025-03']['renewals_paid'], $monthly['2025-03']['renewal_rate']],
                ], 'Monthly plans only: February 2 paid of 3 due, March 1 of 2');
                FcTest::assertSame([[3, 3], [6, 5]], [
                    [$monthly['2025-02']['start_subscriptions'], $monthly['2025-03']['start_subscriptions']],
                    [$rows['2025-02']['start_subscriptions'], $rows['2025-03']['start_subscriptions'] - 1],
                ], 'The monthly base leaves the yearly plans out (March holds the completed yearly plan, and the plan canceled in February, still paid up)');

                $year = $service->getRetentionData($params(['period' => 'year', 'endDate' => new DateTime('2025-12-31 23:59:59')]));
                FcTest::assertSame(1, count($year), 'The yearly period gives one row for 2025');
                FcTest::assertSame(['2025', 3, 2, 33.33, 4, 4, 57.14], [$year[0]['group'], $year[0]['start_subscriptions'], $year[0]['churned_subscriptions'],
                    $year[0]['retention_rate'], $year[0]['new_subscriptions'], $year[0]['renewals_paid'], $year[0]['renewal_rate']],
                    '2025: 3 at the start (the 2024 plans), 2 of them left; 4 new (the completed plan too); 4 renewals paid (each payment counts, two from one plan), 3 lost, 1 retrying');

                // A range starting mid-month: that month is shown but is not a closed period
                $clipped = $service->getRetentionData($params(['startDate' => new DateTime('2025-02-10 00:00:00')]));
                FcTest::assertSame([[false, false], [true, false]], [
                    [$clipped[0]['is_complete'], $clipped[0]['is_running']], [$clipped[1]['is_complete'], $clipped[1]['is_running']],
                ], 'February from the 10th is partial, March is complete');
                $endClipped = $service->getRetentionData($params(['endDate' => new DateTime('2025-03-15 23:59:59')]));
                FcTest::assertSame([true, false, false], [$endClipped[1]['is_complete'], $endClipped[2]['is_complete'], $endClipped[2]['is_running']],
                    'A range ending on 15 March: February is complete, March is partial (not running)');

                $intervals = $service->retentionIntervals(['currency' => $fcRenewalCurrency]);
                sort($intervals);
                FcTest::assertSame(['monthly', 'yearly'], $intervals, 'The interval switch lists the intervals in use');
                FcTest::assertSame(['yearly'], $service->retentionIntervals(['currency' => $fcRenewalCurrency, 'variationIds' => [$base + 7]]),
                    'With a product filter, only the intervals of that product');

                // The current month is still running; the month before it is closed
                $running = $service->getRetentionData($params([
                    'startDate' => new DateTime(gmdate('Y-m-01 00:00:00', strtotime('first day of last month'))),
                    'endDate'   => new DateTime(gmdate('Y-m-t 23:59:59')),
                ]));
                FcTest::assertSame([[true, false], [false, true]], [[$running[0]['is_complete'], $running[0]['is_running']], [$running[1]['is_complete'], $running[1]['is_running']]],
                    'Last month is complete, this month is still running');

                $route = FcTest::rest('GET', 'reports/subscription-retention', ['params' => [
                    'startDate' => '2025-01-01', 'endDate' => '2025-03-31', 'currency' => $fcRenewalCurrency, 'billingInterval' => 'monthly',
                ]]);
                $routeRows = $by($route['data']['retention_data'] ?? []);
                FcTest::assertSame([200, 66.67, true], [$route['status'], $routeRows['2025-02']['renewal_rate'] ?? null, isset($route['data']['intervals'])],
                    'GET /reports/subscription-retention takes the interval and returns the interval list');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
