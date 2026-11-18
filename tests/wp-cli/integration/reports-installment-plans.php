<?php
/**
 * Installments report, revenue by variation, and installment plans in Future Renewals.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\InstallmentReportService;
use FluentCart\App\Services\Report\ProductReportService;
use FluentCart\App\Services\Report\ReportHelper;
use FluentCart\App\Services\Report\SubscriptionReportService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcPlanCurrency = 'XTS';

$fcPlanGuard = function () use ($fcPlanCurrency) {
    $db = App::db();
    $foreign = (int) $db->table('fct_subscriptions')->where('currency', $fcPlanCurrency)->count()
        + (int) $db->table('fct_orders')->where('currency', $fcPlanCurrency)->count();
    if ($foreign !== 0) {
        throw new RuntimeException('Refusing to seed: ' . $foreign . ' existing rows already use currency ' . $fcPlanCurrency);
    }
    FcFixture::customer();

    return 1800000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 4;
};

$fcPlanOrder = function (array $attributes) use ($fcPlanCurrency) {
    return FcFixture::reportOrder(array_merge([
        'status'         => Status::ORDER_COMPLETED,
        'payment_status' => Status::PAYMENT_PAID,
        'currency'       => $fcPlanCurrency,
        'type'           => Status::ORDER_TYPE_SUBSCRIPTION,
        'subtotal'       => 1000,
        'tax_total'      => 0,
        'total_amount'   => 1000,
        'total_paid'     => 1000,
        'total_refund'   => 0,
        'created_at'     => '2001-02-03 10:00:00',
        'updated_at'     => '2001-02-03 10:00:00',
    ], $attributes));
};

/**
 * Each plan: status, bill_times, bill_count, renewals (paid renewal orders),
 * and optional parent / refunded_renewal / attributes overrides.
 */
$fcPlanSeed = function (array $plans) use ($fcPlanCurrency, $fcPlanGuard, $fcPlanOrder) {
    $baseKey = $fcPlanGuard();
    $created = [];

    foreach ($plans as $index => $plan) {
        $parent = $fcPlanOrder(isset($plan['parent']) ? $plan['parent'] : []);

        for ($i = 0; $i < (isset($plan['renewals']) ? $plan['renewals'] : 0); $i++) {
            $fcPlanOrder(['type' => Status::ORDER_TYPE_RENEWAL, 'parent_id' => (int) $parent->id]);
        }

        if (!empty($plan['refunded_renewal'])) {
            $fcPlanOrder([
                'type'           => Status::ORDER_TYPE_RENEWAL,
                'parent_id'      => (int) $parent->id,
                'status'         => Status::ORDER_CANCELED,
                'payment_status' => Status::PAYMENT_REFUNDED,
                'total_refund'   => 1000,
            ]);
        }

        $productId = $baseKey + ($index * 2);
        $created[$plan['suffix']] = FcDomainFixture::subscription(
            $parent,
            $productId,
            $productId + 1,
            $plan['status'],
            'plans-' . $plan['suffix'],
            array_merge([
                'currency'          => $fcPlanCurrency,
                'billing_interval'  => 'monthly',
                'recurring_amount'  => 1000,
                'recurring_total'   => 1000,
                'bill_times'        => $plan['bill_times'],
                'bill_count'        => $plan['bill_count'],
                'next_billing_date' => '2030-01-15 10:00:00',
            ], isset($plan['attributes']) ? $plan['attributes'] : [])
        );
    }

    return $created;
};

