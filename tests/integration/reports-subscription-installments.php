<?php
/**
 * Subscription reports count recurring subscriptions only; installment plans
 * (bill_times > 0) stay out.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\SubscriptionReportService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcInstallmentCurrency = 'XTS';

$fcInstallmentParams = function ($subscriptionType = null) use ($fcInstallmentCurrency) {
    $window = FcFixture::reportWindow();

    return [
        'startDate'        => new DateTime($window['start']),
        'endDate'          => new DateTime($window['end']),
        'groupKey'         => 'daily',
        'currency'         => $fcInstallmentCurrency,
        'paymentStatus'    => ['paid'],
        'orderStatus'      => ['on-hold', 'failed'],
        'orderTypes'       => null,
        'variationIds'     => [],
        'comparePeriod'    => null,
        'subscriptionType' => $subscriptionType,
    ];
};

$fcInstallmentSeed = function (array $plans) use ($fcInstallmentCurrency) {
    $db = App::db();
    $foreign = (int) $db->table('fct_subscriptions')->where('currency', $fcInstallmentCurrency)->count()
        + (int) $db->table('fct_orders')->where('currency', $fcInstallmentCurrency)->count();
    if ($foreign !== 0) {
        throw new RuntimeException(
            'Refusing to seed: ' . $foreign . ' existing rows already use currency ' . $fcInstallmentCurrency
        );
    }

    $baseKey = 1700000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 4;
    FcFixture::customer();

    $created = [];
    foreach ($plans as $index => $plan) {
        $order = FcFixture::reportOrder([
            'status'         => Status::ORDER_COMPLETED,
            'payment_status' => 'paid',
            'currency'       => $fcInstallmentCurrency,
            'type'           => Status::ORDER_TYPE_SUBSCRIPTION,
            'subtotal'       => 1000,
            'tax_total'      => 0,
            'total_amount'   => 1000,
            'total_paid'     => 1000,
            'completed_at'   => '2001-02-03 10:00:00',
            'created_at'     => '2001-02-03 10:00:00',
            'updated_at'     => '2001-02-03 10:00:00',
        ]);

        if (!empty($plan['renewal'])) {
            FcFixture::reportOrder([
                'status'         => Status::ORDER_COMPLETED,
                'payment_status' => 'paid',
                'currency'       => $fcInstallmentCurrency,
                'type'           => Status::ORDER_TYPE_RENEWAL,
                'parent_id'      => (int) $order->id,
                'subtotal'       => 1000,
                'tax_total'      => 0,
                'total_amount'   => 1000,
                'total_paid'     => 1000,
                'completed_at'   => '2001-02-04 10:00:00',
                'created_at'     => '2001-02-04 10:00:00',
                'updated_at'     => '2001-02-04 10:00:00',
            ]);
        }

        $productId = $baseKey + ($index * 2);
        $subscription = FcDomainFixture::subscription(
            $order,
            $productId,
            $productId + 1,
            $plan['status'],
            'installments-' . $plan['suffix'],
            array_merge([
                'currency'          => $fcInstallmentCurrency,
                'billing_interval'  => 'yearly',
                'recurring_amount'  => 1000,
                'recurring_total'   => 1000,
                'bill_times'        => $plan['bill_times'],
                'bill_count'        => 1,
                'next_billing_date' => gmdate('Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS),
            ], isset($plan['attributes']) ? $plan['attributes'] : [])
        );

        $updated = $db->table('fct_subscriptions')
            ->where('id', (int) $subscription->id)
            ->update(['created_at' => '2001-02-03 10:00:00']);
        if ((int) $updated !== 1) {
            throw new RuntimeException('Subscription fixture created_at update touched ' . (int) $updated . ' rows.');
        }

        $created[$plan['suffix']] = $subscription;
    }

    return $created;
};

$fcInstallmentRetentionRow = function (array $rows, $group) {
    foreach ($rows as $row) {
        if ((string) $row['group'] === (string) $group) {
            return $row;
        }
    }

    throw new RuntimeException('Retention data is missing month ' . $group);
};

return [
    [
        'id'            => 'reports-subscription-installments-active',
        'name'          => 'Lifespan, signups, chart, retention and Future Renewals count the recurring subscription, not the installment plan',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcInstallmentParams, $fcInstallmentSeed, $fcInstallmentRetentionRow, $fcInstallmentCurrency) {
            try {
                FcFixture::assertReportRangesEmpty();
                $fcInstallmentSeed([
                    ['suffix' => 'active-recurring', 'status' => Status::SUBSCRIPTION_ACTIVE, 'bill_times' => 0, 'renewal' => true],
                    ['suffix' => 'active-installment', 'status' => Status::SUBSCRIPTION_ACTIVE, 'bill_times' => 3, 'renewal' => true],
                ]);
                $service = SubscriptionReportService::make();
                $params = $fcInstallmentParams();

                $lifespan = (array) $service->getRetentionChart($params);
                $lifespanTotal = 0;
                foreach ($lifespan as $bucket) {
                    $lifespanTotal += (int) $bucket;
                }
                FcTest::assertSame(
                    1,
                    $lifespanTotal,
                    'GET /reports/retention-chart lifespan histogram counts 1 recurring subscription'
                );

                $signups = 0;
                foreach ($service->getDailySignups($params) as $row) {
                    if ((string) $row->trend_date === '2001-02-03') {
                        $signups += (int) $row->value;
                    }
                }
                FcTest::assertSame(
                    1,
                    $signups,
                    'GET /reports/daily-signups 2001-02-03 counts 1 recurring signup'
                );

                FcTest::assertSame(
                    1,
                    (int) $service->getChartData($fcInstallmentParams(Status::ORDER_TYPE_SUBSCRIPTION))['totalSubscriptions'],
                    'GET /reports/subscription-chart subscription orders count 1 recurring parent order'
                );
                FcTest::assertSame(
                    1,
                    (int) $service->getChartData($fcInstallmentParams(Status::ORDER_TYPE_RENEWAL))['totalSubscriptions'],
                    'GET /reports/subscription-chart renewal orders count 1 recurring renewal, not the installment charge'
                );

                $month = $fcInstallmentRetentionRow($service->getRetentionData($params), '2001-02');
                FcTest::assertSame(
                    1,
                    (int) $month['new_subscriptions'],
                    'GET /reports/subscription-retention 2001-02 new_subscriptions counts 1 recurring'
                );
                FcTest::assertSame(
                    1,
                    (int) $month['active_subscriptions'],
                    'GET /reports/subscription-retention 2001-02 active_subscriptions counts 1 recurring'
                );
                FcTest::assertSame(
                    0.83,
                    round((float) $month['mrr'], 2),
                    'GET /reports/subscription-retention 2001-02 MRR is 10.00 yearly / 12 from the recurring subscription only'
                );

                $renewals = $service->getFutureRenewals(['currency' => $fcInstallmentCurrency]);
                FcTest::assertSame(
                    1,
                    (int) $renewals['totalRenewals'],
                    'GET /reports/future-renewals counts 1 recurring renewal in the next quarter'
                );
                FcTest::assertSame(
                    1000,
                    (int) $renewals['totalProjected'],
                    'GET /reports/future-renewals projects 1000 cents from the recurring subscription only'
                );
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-subscription-installments-ended',
        'name'          => 'Canceled and expired chart branches and retention churn count recurring subscriptions only',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcInstallmentParams, $fcInstallmentSeed, $fcInstallmentRetentionRow) {
            try {
                FcFixture::assertReportRangesEmpty();
                $canceled = ['canceled_at' => '2001-02-04 10:00:00'];
                $expired = ['expire_at' => '2001-02-04 12:00:00', 'next_billing_date' => null];
                $fcInstallmentSeed([
                    ['suffix' => 'canceled-recurring', 'status' => Status::SUBSCRIPTION_CANCELED, 'bill_times' => 0, 'attributes' => $canceled],
                    ['suffix' => 'canceled-installment', 'status' => Status::SUBSCRIPTION_CANCELED, 'bill_times' => 3, 'attributes' => $canceled],
                    ['suffix' => 'expired-recurring', 'status' => Status::SUBSCRIPTION_EXPIRED, 'bill_times' => 0, 'attributes' => $expired],
                    ['suffix' => 'expired-installment', 'status' => Status::SUBSCRIPTION_EXPIRED, 'bill_times' => 3, 'attributes' => $expired],
                ]);
                $service = SubscriptionReportService::make();

                FcTest::assertSame(
                    1,
                    (int) $service->getChartData($fcInstallmentParams(Status::SUBSCRIPTION_CANCELED))['totalSubscriptions'],
                    'GET /reports/subscription-chart canceled branch counts 1 recurring subscription'
                );
                FcTest::assertSame(
                    1,
                    (int) $service->getChartData($fcInstallmentParams(Status::SUBSCRIPTION_EXPIRED))['totalSubscriptions'],
                    'GET /reports/subscription-chart expired branch counts 1 recurring subscription'
                );

                $month = $fcInstallmentRetentionRow($service->getRetentionData($fcInstallmentParams()), '2001-02');
                FcTest::assertSame(
                    2,
                    (int) $month['churned_new_subscriptions'],
                    'GET /reports/subscription-retention 2001-02 churn of in-month sign-ups counts 1 canceled + 1 expired recurring subscription, installments excluded'
                );
                FcTest::assertSame(
                    0,
                    (int) $month['churned_subscriptions'],
                    'GET /reports/subscription-retention 2001-02 churn of the starting base counts none of these sign-ups'
                );
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-subscription-installments-future-installments',
        'name'          => 'Future Installments KPI keeps active and past_due plans and ignores a canceled plan',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcInstallmentParams, $fcInstallmentSeed) {
            try {
                FcFixture::assertReportRangesEmpty();
                $fcInstallmentSeed([
                    ['suffix' => 'kpi-active-installment', 'status' => Status::SUBSCRIPTION_ACTIVE, 'bill_times' => 3],
                    ['suffix' => 'kpi-past-due-installment', 'status' => Status::SUBSCRIPTION_PAST_DUE, 'bill_times' => 3],
                    ['suffix' => 'kpi-canceled-installment', 'status' => Status::SUBSCRIPTION_CANCELED, 'bill_times' => 3, 'attributes' => ['canceled_at' => '2001-02-04 10:00:00']],
                ]);

                $amount = SubscriptionReportService::make()
                    ->getFutureInstallments($fcInstallmentParams(Status::ORDER_TYPE_SUBSCRIPTION));
                FcTest::assertSame(
                    40.0,
                    round((float) $amount, 2),
                    'GET /reports/subscription-chart future_installments is 2 remaining bills x 10.00 on the active and past_due plans, not the canceled one'
                );
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-subscription-installments-future-renewals-by-variation',
        'name'          => 'Future Renewals splits the projection per variation and the rows sum to totalProjected',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcInstallmentSeed, $fcInstallmentCurrency) {
            try {
                FcFixture::assertReportRangesEmpty();
                $seeded = $fcInstallmentSeed([
                    ['suffix' => 'variant-small', 'status' => Status::SUBSCRIPTION_ACTIVE, 'bill_times' => 0, 'attributes' => ['recurring_total' => 1000]],
                    ['suffix' => 'variant-large', 'status' => Status::SUBSCRIPTION_ACTIVE, 'bill_times' => 0, 'attributes' => ['recurring_total' => 2500]],
                ]);

                $renewals = SubscriptionReportService::make()->getFutureRenewals(['currency' => $fcInstallmentCurrency]);
                $rows = isset($renewals['by_variation']) ? (array) $renewals['by_variation'] : [];

                $actual = [];
                $sum = 0;
                foreach ($rows as $row) {
                    $actual[] = [(int) $row['variation_id'], (string) $row['product_title'], (int) $row['renewals_count'], (int) $row['projected_amount']];
                    $sum += (int) $row['projected_amount'];
                }

                $expected = [];
                foreach (['variant-large', 'variant-small'] as $suffix) {
                    $subscription = $seeded[$suffix];
                    $expected[] = [(int) $subscription->variation_id, (string) $subscription->item_name, 1, 1 * (int) $subscription->recurring_total];
                }

                FcTest::assertSame(
                    $expected,
                    $actual,
                    'GET /reports/future-renewals by_variation has one row per seeded variation, largest projection first, named from item_name'
                );
                FcTest::assertSame(
                    (int) $renewals['totalProjected'],
                    $sum,
                    'GET /reports/future-renewals by_variation projected_amount sums to totalProjected'
                );
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
