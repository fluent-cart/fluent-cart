<?php
/**
 * Retention months are clipped to the requested range: the first and last
 * month count only what falls inside startDate..endDate.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\SubscriptionReportService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcRetentionClipCurrency = 'XTS';

$fcRetentionClipSeed = function (array $plans) use ($fcRetentionClipCurrency) {
    $db = App::db();
    $foreign = (int) $db->table('fct_subscriptions')->where('currency', $fcRetentionClipCurrency)->count()
        + (int) $db->table('fct_orders')->where('currency', $fcRetentionClipCurrency)->count();
    if ($foreign !== 0) {
        throw new RuntimeException(
            'Refusing to seed: ' . $foreign . ' existing rows already use currency ' . $fcRetentionClipCurrency
        );
    }

    $baseKey = 1700000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 4;
    FcFixture::customer();

    foreach ($plans as $index => $plan) {
        $order = FcFixture::reportOrder([
            'status'         => Status::ORDER_COMPLETED,
            'payment_status' => 'paid',
            'currency'       => $fcRetentionClipCurrency,
            'type'           => Status::ORDER_TYPE_SUBSCRIPTION,
            'subtotal'       => 1000,
            'tax_total'      => 0,
            'total_amount'   => 1000,
            'total_paid'     => 1000,
            'completed_at'   => $plan['created_at'],
            'created_at'     => $plan['created_at'],
            'updated_at'     => $plan['created_at'],
        ]);

        $productId = $baseKey + ($index * 2);
        $subscription = FcDomainFixture::subscription(
            $order,
            $productId,
            $productId + 1,
            Status::SUBSCRIPTION_ACTIVE,
            'retention-clip-' . $plan['suffix'],
            [
                'currency'          => $fcRetentionClipCurrency,
                'billing_interval'  => 'monthly',
                'recurring_amount'  => 1000,
                'recurring_total'   => 1000,
                'bill_times'        => 0,
                'bill_count'        => 1,
                'next_billing_date' => gmdate('Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS),
            ]
        );

        $updated = $db->table('fct_subscriptions')
            ->where('id', (int) $subscription->id)
            ->update(['created_at' => $plan['created_at']]);
        if ((int) $updated !== 1) {
            throw new RuntimeException('Subscription fixture created_at update touched ' . (int) $updated . ' rows.');
        }
    }
};

return [
    [
        'id'            => 'reports-retention-range-clip',
        'name'          => 'Retention clips the first and last month to the requested start and end dates',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcRetentionClipSeed, $fcRetentionClipCurrency) {
            try {
                FcFixture::assertReportRangesEmpty();
                $fcRetentionClipSeed([
                    ['suffix' => 'before-start', 'created_at' => '2001-01-20 10:00:00'],
                    ['suffix' => 'after-end', 'created_at' => '2001-02-20 10:00:00'],
                ]);

                $rows = SubscriptionReportService::make()->getRetentionData([
                    'startDate'        => new DateTime('2001-01-25 00:00:00'),
                    'endDate'          => new DateTime('2001-02-15 23:59:59'),
                    'groupKey'         => 'monthly',
                    'currency'         => $fcRetentionClipCurrency,
                    'paymentStatus'    => ['paid'],
                    'orderStatus'      => ['on-hold', 'failed'],
                    'orderTypes'       => null,
                    'variationIds'     => [],
                    'comparePeriod'    => null,
                    'subscriptionType' => null,
                ]);

                FcTest::assertSame(
                    ['2001-01', '2001-02'],
                    array_map(function ($row) {
                        return (string) $row['group'];
                    }, $rows),
                    'GET /reports/subscription-retention yields one row per month the range touches'
                );

                FcTest::assertSame(
                    0,
                    (int) $rows[0]['new_subscriptions'],
                    'GET /reports/subscription-retention 2001-01 does not count a signup before the requested start'
                );
                FcTest::assertSame(
                    1,
                    (int) $rows[0]['start_subscriptions'],
                    'GET /reports/subscription-retention 2001-01 starts with the subscription that existed at the requested start'
                );
                FcTest::assertSame(
                    0,
                    (int) $rows[1]['new_subscriptions'],
                    'GET /reports/subscription-retention 2001-02 does not count a signup after the requested end'
                );
                FcTest::assertSame(
                    '1',
                    $rows[1]['active_subscriptions'],
                    'GET /reports/subscription-retention 2001-02 active count stops at the requested end'
                );
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