return [
    [
        'id'            => 'reports-installment-plans-buckets',
        'name'          => 'Installments report puts every started plan in one bucket and schedules what is left',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcPlanSeed, $fcPlanCurrency) {
            try {
                FcFixture::assertReportRangesEmpty();
                $plans = $fcPlanSeed([
                    ['suffix' => 'running', 'status' => Status::SUBSCRIPTION_ACTIVE, 'bill_times' => 4, 'bill_count' => 2, 'renewals' => 1],
                    ['suffix' => 'overdue', 'status' => Status::SUBSCRIPTION_EXPIRING, 'bill_times' => 4, 'bill_count' => 1,
                        'attributes' => ['next_billing_date' => '2029-12-10 10:00:00']],
                    ['suffix' => 'lapsed', 'status' => Status::SUBSCRIPTION_EXPIRED, 'bill_times' => 4, 'bill_count' => 1],
                    ['suffix' => 'refunded', 'status' => Status::SUBSCRIPTION_CANCELED, 'bill_times' => 4, 'bill_count' => 2, 'refunded_renewal' => true],
                    ['suffix' => 'completed', 'status' => Status::SUBSCRIPTION_COMPLETED, 'bill_times' => 4, 'bill_count' => 4, 'renewals' => 3],
                    // Not started: the only order was refunded in full
                    ['suffix' => 'never-kept', 'status' => Status::SUBSCRIPTION_CANCELED, 'bill_times' => 4, 'bill_count' => 1,
                        'parent' => ['payment_status' => Status::PAYMENT_REFUNDED, 'status' => Status::ORDER_CANCELED, 'total_refund' => 1000]],
                    ['suffix' => 'open-ended', 'status' => Status::SUBSCRIPTION_ACTIVE, 'bill_times' => 0, 'bill_count' => 1],
                    ['suffix' => 'intended', 'status' => Status::SUBSCRIPTION_INTENDED, 'bill_times' => 4, 'bill_count' => 0],
                ]);

                $report = InstallmentReportService::make()->getReport([
                    'currency'    => $fcPlanCurrency,
                    'asOf'        => '2030-01-01',
                    'atRiskLimit' => 200,
                    'withVariations' => true,
                ]);

                FcTest::assertSame([
                    'plans_started'   => 5,
                    'payments'        => 9,
                    'collected'       => 9000,
                    'scheduled'       => 2000,
                    'overdue'         => 3000,
                    'lapsed_unpaid'   => 3000,
                    'completion_rate' => 33.33,
                    'collection_rate' => 50.0,
                    'completed_count' => 1,
                    'running_count'   => 1,
                    'overdue_count'   => 1,
                    'paused_count'    => 0,
                    'upgraded_count'  => 0,
                    'lapsed_count'    => 1,
                    'refunded_count'  => 1,
                ], $report['summary'], 'Summary reconciles: 5 started plans, one per bucket; a fully refunded first order never starts a plan; completion rate = 1 completed of 3 ended plans; '
                    . 'collection rate = 3 of the 6 payments after the first on the completed and lapsed plans');

                FcTest::assertSame(
                    [['2001-02', 5, 9000, 1, 1, 1, 1, 1]],
                    array_map(function ($month) {
                        return [$month['month'], $month['plans_started'], $month['collected'], $month['completed_count'], $month['running_count'], $month['overdue_count'], $month['lapsed_count'], $month['refunded_count']];
                    }, $report['starts']),
                    'Plans started by month: all five plans first paid in Feb 2001, one per outcome'
                );

                // The overdue Dec 2029 payment shows in the as-of month
                FcTest::assertSame([
                    ['month' => '2030-01', 'payments' => 3, 'amount' => 3000, 'scheduled' => 1000, 'overdue' => 2000],
                    ['month' => '2030-02', 'payments' => 2, 'amount' => 2000, 'scheduled' => 1000, 'overdue' => 1000],
                ], $report['schedule'], 'Schedule runs to the last installment and sums to scheduled + overdue');

                $atRisk = array_map(function ($row) {
                    return [$row['subscription_id'], $row['bucket'], $row['installments_left'], $row['left_amount']];
                }, $report['at_risk']);
                FcTest::assertSame([
                    [(int) $plans['lapsed']->id, 'lapsed', 3, 3000],
                    [(int) $plans['overdue']->id, 'overdue', 3, 3000],
                ], $atRisk, 'At-risk lists the overdue and lapsed plans, largest balance then newest first');
                FcTest::assertSame([2, 6000], [$report['at_risk_count'], $report['at_risk_amount']], 'at_risk_count and at_risk_amount cover overdue + lapsed');

                $service = InstallmentReportService::make();
                $page2 = $service->getAtRisk(['currency' => $fcPlanCurrency, 'page' => 2, 'perPage' => 1]);
                FcTest::assertSame(
                    [2, 2, 1, [(int) $plans['overdue']->id]],
                    [$page2['total'], $page2['page'], $page2['per_page'], array_column($page2['data'], 'subscription_id')],
                    'At-risk pages keep the same order: page 2 of 1 row holds the second plan'
                );
                FcTest::assertSame(
                    [(int) $plans['lapsed']->id],
                    array_column($service->getAtRisk(['currency' => $fcPlanCurrency, 'bucket' => 'lapsed'])['data'], 'subscription_id'),
                    'bucket=lapsed keeps only lapsed plans'
                );
                $customerEmail = (string) App::db()->table('fct_customers')->where('id', (int) $plans['lapsed']->customer_id)->value('email');
                FcTest::assertSame(
                    [2, 0],
                    [
                        $service->getAtRisk(['currency' => $fcPlanCurrency, 'search' => $customerEmail])['total'],
                        $service->getAtRisk(['currency' => $fcPlanCurrency, 'search' => 'no-such-customer-' . FcFixture::identity()])['total'],
                    ],
                    'Search matches the customer email and finds nothing for an unknown one'
                );
                // Summary and table rows are separate queries; they must agree field by field
                $rows = InstallmentReportService::make()->getByVariation(['currency' => $fcPlanCurrency])['data'];
                $fields = [
                    'plans_started' => 'plans_started', 'payments' => 'payments', 'collected' => 'collected',
                    'scheduled' => 'scheduled', 'overdue' => 'overdue_amount', 'lapsed_unpaid' => 'lapsed_amount',
                    'completed_count' => 'completed_count', 'running_count' => 'running_count', 'overdue_count' => 'overdue_count',
                    'paused_count' => 'paused_count', 'upgraded_count' => 'upgraded_count', 'lapsed_count' => 'lapsed_count', 'refunded_count' => 'refunded_count',
                ];
                $fromRows = [];
                foreach ($fields as $summaryKey => $rowKey) {
                    $fromRows[$summaryKey] = array_sum(array_column($rows, $rowKey));
                }
                FcTest::assertSame(
                    array_intersect_key($report['summary'], $fields),
                    $fromRows,
                    'Summing the Plans by Variation rows gives the summary on every field'
                );

                $summary = $report['summary'];
                FcTest::assertSame(50.0, round(($summary['scheduled'] + $summary['overdue']) / 100, 2), 'future installments = running + overdue');
                FcTest::assertSame(20.0, round($summary['scheduled'] / 100, 2), 'scheduled installments = running only');
                FcTest::assertSame(30.0, round($summary['overdue'] / 100, 2), 'overdue installments = expiring/past_due/failing');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-installment-plans-sold-variation',
        'name'          => 'Installments report files a plan under the price it was sold at, not the price it was later moved to',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcPlanSeed, $fcPlanCurrency) {
            try {
                FcFixture::assertReportRangesEmpty();
                $plans = $fcPlanSeed([
                    ['suffix' => 'moved', 'status' => Status::SUBSCRIPTION_COMPLETED, 'bill_times' => 2, 'bill_count' => 2, 'renewals' => 1],
                ]);
                $plan = $plans['moved'];
                $soldVariation = (int) $plan->variation_id + 1000;
                FcFixture::reportOrderItem($plan->parent_order_id, [
                    'object_id' => $soldVariation, 'payment_type' => 'subscription',
                    'unit_price' => 1000, 'subtotal' => 1000, 'line_total' => 1000,
                ]);

                $report = InstallmentReportService::make()->getReport(['currency' => $fcPlanCurrency, 'asOf' => '2030-01-01', 'withVariations' => true]);
                FcTest::assertSame(
                    [[$soldVariation, 1, 1000, 1000, 2, 2]],
                    array_map(function ($row) {
                        return [$row['variation_id'], $row['plans_started'], $row['min_price'], $row['max_price'], $row['min_bill_times'], $row['max_bill_times']];
                    }, $report['by_variation']),
                    'The plan sits under its sold variation, with terms from the plan: 10.00 x 2'
                );
                FcTest::assertSame(
                    1,
                    InstallmentReportService::make()->getReport(['currency' => $fcPlanCurrency, 'variationIds' => [$soldVariation]])['summary']['plans_started'],
                    'The product filter matches the sold variation too'
                );
                $filtered = InstallmentReportService::make()->getReport(['currency' => $fcPlanCurrency, 'variationIds' => [$soldVariation + 1]]);
                FcTest::assertSame(
                    [$soldVariation],
                    array_column(InstallmentReportService::make()->getByVariation(['currency' => $fcPlanCurrency])['data'], 'variation_id'),
                    'getByVariation returns the table rows the variations endpoint serves'
                );
                FcTest::assertSame(
                    [0, [$soldVariation]],
                    [$filtered['summary']['plans_started'], array_column(array_merge([], ...array_column($filtered['filter_options'], 'variations')), 'id')],
                    'Filter options list every sold variation even while another is selected'
                );
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-installment-plans-starts-gap',
        'name'          => 'Plans started by month keeps a month with no starts as a zero row',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcPlanSeed, $fcPlanCurrency) {
            try {
                FcFixture::assertReportRangesEmpty();
                $fcPlanSeed([
                    ['suffix' => 'jan', 'status' => Status::SUBSCRIPTION_COMPLETED, 'bill_times' => 1, 'bill_count' => 1,
                        'parent' => ['created_at' => '2001-01-15 10:00:00']],
                    ['suffix' => 'mar', 'status' => Status::SUBSCRIPTION_COMPLETED, 'bill_times' => 1, 'bill_count' => 1,
                        'parent' => ['created_at' => '2001-03-15 10:00:00']],
                ]);

                $starts = InstallmentReportService::make()->getReport(['currency' => $fcPlanCurrency])['starts'];
                FcTest::assertSame(
                    [['2001-01', 1], ['2001-02', 0], ['2001-03', 1]],
                    array_map(function ($month) {
                        return [$month['month'], $month['plans_started']];
                    }, $starts),
                    'January and March each have one start; February is a zero row'
                );
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-installment-plans-started-between',
        'name'          => 'Started between narrows every section to plans first paid in the range, end day included',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC', // fixtures are UTC days; reports-site-timezone covers other zones
        'run'           => function () use ($fcPlanSeed, $fcPlanCurrency) {
            try {
                FcFixture::assertReportRangesEmpty();
                $plans = $fcPlanSeed([
                    ['suffix' => 'jan', 'status' => Status::SUBSCRIPTION_EXPIRED, 'bill_times' => 3, 'bill_count' => 1,
                        'parent' => ['created_at' => '2001-01-31 23:59:59']],
                    ['suffix' => 'feb', 'status' => Status::SUBSCRIPTION_EXPIRED, 'bill_times' => 3, 'bill_count' => 1,
                        'parent' => ['created_at' => '2001-02-01 00:00:00']],
                    ['suffix' => 'mar', 'status' => Status::SUBSCRIPTION_COMPLETED, 'bill_times' => 1, 'bill_count' => 1,
                        'parent' => ['created_at' => '2001-03-31 23:30:00']],
                    ['suffix' => 'apr', 'status' => Status::SUBSCRIPTION_COMPLETED, 'bill_times' => 1, 'bill_count' => 1,
                        'parent' => ['created_at' => '2001-04-01 00:00:00']],
                ]);
                $range = ['currency' => $fcPlanCurrency, 'startedFrom' => '2001-02-01', 'startedTo' => '2001-03-31'];
                $service = InstallmentReportService::make();

                $report = $service->getReport($range);
                FcTest::assertSame(
                    [2, 1, 1, ['2001-02', '2001-03'], '2001-02-01 00:00:00', '2001-03-31 23:59:59'],
                    [
                        $report['summary']['plans_started'], $report['summary']['lapsed_count'], $report['summary']['completed_count'],
                        array_column($report['starts'], 'month'), $report['started_from'], $report['started_to'],
                    ],
                    'Feb 1 00:00 and Mar 31 23:30 are in; Jan 31 23:59:59 and Apr 1 00:00 are out'
                );
                FcTest::assertSame(4, count($report['filter_options'][0]['variations']), 'The product picker still offers every variation');

                // In New York, Mar 31 23:30 and Apr 1 00:00 UTC are both Mar 31: the bars use the same store months as the filter
                $newYork = FcReportFixture::inSiteTimezone('America/New_York', function () use ($service, $range) {
                    $report = $service->getReport($range);

                    return [$report['summary']['plans_started'], array_map(function ($month) {
                        return [$month['month'], $month['plans_started']];
                    }, $report['starts'])];
                });
                FcTest::assertSame([2, [['2001-03', 2]]], $newYork, 'Started bars follow the store month of the filter');

                FcTest::assertSame(
                    [(int) $plans['feb']->id],
                    array_column($service->getAtRisk($range)['data'], 'subscription_id'),
                    'At-risk lists only the lapsed plan started in range'
                );

                $marVariation = (int) $plans['mar']->variation_id;
                $narrowed = $service->getByVariation($range + ['variationIds' => [$marVariation]])['data'];
                FcTest::assertSame(
                    [[$marVariation, 1, 1]],
                    array_map(function ($row) {
                        return [$row['variation_id'], $row['plans_started'], $row['completed_count']];
                    }, $narrowed),
                    'Range and variation together: one completed plan on that variation'
                );

                FcTest::assertSame(4, $service->getReport(['currency' => $fcPlanCurrency, 'startedFrom' => 'not-a-date'])['summary']['plans_started'], 'An invalid date is ignored, not an empty report');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-installment-plans-review-round-2',
        'name'          => 'Review round 2: custom schedules, upgraded and pending plans, mixed parent orders, impossible dates',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcPlanSeed, $fcPlanCurrency) {
            try {
                FcFixture::assertReportRangesEmpty();
                $plans = $fcPlanSeed([
                    // C: every 2 weeks, stored with the weekly slug
                    ['suffix' => 'fortnightly', 'status' => Status::SUBSCRIPTION_ACTIVE, 'bill_times' => 4, 'bill_count' => 1,
                        'attributes' => ['billing_interval' => 'weekly', 'next_billing_date' => '2030-01-07 10:00:00',
                            'config' => ['billing_schedule' => ['period' => 'week', 'interval' => 2]]]],
                    // D: canceled by a Pro upgrade
                    ['suffix' => 'upgraded', 'status' => Status::SUBSCRIPTION_CANCELED, 'bill_times' => 12, 'bill_count' => 3, 'renewals' => 2,
                        'attributes' => ['config' => ['upgraded_to_sub_id' => 999999]]],
                    // E: paid, waiting for the gateway to activate
                    ['suffix' => 'pending', 'status' => Status::SUBSCRIPTION_PENDING, 'bill_times' => 4, 'bill_count' => 1,
                        'attributes' => ['next_billing_date' => '2030-03-01 10:00:00']],
                    // F: an admin order with the plan (10.00) and an ordinary item (90.00); the ordinary item was refunded
                    ['suffix' => 'mixed', 'status' => Status::SUBSCRIPTION_EXPIRED, 'bill_times' => 4, 'bill_count' => 1,
                        'parent' => ['payment_status' => Status::PAYMENT_PARTIALLY_REFUNDED, 'subtotal' => 10000,
                            'total_amount' => 10000, 'total_paid' => 10000, 'total_refund' => 9000]],
                ]);
                $mixed = $plans['mixed'];
                FcFixture::reportOrderItem($mixed->parent_order_id, [
                    'object_id' => (int) $mixed->variation_id, 'payment_type' => 'subscription',
                    'unit_price' => 1000, 'subtotal' => 1000, 'line_total' => 1000,
                ]);
                FcFixture::reportOrderItem($mixed->parent_order_id, [
                    'object_id' => (int) $mixed->variation_id + 500, 'payment_type' => 'onetime',
                    'unit_price' => 9000, 'subtotal' => 9000, 'line_total' => 9000, 'refund_total' => 9000,
                ]);

                $report = InstallmentReportService::make()->getReport(['currency' => $fcPlanCurrency, 'asOf' => '2030-01-01', 'atRiskLimit' => 20, 'withVariations' => true]);
                $byVariation = [];
                foreach ($report['by_variation'] as $row) {
                    $byVariation[$row['variation_id']] = $row;
                }

                // C
                FcTest::assertSame(
                    [['2030-01', 2], ['2030-02', 1]],
                    array_values(array_filter(array_map(function ($month) {
                        return [$month['month'], $month['payments']];
                    }, $report['schedule']), function ($month) {
                        return $month[0] <= '2030-02';
                    })),
                    'The every-2-weeks plan bills Jan 7, Jan 21 and Feb 4'
                );

                // D
                $upgraded = $byVariation[(int) $plans['upgraded']->variation_id];
                $atRiskIds = array_column($report['at_risk'], 'subscription_id');
                FcTest::assertSame(
                    [1, 0, 3000, false],
                    [$upgraded['upgraded_count'], $upgraded['lapsed_count'], $upgraded['collected'], in_array((int) $plans['upgraded']->id, $atRiskIds, true)],
                    'An upgraded plan is its own bucket, keeps the money it collected and is never at risk'
                );

                // E
                FcTest::assertSame(1, $byVariation[(int) $plans['pending']->variation_id]['running_count'], 'A paid plan waiting on the gateway counts as running');

                // F
                $mixedRow = $byVariation[(int) $mixed->variation_id];
                FcTest::assertSame(
                    [1000, 1, 0],
                    [$mixedRow['collected'], $mixedRow['lapsed_count'], $mixedRow['refunded_count']],
                    'Only the plan line of a mixed order counts, and a refund of the other item does not mark the plan refunded'
                );

                // Completion rate: only completed, lapsed and refunded plans have ended
                FcTest::assertSame(0.0, (float) $report['summary']['completion_rate'], 'The upgraded plan stays out of the completion rate; the mixed plan lapsed');

                // G
                FcTest::assertSame(
                    [4, null],
                    [InstallmentReportService::make()->getReport(['currency' => $fcPlanCurrency, 'startedFrom' => '2001-02-31'])['summary']['plans_started'],
                        InstallmentReportService::make()->getReport(['currency' => $fcPlanCurrency, 'startedFrom' => '2001-02-31'])['started_from']],
                    'An impossible date is ignored, not rolled over to March 3'
                );
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-installment-plans-review-round-3',
        'name'          => 'Review round 3: zero and trial plan lines on mixed orders, fee lines, upgraded plans that still bill',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcPlanSeed, $fcPlanOrder, $fcPlanCurrency, $fcPlanGuard) {
            try {
                FcFixture::assertReportRangesEmpty();
                $plans = $fcPlanSeed([
                    // 1: a 100% discounted plan line next to a paid ordinary item
                    ['suffix' => 'free-line', 'status' => Status::SUBSCRIPTION_EXPIRED, 'bill_times' => 4, 'bill_count' => 1,
                        'parent' => ['subtotal' => 10000, 'total_amount' => 9000, 'total_paid' => 9000]],
                    // 3: admin order with a trial plan (100.00, not charged), a 10.00 signup fee and a 50.00 item
                    ['suffix' => 'trial', 'status' => Status::SUBSCRIPTION_ACTIVE, 'bill_times' => 4, 'bill_count' => 0,
                        'parent' => ['subtotal' => 16000, 'total_amount' => 6000, 'total_paid' => 6000]],
                    // 5: upgraded, but the remote cancel failed and it still bills
                    ['suffix' => 'upgrade-failed', 'status' => Status::SUBSCRIPTION_ACTIVE, 'bill_times' => 4, 'bill_count' => 1,
                        'attributes' => ['config' => ['upgraded_to_sub_id' => 999998]]],
                ]);

                $free = $plans['free-line'];
                FcFixture::reportOrderItem($free->parent_order_id, [
                    'object_id' => (int) $free->variation_id, 'payment_type' => 'subscription',
                    'unit_price' => 1000, 'subtotal' => 1000, 'discount_total' => 1000, 'line_total' => 0,
                ]);
                FcFixture::reportOrderItem($free->parent_order_id, [
                    'object_id' => (int) $free->variation_id + 500, 'payment_type' => 'onetime',
                    'unit_price' => 9000, 'subtotal' => 9000, 'line_total' => 9000,
                ]);

                $trial = $plans['trial'];
                FcFixture::reportOrderItem($trial->parent_order_id, [
                    'object_id' => (int) $trial->variation_id, 'payment_type' => 'subscription',
                    'unit_price' => 10000, 'subtotal' => 10000, 'line_total' => 10000,
                    'other_info' => ['trial_days' => 7, 'fixture_identity' => FcFixture::identity()],
                ]);
                FcFixture::reportOrderItem($trial->parent_order_id, [
                    'object_id' => (int) $trial->variation_id, 'payment_type' => 'signup_fee',
                    'unit_price' => 1000, 'subtotal' => 1000, 'line_total' => 1000,
                ]);
                FcFixture::reportOrderItem($trial->parent_order_id, [
                    'object_id' => (int) $trial->variation_id + 500, 'payment_type' => 'onetime',
                    'unit_price' => 5000, 'subtotal' => 5000, 'line_total' => 5000,
                ]);

                $report = InstallmentReportService::make()->getReport(['currency' => $fcPlanCurrency, 'asOf' => '2030-01-01', 'withVariations' => true]);
                $byVariation = [];
                foreach ($report['by_variation'] as $row) {
                    $byVariation[$row['variation_id']] = $row;
                }

                FcTest::assertSame(false, isset($byVariation[(int) $free->variation_id]), 'A plan whose only line was free took no money, so it never started');
                FcTest::assertSame(1000, $byVariation[(int) $trial->variation_id]['collected'], 'The trial line charged nothing; the plan collected its 10.00 signup fee');
                FcTest::assertSame(
                    [1, 0],
                    [$byVariation[(int) $plans['upgrade-failed']->variation_id]['running_count'], $byVariation[(int) $plans['upgrade-failed']->variation_id]['upgraded_count']],
                    'A plan that still bills stays running even if an upgrade flagged it'
                );

                // Revenue by variation on the same trial order: the trial line takes none of the 60.00
                $window = FcFixture::reportWindow();
                App::db()->table('fct_orders')->where('id', (int) $trial->parent_order_id)->update(['created_at' => $window['start']]);
                App::db()->table('fct_order_items')->where('order_id', (int) $trial->parent_order_id)->update(['created_at' => $window['start']]);
                $revenue = ProductReportService::make()->getVariationRevenue([
                    'startDate'     => new DateTime($window['start']),
                    'endDate'       => new DateTime($window['end']),
                    'currency'      => $fcPlanCurrency,
                    'paymentStatus' => Status::getReportStatuses(),
                    'variationIds'  => [(int) $trial->variation_id, (int) $trial->variation_id + 500],
                ]);
                $gross = array_column($revenue['variations'], 'gross', 'variation_id');
                FcTest::assertSame(
                    [1000, 5000],
                    [$gross[(int) $trial->variation_id], $gross[(int) $trial->variation_id + 500]],
                    'Revenue by variation splits the 60.00 as 10.00 signup fee and 50.00 item'
                );
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-installment-plans-fee-lines',
        'name'          => 'Revenue by variation keeps checkout fees out of the variation rows but in the totals',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcPlanGuard, $fcPlanOrder, $fcPlanCurrency) {
            try {
                FcFixture::assertReportRangesEmpty();
                $variationId = $fcPlanGuard();
                $window = FcFixture::reportWindow();
                $order = $fcPlanOrder([
                    'type' => Status::ORDER_TYPE_PAYMENT, 'subtotal' => 5500, 'fee_total' => 500,
                    'total_amount' => 5500, 'total_paid' => 5500, 'created_at' => $window['start'],
                ]);
                FcFixture::reportOrderItem($order->id, [
                    'object_id' => $variationId, 'payment_type' => 'onetime',
                    'unit_price' => 5000, 'subtotal' => 5000, 'line_total' => 5000,
                ]);
                FcFixture::reportOrderItem($order->id, [
                    'object_id' => 0, 'post_id' => 0, 'payment_type' => 'fee', 'title' => 'Handling',
                    'unit_price' => 500, 'subtotal' => 500, 'line_total' => 500,
                ]);

                $result = ProductReportService::make()->getVariationRevenue([
                    'startDate'     => new DateTime($window['start']),
                    'endDate'       => new DateTime($window['end']),
                    'currency'      => $fcPlanCurrency,
                    'paymentStatus' => Status::getReportStatuses(),
                    'variationIds'  => [],
                ]);

                FcTest::assertSame([$variationId], array_column($result['variations'], 'variation_id'), 'No variation #0 row for the fee');
                FcTest::assertSame(
                    [1, 5000, 5500, 500],
                    [$result['totals']['units'], $result['variations'][0]['gross'], $result['totals']['gross'], $result['totals']['fees_gross']],
                    'One unit; the fee stays in the totals (55.00 = total paid) and is reported as fees'
                );
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-installment-plans-left-is-signed',
        'name'          => 'A plan billed past its term owes nothing instead of overflowing',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcPlanSeed, $fcPlanCurrency) {
            try {
                FcFixture::assertReportRangesEmpty();
                $fcPlanSeed([
                    ['suffix' => 'over-billed', 'status' => Status::SUBSCRIPTION_ACTIVE, 'bill_times' => 3, 'bill_count' => 5, 'renewals' => 4],
                ]);

                $report = InstallmentReportService::make()->getReport(['currency' => $fcPlanCurrency, 'asOf' => '2030-01-01', 'withVariations' => true]);

                FcTest::assertSame(1, $report['summary']['completed_count'], 'bill_count > bill_times counts as completed');
                FcTest::assertSame(0, $report['summary']['scheduled'], 'and schedules nothing');
                FcTest::assertSame(0.0, round(($report['summary']['scheduled'] + $report['summary']['overdue']) / 100, 2), 'future installments do not overflow');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-installment-plans-variation-revenue',
        'name'          => 'Revenue by variation splits one-time and recurring lines with refunds per line',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcPlanGuard, $fcPlanOrder, $fcPlanCurrency) {
            try {
                FcFixture::assertReportRangesEmpty();
                $variationId = $fcPlanGuard();
                $window = FcFixture::reportWindow();
                $lines = [
                    // [type, payment_type, line total, refund]
                    [Status::ORDER_TYPE_PAYMENT, 'onetime', 5000, 0],
                    [Status::ORDER_TYPE_PAYMENT, 'onetime', 5000, 2000],
                    [Status::ORDER_TYPE_SUBSCRIPTION, 'subscription', 1000, 0],
                ];
                foreach ($lines as $line) {
                    $order = $fcPlanOrder([
                        'type'           => $line[0],
                        'payment_status' => $line[3] ? Status::PAYMENT_PARTIALLY_REFUNDED : Status::PAYMENT_PAID,
                        'subtotal'       => $line[2],
                        'total_amount'   => $line[2],
                        'total_paid'     => $line[2],
                        'total_refund'   => $line[3],
                        'created_at'     => $window['start'],
                    ]);
                    FcFixture::reportOrderItem($order->id, [
                        'object_id'    => $variationId,
                        'payment_type' => $line[1],
                        'unit_price'   => $line[2],
                        'subtotal'     => $line[2],
                        'line_total'   => $line[2],
                        'refund_total' => $line[3],
                    ]);
                }

                $result = ProductReportService::make()->getVariationRevenue([
                    'startDate'     => new DateTime($window['start']),
                    'endDate'       => new DateTime($window['end']),
                    'currency'      => $fcPlanCurrency,
                    'paymentStatus' => Status::getReportStatuses(),
                    'variationIds'  => [$variationId],
                ]);

                FcTest::assertSame(1, count($result['variations']), 'One row for the one variation');
                $row = $result['variations'][0];
                FcTest::assertSame(
                    [2, 10000, 2000, 8000, 1, 1000, 0, 1000, 1, 11000, 9000],
                    [
                        $row['one_time_orders'], $row['one_time_gross'], $row['one_time_refunds'], $row['one_time_net'],
                        $row['recurring_orders'], $row['recurring_gross'], $row['recurring_refunds'], $row['recurring_net'],
                        $row['refunded_orders'], $row['gross'], $row['net'],
                    ],
                    'One-time: 2 orders, 100.00 gross, 20.00 refunded; recurring: 1 order, 10.00; totals add up'
                );
                FcTest::assertSame(9000, $result['totals']['net'], 'Totals net matches the row');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-installment-plans-variation-revenue-money',
        'name'          => 'Revenue by variation follows the money paid, even when an order discount is stale',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcPlanGuard, $fcPlanOrder, $fcPlanCurrency) {
            try {
                FcFixture::assertReportRangesEmpty();
                $first = $fcPlanGuard();
                $second = $first + 1;
                $window = FcFixture::reportWindow();

                // Stale header discount: the customer still paid the full 10.99
                $stale = $fcPlanOrder([
                    'type' => Status::ORDER_TYPE_PAYMENT, 'subtotal' => 1099, 'manual_discount_total' => 1099,
                    'total_amount' => 1099, 'total_paid' => 1099, 'created_at' => $window['start'],
                ]);
                FcFixture::reportOrderItem($stale->id, [
                    'object_id' => $first, 'unit_price' => 1099, 'subtotal' => 1099, 'line_total' => 1099,
                ]);

                // Two lines share 30.01 paid and 4.00 refunded by line amount; the odd cent goes to the first line
                $split = $fcPlanOrder([
                    'type' => Status::ORDER_TYPE_PAYMENT, 'payment_status' => Status::PAYMENT_PARTIALLY_REFUNDED,
                    'subtotal' => 4000, 'total_amount' => 3001, 'total_paid' => 3001, 'total_refund' => 400,
                    'created_at' => $window['start'],
                ]);
                FcFixture::reportOrderItem($split->id, [
                    'object_id' => $first, 'unit_price' => 3000, 'subtotal' => 3000, 'line_total' => 3000,
                ]);
                FcFixture::reportOrderItem($split->id, [
                    'object_id' => $second, 'unit_price' => 1000, 'subtotal' => 1000, 'line_total' => 1000,
                ]);

                $result = ProductReportService::make()->getVariationRevenue([
                    'startDate'     => new DateTime($window['start']),
                    'endDate'       => new DateTime($window['end']),
                    'currency'      => $fcPlanCurrency,
                    'paymentStatus' => Status::getReportStatuses(),
                    'variationIds'  => [],
                ]);

                $rows = [];
                foreach ($result['variations'] as $row) {
                    $rows[$row['variation_id']] = [$row['gross'], $row['refunds'], $row['net']];
                }
                FcTest::assertSame(
                    [$first => [1099 + 2251, 300, 1099 + 2251 - 300], $second => [750, 100, 650]],
                    [$first => $rows[$first], $second => $rows[$second]],
                    'Gross and refunds are each order\'s paid and refunded money, shared across its lines by line amount'
                );
                FcTest::assertSame(
                    [4100, 400, 3700],
                    [$result['totals']['gross'], $result['totals']['refunds'], $result['totals']['net']],
                    'Totals equal SUM(total_paid), SUM(total_refund) and their difference'
                );
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-installment-plans-future-renewals',
        'name'          => 'Future Renewals projects open-ended plans only, over the chosen horizon in calendar months',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcPlanSeed, $fcPlanCurrency) {
            try {
                FcFixture::assertReportRangesEmpty();
                $fcPlanSeed([
                    ['suffix' => 'fr-installment', 'status' => Status::SUBSCRIPTION_ACTIVE, 'bill_times' => 4, 'bill_count' => 2, 'renewals' => 1,
                        'attributes' => ['next_billing_date' => '2030-01-11 10:00:00']],
                    ['suffix' => 'fr-recurring', 'status' => Status::SUBSCRIPTION_ACTIVE, 'bill_times' => 0, 'bill_count' => 1,
                        'attributes' => ['next_billing_date' => '2030-01-11 10:00:00']],
                ]);

                $service = SubscriptionReportService::make();
                $twelve = $service->getFutureRenewals(['currency' => $fcPlanCurrency, 'asOf' => '2030-01-01', 'horizon' => '12']);
                FcTest::assertSame([12, 12000], [$twelve['totalRenewals'], $twelve['totalProjected']], '12 monthly renewals of the open-ended plan; the installment plan is left out');

                $default = $service->getFutureRenewals(['currency' => $fcPlanCurrency, 'asOf' => '2030-01-31']);
                FcTest::assertSame(['2030-01', '2030-02', '2030-03', '2030-04'], array_column($default['projections'], 'group'), 'Default 3 months from Jan 31 keeps February');
                FcTest::assertSame(3, $default['totalRenewals'], 'Feb, Mar and Apr renewals fall inside the 3 months');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-installment-plans-review-fixes',
        'name'          => 'Review fixes: stale renewals, signup fees, payoff lines, paid count, paused plans, KPI cards',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcPlanSeed, $fcPlanOrder, $fcPlanCurrency, $fcPlanGuard) {
            try {
                FcFixture::assertReportRangesEmpty();
                $plans = $fcPlanSeed([
                    // A daily plan already 10 days behind: the overdue days must not crowd out the horizon
                    ['suffix' => 'daily-behind', 'status' => Status::SUBSCRIPTION_ACTIVE, 'bill_times' => 0, 'bill_count' => 1,
                        'attributes' => ['billing_interval' => 'daily', 'next_billing_date' => '2029-12-22 10:00:00']],
                    // Prepaid three installments in one payoff order: bill_count 4, but only 2 orders
                    ['suffix' => 'prepaid', 'status' => Status::SUBSCRIPTION_EXPIRED, 'bill_times' => 6, 'bill_count' => 4, 'renewals' => 1],
                    ['suffix' => 'paused', 'status' => Status::SUBSCRIPTION_PAUSED, 'bill_times' => 4, 'bill_count' => 1],
                    // Never paid: an active plan whose only order took no money
                    ['suffix' => 'never-paid', 'status' => Status::SUBSCRIPTION_ACTIVE, 'bill_times' => 4, 'bill_count' => 1,
                        'parent' => ['payment_status' => 'pending', 'status' => 'pending', 'total_paid' => 0]],
                ]);
                $service = SubscriptionReportService::make();

                // 1. Daily renewals over the 3 whole months January to March: 31 + 28 + 31 (the horizon ends on a month's last day)
                $renewals = $service->getFutureRenewals(['currency' => $fcPlanCurrency, 'asOf' => '2030-01-01', 'horizon' => '3']);
                FcTest::assertSame(90, $renewals['totalRenewals'], 'A plan behind schedule still renews every day of the horizon');

                // 4. The prepaid plan shows its bill_count, not its order count
                $report = InstallmentReportService::make()->getReport(['currency' => $fcPlanCurrency, 'asOf' => '2030-01-01', 'atRiskLimit' => 10]);
                $atRisk = [];
                foreach ($report['at_risk'] as $row) {
                    $atRisk[$row['subscription_id']] = [$row['bucket'], $row['bill_count']];
                }
                FcTest::assertSame(['lapsed', 4], $atRisk[(int) $plans['prepaid']->id], 'Paid counts installments (bill_count), not orders');

                // Paused plans get their own bucket: not lapsed, not at risk, not in the completion rate
                FcTest::assertSame(
                    [1, 1, false],
                    [$report['summary']['paused_count'], $report['summary']['lapsed_count'], isset($atRisk[(int) $plans['paused']->id])],
                    'A paused plan is counted as paused, never as lapsed or at risk'
                );

                // 5. Started plans only: the never-paid plan owes nothing yet
                FcTest::assertSame(0.0, round($report['summary']['overdue'] / 100, 2), 'The never-paid plan is not overdue');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-installment-plans-variation-lines',
        'name'          => 'Revenue by variation: signup fees add money, not units or a name; payoff lines on renewals are recurring',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcPlanGuard, $fcPlanOrder, $fcPlanCurrency) {
            try {
                FcFixture::assertReportRangesEmpty();
                $variationId = $fcPlanGuard();
                $window = FcFixture::reportWindow();

                $order = $fcPlanOrder(['subtotal' => 1500, 'total_amount' => 1500, 'total_paid' => 1500, 'created_at' => $window['start']]);
                FcFixture::reportOrderItem($order->id, [
                    'object_id' => $variationId, 'payment_type' => 'subscription', 'title' => 'Plan A',
                    'unit_price' => 1000, 'subtotal' => 1000, 'line_total' => 1000,
                ]);
                FcFixture::reportOrderItem($order->id, [
                    'object_id' => $variationId, 'payment_type' => 'signup_fee', 'title' => 'Signup Fee',
                    'unit_price' => 500, 'subtotal' => 500, 'line_total' => 500,
                ]);
                // An early payoff: Pro writes a 'onetime' line on a renewal order
                $payoff = $fcPlanOrder([
                    'type' => Status::ORDER_TYPE_RENEWAL, 'parent_id' => (int) $order->id,
                    'subtotal' => 3000, 'total_amount' => 3000, 'total_paid' => 3000, 'created_at' => $window['start'],
                ]);
                FcFixture::reportOrderItem($payoff->id, [
                    'object_id' => $variationId, 'payment_type' => 'onetime', 'title' => 'Plan A',
                    'unit_price' => 3000, 'subtotal' => 3000, 'line_total' => 3000,
                ]);

                $row = ProductReportService::make()->getVariationRevenue([
                    'startDate'     => new DateTime($window['start']),
                    'endDate'       => new DateTime($window['end']),
                    'currency'      => $fcPlanCurrency,
                    'paymentStatus' => Status::getReportStatuses(),
                    'variationIds'  => [$variationId],
                ])['variations'][0];

                FcTest::assertSame(
                    ['Plan A', 2, 0, 4500, 0],
                    [$row['variation_title'], $row['units'], $row['one_time_gross'], $row['recurring_gross'], $row['one_time_units']],
                    'One unit per plan order, never one for the fee line; the payoff counts as recurring; the name skips "Signup Fee"'
                );
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-installment-plans-billing-dates',
        'name'          => 'Billing dates step like the renewal engine, including custom billing schedules',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            $format = function (array $dates) {
                return array_map(function ($date) {
                    return $date->format('Y-m-d H:i');
                }, $dates);
            };

            // The engine adds each cycle to the previous due date (RenewalService), so Jan 31 drifts to the 28th
            FcTest::assertSame(
                ['2031-01-31 10:00', '2031-02-28 10:00', '2031-03-28 10:00', '2031-04-28 10:00'],
                $format(ReportHelper::billingDates('2031-01-31 10:00:00', 'monthly', 4)),
                'Monthly from Jan 31 follows SubscriptionHelper::addBillingInterval() cycle by cycle'
            );
            FcTest::assertSame(
                ['2031-01-07 10:00', '2031-01-21 10:00', '2031-02-04 10:00'],
                $format(ReportHelper::billingDates('2031-01-07 10:00:00', 'weekly', 3, null, ['period' => 'week', 'interval' => 2, 'anchor' => []])),
                'A config billing_schedule of every 2 weeks wins over the weekly slug'
            );
        },
    ],
    [
        'id'            => 'reports-installment-plans-store-month',
        'name'          => 'The installment schedule buckets by store month; due-in-30-days and the collection rate',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'Asia/Dhaka', // UTC+6 all year
        'run'           => function () use ($fcPlanSeed, $fcPlanCurrency) {
            try {
                $fcPlanSeed([
                    // Due 31 Jan 20:00 UTC = 1 Feb in the store, then 28 Feb 20:00 UTC = 1 Mar
                    ['suffix' => 'month-edge', 'status' => Status::SUBSCRIPTION_ACTIVE, 'bill_times' => 4, 'bill_count' => 2, 'renewals' => 1,
                        'attributes' => ['next_billing_date' => '2030-01-31 20:00:00']],
                    ['suffix' => 'due-soon', 'status' => Status::SUBSCRIPTION_ACTIVE, 'bill_times' => 3, 'bill_count' => 1,
                        'attributes' => ['next_billing_date' => '2030-01-10 10:00:00', 'recurring_total' => 1500]],
                    ['suffix' => 'done', 'status' => Status::SUBSCRIPTION_COMPLETED, 'bill_times' => 2, 'bill_count' => 2, 'renewals' => 1],
                    ['suffix' => 'gone', 'status' => Status::SUBSCRIPTION_EXPIRED, 'bill_times' => 2, 'bill_count' => 1],
                    // As-of midnight is 18:00 UTC the day before: 31 Dec 20:00 UTC is 1 Jan in the store, inside the 30 days;
                    // 30 Jan 20:00 UTC is 31 Jan, outside
                    ['suffix' => 'early', 'status' => Status::SUBSCRIPTION_ACTIVE, 'bill_times' => 2, 'bill_count' => 1,
                        'attributes' => ['next_billing_date' => '2029-12-31 20:00:00', 'recurring_total' => 100]],
                    ['suffix' => 'late', 'status' => Status::SUBSCRIPTION_ACTIVE, 'bill_times' => 2, 'bill_count' => 1,
                        'attributes' => ['next_billing_date' => '2030-01-30 20:00:00', 'recurring_total' => 10]],
                    // Its renewal has not run yet: still due, as in the schedule
                    ['suffix' => 'stale', 'status' => Status::SUBSCRIPTION_ACTIVE, 'bill_times' => 2, 'bill_count' => 1,
                        'attributes' => ['next_billing_date' => '2029-12-20 10:00:00', 'recurring_total' => 1]],
                    // A failed payment on 10 Dec: overdue, and due now
                    ['suffix' => 'behind', 'status' => Status::SUBSCRIPTION_EXPIRING, 'bill_times' => 2, 'bill_count' => 1,
                        'attributes' => ['next_billing_date' => '2029-12-10 10:00:00', 'recurring_total' => 7]],
                    // One renewal order collected three cycles: every installment paid
                    ['suffix' => 'credited', 'status' => Status::SUBSCRIPTION_COMPLETED, 'bill_times' => 4, 'bill_count' => 4, 'renewals' => 1],
                ]);
                $report = InstallmentReportService::make()->getReport(['currency' => $fcPlanCurrency, 'asOf' => '2030-01-01']);

                FcTest::assertSame([['2030-01', 5], ['2030-02', 2], ['2030-03', 1]],
                    array_map(function ($month) {
                        return [$month['month'], $month['payments']];
                    }, $report['schedule']),
                    'Store months: January has 10 Jan, the two past-due ones, 1 Jan and 31 Jan in the store; the payments due late on 31 Jan and 28 Feb UTC fall in February and March');
                FcTest::assertSame(['count' => 4, 'amount' => 1608, 'overdue' => ['count' => 2, 'amount' => 8]], $report['due_soon'],
                    'Due in the 30 store days from 1 Jan: 10 Jan, 1 Jan in the store, and the two past-due ones (the overdue plan too); not 31 Jan');
                FcTest::assertSame(80.0, $report['summary']['collection_rate'], 'Of the payments after the first on ended plans, 4 of 5 were paid, counted by billing cycle');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
