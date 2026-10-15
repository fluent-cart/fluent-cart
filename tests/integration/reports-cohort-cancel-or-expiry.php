<?php
/**
 * Cohort snapshots: a subscriber stays in their cohort month, then counts as retained in a later
 * month only while a subscription is still running at that month's end.
 */

use FluentCart\App\Services\Report\RetentionSnapshotService;

$fcCohortSub = function ($status, $createdAt, $canceledAt, $expireAt) {
    return (object) [
        'id'               => 0,
        'customer_id'      => 1,
        'product_id'       => 1,
        'currency'         => 'USD',
        'status'           => $status,
        'billing_interval' => 'yearly',
        'recurring_amount' => 12000,
        'created_at'       => $createdAt,
        'canceled_at'      => $canceledAt,
        'expire_at'        => $expireAt,
        'last_payment'     => $createdAt,
    ];
};

return [
    [
        'id'            => 'reports-cohort-cancel-or-expiry',
        'name'          => 'Cohort snapshots drop a subscriber after cancellation or expiry and keep the cohort month whole',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcCohortSub) {
            $service = new class extends RetentionSnapshotService {
                public function stateFor(array $subscriptions, string $month): array
                {
                    return $this->getCustomerStateForMonth($subscriptions, $month);
                }
            };

            $retainedMonths = function (array $subscriptions) use ($service) {
                $months = [];
                for ($i = 0; $i < 14; $i++) {
                    $month = gmdate('Y-m', strtotime('2001-01-01 +' . $i . ' months'));
                    if ($service->stateFor($subscriptions, $month)['is_active']) {
                        $months[] = $month;
                    }
                }
                return implode(',', $months);
            };

            $everyMonth = '2001-01,2001-02,2001-03,2001-04,2001-05,2001-06,2001-07,2001-08,2001-09,2001-10,2001-11,2001-12,2002-01,2002-02';

            $canceled = [$fcCohortSub('canceled', '2001-01-15 10:00:00', '2001-02-20 10:00:00', null)];
            FcTest::assertSame(
                '2001-01',
                $retainedMonths($canceled),
                'Cohort snapshots drop a subscription cancelled in February from February onwards'
            );

            $expired = [$fcCohortSub('expired', '2001-01-10 10:00:00', '2001-06-01 10:00:00', '2001-04-10 10:00:00')];
            FcTest::assertSame(
                '2001-01,2001-02,2001-03',
                $retainedMonths($expired),
                'Cohort snapshots drop an expired subscription at the earlier of expiry and cancellation'
            );

            $active = [$fcCohortSub('active', '2001-01-05 10:00:00', null, '2001-02-01 10:00:00')];
            FcTest::assertSame(
                $everyMonth,
                $retainedMonths($active),
                'Cohort snapshots ignore a stale expire_at on an active subscription'
            );
            FcTest::assertSame(1000, $service->stateFor($active, '2001-07')['mrr'], 'Cohort snapshots count a retained yearly 12000 as 1000 MRR');

            $sameMonth = [$fcCohortSub('canceled', '2001-01-03 10:00:00', '2001-01-25 10:00:00', null)];
            FcTest::assertSame(
                '2001-01',
                $retainedMonths($sameMonth),
                'Cohort snapshots keep a subscription cancelled inside its cohort month in that cohort only'
            );
            FcTest::assertSame(1000, $service->stateFor($sameMonth, '2001-01')['mrr'], 'Cohort snapshots keep the cohort-month MRR of a same-month cancellation');
        },
    ],
];
