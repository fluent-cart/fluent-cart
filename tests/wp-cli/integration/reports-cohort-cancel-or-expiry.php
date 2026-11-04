<?php
/**
 * Cohort snapshots: a subscriber counts in a month, the cohort month included, only while a subscription
 * is still running at that month's end (the month-end snapshot of MRR reports and the Retention table).
 */

use FluentCart\App\Services\Report\RetentionSnapshotService;

$fcCohortSub = function ($status, $createdAt, $canceledAt, $expireAt, $reason = 'refunded') {
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
        // A refund ends the subscription at once; a plain cancel keeps its paid term
        'config'           => json_encode(['cancellation_reason' => $reason]),
    ];
};

return [
    [
        'id'            => 'reports-cohort-cancel-or-expiry',
        'name'          => 'Cohort snapshots count subscribers running at each month end and drop them after cancellation or expiry',
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

            // Month ends are the store's: cancelled at 22:00 on Jan 31 in New York (Feb 1 03:00 UTC) is gone by January's end
            $lateJanuary = [$fcCohortSub('canceled', '2001-01-15 10:00:00', '2001-02-01 03:00:00', null)];
            FcTest::assertSame(['', '2001-01'], [
                FcReportFixture::inSiteTimezone('America/New_York', 0, function () use ($retainedMonths, $lateJanuary) {
                    return $retainedMonths($lateJanuary);
                }),
                FcReportFixture::inSiteTimezone('UTC', 0, function () use ($retainedMonths, $lateJanuary) {
                    return $retainedMonths($lateJanuary);
                }),
            ], 'Cohort month ends follow the WordPress timezone');

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
            // Changed with the month-end rule: a subscription that starts and ends inside one month has no MRR at
            // that month's end, so it joins no cohort (it was kept in its cohort month before)
            FcTest::assertSame(
                '',
                $retainedMonths($sameMonth),
                'Cohort snapshots leave out a subscription cancelled inside its first month'
            );
            FcTest::assertSame(0, $service->stateFor($sameMonth, '2001-01')['mrr'], 'Cohort snapshots give a same-month cancellation no MRR at the month end');

            // A plain cancel keeps the paid year: canceled in February, the yearly plan stays until 15 January 2002
            $keepsYear = [$fcCohortSub('canceled', '2001-01-15 10:00:00', '2001-02-20 10:00:00', null, 'cancelled_by_customer')];
            FcTest::assertSame(
                '2001-01,2001-02,2001-03,2001-04,2001-05,2001-06,2001-07,2001-08,2001-09,2001-10,2001-11,2001-12',
                $retainedMonths($keepsYear),
                'Cohort snapshots keep a plain cancel until its paid term ends, not past it'
            );

            // Paid on 31 January, a month runs to 28 February (as MySQL adds a month), not into March
            $monthEnd = $fcCohortSub('canceled', '2001-01-31 10:00:00', '2001-02-05 10:00:00', null, 'cancelled_by_customer');
            $monthEnd->billing_interval = 'monthly';
            FcTest::assertSame('2001-01', $retainedMonths([$monthEnd]), 'A month from 31 January ends on 28 February, so it is gone at February\'s end');
        },
    ],
];
