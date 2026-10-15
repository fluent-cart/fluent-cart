<?php
/**
 * Retention month arithmetic closes: start + new - churned - churned_new = end,
 * and retention % + churn % = 100 for the starting base.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\SubscriptionReportService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcRetentionMathsCurrency = 'XTS';

$fcRetentionMathsSeed = function (array $plans) use ($fcRetentionMathsCurrency) {
    $db = App::db();
    $foreign = (int) $db->table('fct_subscriptions')->where('currency', $fcRetentionMathsCurrency)->count()
        + (int) $db->table('fct_orders')->where('currency', $fcRetentionMathsCurrency)->count();
    if ($foreign !== 0) {
        throw new RuntimeException(
            'Refusing to seed: ' . $foreign . ' existing rows already use currency ' . $fcRetentionMathsCurrency
        );
    }

    $baseKey = 1700000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 4;
    FcFixture::customer();

    $orderAttributes = function ($createdAt) use ($fcRetentionMathsCurrency) {
        return [
            'status'         => Status::ORDER_COMPLETED,
            'payment_status' => 'paid',
            'currency'       => $fcRetentionMathsCurrency,
            'type'           => Status::ORDER_TYPE_SUBSCRIPTION,
            'subtotal'       => 1000,
            'tax_total'      => 0,
            'total_amount'   => 1000,
            'total_paid'     => 1000,
            'completed_at'   => $createdAt,
            'created_at'     => $createdAt,
            'updated_at'     => $createdAt,
        ];
    };

    foreach ($plans as $index => $plan) {
        $order = FcFixture::reportOrder($orderAttributes($plan['created_at']));

        $productId = $baseKey + ($index * 2);
        $subscription = FcDomainFixture::subscription(
            $order,
            $productId,
            $productId + 1,
            $plan['status'],
            'retention-maths-' . $plan['suffix'],
            [
                'currency'          => $fcRetentionMathsCurrency,
                'billing_interval'  => 'monthly',
                'recurring_amount'  => 1000,
                'recurring_total'   => 1000,
                'bill_times'        => 0,
                'bill_count'        => 1,
                'canceled_at'       => $plan['canceled_at'],
                'expire_at'         => $plan['expire_at'],
                'next_billing_date' => gmdate('Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS),
            ]
        );

        $updated = $db->table('fct_subscriptions')
            ->where('id', (int) $subscription->id)
            ->update(['created_at' => $plan['created_at']]);
        if ((int) $updated !== 1) {
            throw new RuntimeException('Subscription fixture created_at update touched ' . (int) $updated . ' rows.');
        }

        if (!empty($plan['renewal_at'])) {
            FcFixture::reportOrder(array_merge($orderAttributes($plan['renewal_at']), [
                'type'      => Status::ORDER_TYPE_RENEWAL,
                'parent_id' => (int) $order->id,
            ]));
        }
    }
};

return [
    [
        'id'            => 'reports-retention-maths',
        'name'          => 'Retention month closes: start + new - churned - churned_new = end and retention % + churn % = 100',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcRetentionMathsSeed, $fcRetentionMathsCurrency) {
            try {
                FcFixture::assertReportRangesEmpty();
                // March 2001 is the month under test. Starting base: active + canceled-in-month.
                $fcRetentionMathsSeed([
                    ['suffix' => 'active', 'status' => Status::SUBSCRIPTION_ACTIVE, 'created_at' => '2001-02-10 10:00:00', 'canceled_at' => null, 'expire_at' => null, 'renewal_at' => '2001-03-10 10:00:00'],
                    ['suffix' => 'canceled-in-month', 'status' => Status::SUBSCRIPTION_CANCELED, 'created_at' => '2001-02-10 10:00:00', 'canceled_at' => '2001-03-15 10:00:00', 'expire_at' => null],
                    // Left when it expired in February; the later canceled_at stamp is not a March loss.
                    ['suffix' => 'expired-before-month', 'status' => Status::SUBSCRIPTION_CANCELED, 'created_at' => '2001-01-10 10:00:00', 'canceled_at' => '2001-03-05 10:00:00', 'expire_at' => '2001-02-20 10:00:00'],
                    ['suffix' => 'new-and-churned', 'status' => Status::SUBSCRIPTION_CANCELED, 'created_at' => '2001-03-10 10:00:00', 'canceled_at' => '2001-03-20 10:00:00', 'expire_at' => null],
                    // Never started: excluded from every count.
                    ['suffix' => 'ghost', 'status' => 'incomplete_expired', 'created_at' => '2001-02-10 10:00:00', 'canceled_at' => null, 'expire_at' => null],
                    ['suffix' => 'intended', 'status' => Status::SUBSCRIPTION_INTENDED, 'created_at' => '2001-03-12 10:00:00', 'canceled_at' => null, 'expire_at' => null],
                ]);

                $rows = SubscriptionReportService::make()->getRetentionData([
                    'startDate'        => new DateTime('2001-03-01 00:00:00'),
                    'endDate'          => new DateTime('2001-03-31 23:59:59'),
                    'groupKey'         => 'monthly',
                    'currency'         => $fcRetentionMathsCurrency,
                    'paymentStatus'    => ['paid'],
                    'orderStatus'      => ['on-hold', 'failed'],
                    'orderTypes'       => null,
                    'variationIds'     => [],
                    'comparePeriod'    => null,
                    'subscriptionType' => null,
                ]);

                FcTest::assertSame(1, count($rows), 'GET /reports/subscription-retention March 2001 yields one month row');
                $row = $rows[0];

                $start = (int) $row['start_subscriptions'];
                $new = (int) $row['new_subscriptions'];
                $churned = (int) $row['churned_subscriptions'];
                $churnedNew = (int) $row['churned_new_subscriptions'];
                $end = (int) $row['active_subscriptions'];

                FcTest::assertSame(2, $start, 'GET /reports/subscription-retention starting base excludes the ghost and the subscription that expired before the month');
                FcTest::assertSame(1, $churned, 'GET /reports/subscription-retention churned counts only losses from the starting base');
                FcTest::assertSame(
                    $end,
                    $start + $new - $churned - $churnedNew,
                    'GET /reports/subscription-retention start + new - churned - churned_new = end'
                );

                $retentionRate = (float) $row['retention_rate'];
                $churnRate = $start > 0 ? ($churned / $start) * 100 : 0;
                FcTest::assertSame(
                    100.0,
                    round($retentionRate + $churnRate, 2),
                    'GET /reports/subscription-retention retention % + churn % = 100 over the starting base'
                );

                $service = SubscriptionReportService::make();
                $quarterParams = [
                    'startDate'        => new DateTime('2001-01-01 00:00:00'),
                    'endDate'          => new DateTime('2001-03-31 23:59:59'),
                    'groupKey'         => null,
                    'currency'         => $fcRetentionMathsCurrency,
                    'paymentMode'      => null,
                    'variationIds'     => [],
                    'comparePeriod'    => null,
                    'paymentStatus'    => Status::getReportStatuses(),
                    'subscriptionType' => Status::ORDER_TYPE_SUBSCRIPTION,
                ];

                $signups = [];
                foreach ($service->getDailySignups($quarterParams) as $signupRow) {
                    $signups[(string) $signupRow->trend_date] = (int) $signupRow->value;
                }
                FcTest::assertSame(
                    ['2001-01-10' => 1, '2001-02-10' => 2, '2001-03-10' => 1],
                    $signups,
                    'GET /reports/subscription-daily-signups counts only started subscriptions per day, leaving out the ghost and the intended row'
                );
                FcTest::assertSame(4, array_sum($signups), 'GET /reports/subscription-daily-signups total counts only started subscriptions');

                $buckets = array_map('intval', (array) $service->getRetentionChart($quarterParams));
                FcTest::assertSame(
                    ['day_7' => 0, 'day_15' => 1, 'day_30' => 0, 'day_90' => 2, 'day_180' => 0, 'day_365' => 0, 'more_than_year' => 1],
                    $buckets,
                    'GET /reports/subscription-retention-chart lifespan buckets count only started subscriptions'
                );
                FcTest::assertSame(4, array_sum($buckets), 'GET /reports/subscription-retention-chart lifespan bucket total matches the started subscriptions');

                $withinCentury = (array) $service->getRetentionChart(array_merge($quarterParams, ['customDays' => 36500]));
                FcTest::assertSame(
                    4,
                    (int) $withinCentury['day_36500'],
                    'GET /reports/subscription-retention-chart customDays count leaves out the ghost and the intended row'
                );
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
