<?php
/**
 * Subscriptions overview: period movement (new, churned with churn rate, net change, new / churned / net MRR,
 * renewals, failed renewals), a movement chart, MRR by plan and billing interval now, and real free-trial conversion.
 * Installment plans stay out; churn uses the Retention page's "left at" rule.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\SubscriptionReportService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcSubCurrency = 'XTS';

return [
    [
        'id'            => 'reports-subscriptions-overview',
        'name'          => 'Subscriptions overview: new, churned and churn rate, MRR movement, renewals, plan mix and trial conversion',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC', // fixtures are UTC days; reports-site-timezone covers other zones
        'run'           => function () use ($fcSubCurrency) {
            $transactionIds = [];
            try {
                $db = App::db();
                $foreign = (int) $db->table('fct_orders')->where('currency', $fcSubCurrency)->count()
                    + (int) $db->table('fct_subscriptions')->where('currency', $fcSubCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' rows already use currency ' . $fcSubCurrency);
                }
                FcFixture::customer();
                $base = 1600000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 20;
                $order = function (array $attributes) use ($fcSubCurrency) {
                    return FcFixture::reportOrder(array_merge([
                        'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'currency' => $fcSubCurrency,
                        'type' => Status::ORDER_TYPE_SUBSCRIPTION, 'subtotal' => 1000, 'total_amount' => 1000, 'total_paid' => 1000,
                        'created_at' => '2025-06-01 10:00:00', 'updated_at' => '2025-06-01 10:00:00',
                    ], $attributes));
                };
                $sub = function ($n, $status, $createdAt, array $attributes) use ($order, $base, $fcSubCurrency, $db) {
                    $parent = $order(['created_at' => $createdAt, 'updated_at' => $createdAt]);
                    $row = FcDomainFixture::subscription($parent, $base + $n * 2, $base + $n * 2 + 1, $status, 'subs-overview-' . $n,
                        array_merge(['currency' => $fcSubCurrency, 'canceled_at' => null], $attributes));
                    $db->table('fct_subscriptions')->where('id', (int) $row->id)->update(['created_at' => $createdAt]);

                    return [$parent, $row];
                };

                [$s1] = $sub(1, Status::SUBSCRIPTION_ACTIVE, '2025-06-10 10:00:00', ['recurring_total' => 1000]);
                $sub(2, Status::SUBSCRIPTION_CANCELED, '2025-06-01 10:00:00', ['recurring_total' => 2000, 'canceled_at' => '2025-07-15 10:00:00']);
                $sub(3, Status::SUBSCRIPTION_ACTIVE, '2025-07-05 10:00:00', ['recurring_total' => 12000, 'billing_interval' => 'yearly']);
                $sub(4, Status::SUBSCRIPTION_CANCELED, '2025-07-08 10:00:00', ['recurring_total' => 500, 'canceled_at' => '2025-07-20 10:00:00']);
                $sub(5, Status::SUBSCRIPTION_ACTIVE, '2025-07-09 10:00:00', ['recurring_total' => 9000, 'bill_times' => 3]); // installment plan
                [$s6] = $sub(6, Status::SUBSCRIPTION_ACTIVE, '2025-07-02 10:00:00', ['recurring_total' => 1500, 'trial_days' => 14]);
                $sub(7, Status::SUBSCRIPTION_CANCELED, '2025-07-03 10:00:00', ['recurring_total' => 800, 'trial_days' => 14, 'canceled_at' => '2025-07-10 10:00:00']);
                // A trial whose first charge failed: not canceled yet, but it ended without paying
                $sub(8, Status::SUBSCRIPTION_FAILING, '2025-07-04 10:00:00', ['recurring_total' => 700, 'trial_days' => 7]);
                // A trial counts as new when it first pays, as Stripe Billing counts it: June start paid in July is new in July,
                // July start paid in August is not
                [$s9] = $sub(9, Status::SUBSCRIPTION_ACTIVE, '2025-06-25 10:00:00', ['recurring_total' => 600, 'trial_days' => 14]);
                [$s10] = $sub(10, Status::SUBSCRIPTION_ACTIVE, '2025-07-25 10:00:00', ['recurring_total' => 400, 'trial_days' => 14]);

                $renewal = function ($parent, $cents, $at, $paymentStatus) use ($order) {
                    return $order(['type' => Status::ORDER_TYPE_RENEWAL, 'parent_id' => (int) $parent->id, 'payment_status' => $paymentStatus,
                        'subtotal' => $cents, 'total_amount' => $cents, 'total_paid' => $paymentStatus === Status::PAYMENT_PAID ? $cents : 0,
                        'created_at' => $at, 'updated_at' => $at]);
                };
                $renewal($s1, 1000, '2025-07-10 10:00:00', Status::PAYMENT_PAID);
                $renewal($s6, 1500, '2025-07-16 10:00:00', Status::PAYMENT_PAID);
                $renewal($s9, 600, '2025-07-09 10:00:00', Status::PAYMENT_PAID);
                $renewal($s10, 400, '2025-08-08 10:00:00', Status::PAYMENT_PAID);
                // A declined renewal charge: the store records a failed charge transaction on a pending renewal order
                $declined = $renewal($s1, 1000, '2025-07-20 10:00:00', Status::PAYMENT_PENDING);
                $transactionIds[] = (int) \FluentCart\App\Models\OrderTransaction::query()->create([
                    'order_id' => (int) $declined->id, 'order_type' => Status::ORDER_TYPE_RENEWAL, 'payment_method' => 'stripe',
                    'payment_mode' => 'test', 'payment_method_type' => '', 'currency' => $fcSubCurrency, 'rate' => 1,
                    'transaction_type' => Status::TRANSACTION_TYPE_CHARGE, 'vendor_charge_id' => '', 'status' => Status::TRANSACTION_FAILED,
                    'total' => 1000, 'meta' => [], 'created_at' => '2025-07-20 10:00:00', 'updated_at' => '2025-07-20 10:00:00',
                ])->id;

                $params = [
                    'startDate' => new DateTime('2025-07-01 00:00:00'), 'endDate' => new DateTime('2025-07-31 23:59:59'),
                    'currency' => $fcSubCurrency, 'paymentStatus' => Status::getReportStatuses(), 'variationIds' => [], 'groupKey' => 'monthly',
                ];
                $o = SubscriptionReportService::make()->getOverview($params);
                $p = $o['period'];

                // The free trial canceled before paying was never a subscriber: it shows only in trial conversion
                // A cancel keeps the paid term: the monthly plan bought 8 July and canceled 20 July leaves on 8 August
                FcTest::assertSame([4, 1, 2, 50.0, 3], [$p['new'], $p['churned'], $p['active_at_start'], round((float) $p['churn_rate'], 2), $p['net_change']],
                    'New 4 (with the June trial paid in July), churned 1 (from the starting base of 2: 50%), net +3; the installment plan, unpaid trials and the trial paid in August stay out');
                FcTest::assertSame(0, $p['churned_new'] ?? null, 'The July sign-up canceled in July is still paid up at the end of July');
                FcTest::assertSame([36.0, 20.0, 16.0], [round((float) $p['new_mrr'], 2), round((float) $p['churned_mrr'], 2), round((float) $p['net_mrr'], 2)],
                    'New MRR 10 + 5 + 15 + 6, churned MRR 20, net 16');
                $august = SubscriptionReportService::make()->getOverview(array_merge($params, [
                    'startDate' => new DateTime('2025-08-01 00:00:00'), 'endDate' => new DateTime('2025-08-31 23:59:59'),
                ]), false)['period'];
                FcTest::assertSame([1, 5.0], [$august['churned'], round((float) $august['churned_mrr'], 2)], 'It churns when its paid month ends, in August');
                // Net sales: 10 + 15 + 6 paid, less the fixture's 1.23 of tax on each
                FcTest::assertSame([3, 27.31, 1, 10.0], [$p['renewals'], round((float) $p['renewal_amount'], 2), $p['failed_renewals'], round((float) $p['failed_amount'], 2)],
                    'Renewals paid and failed in July');
                $byType = array_column(\FluentCart\App\Services\Report\RevenueReportService::make()->getRevenueByOrderType($params), null, 'key');
                FcTest::assertSame(round((float) ($byType[Status::ORDER_TYPE_RENEWAL]['net_sales'] ?? -1), 2), round((float) $p['renewal_amount'], 2),
                    'The Renewals card equals the Revenue report\'s Renewal row');

                $july = array_column($o['chart'], null, 'group')['2025-07'] ?? [];
                FcTest::assertSame([4, 1], [(int) ($july['new'] ?? -1), (int) ($july['churned'] ?? -1)], 'Movement chart, July');
                $june = array_column(SubscriptionReportService::make()->getOverview(array_merge($params, [
                    'startDate' => new DateTime('2025-06-01 00:00:00'), 'endDate' => new DateTime('2025-06-30 23:59:59'),
                ]), false)['chart'], null, 'group')['2025-06'] ?? [];
                FcTest::assertSame(2, (int) ($june['new'] ?? -1), 'June counts the two paid starts, not the trial that paid in July');

                // The product filter reaches failed renewals too: the declined charge belongs to the first plan
                $other = SubscriptionReportService::make()->getOverview(array_merge($params, ['variationIds' => [$base + 7]]), false)['period'];
                FcTest::assertSame([0, 0.0], [$other['failed_renewals'], round((float) $other['failed_amount'], 2)], 'Failed renewals follow the product filter');

                FcTest::assertSame(['monthly' => 35.0, 'yearly' => 10.0], array_map(function ($v) {
                    return round((float) $v, 2);
                }, array_column($o['mrr_by_interval'], 'mrr', 'key')), 'MRR by billing interval now');
                FcTest::assertSame(45.0, round(array_sum(array_column($o['mrr_by_plan'], 'mrr')), 2), 'MRR by plan adds up to MRR now');
                FcTest::assertSame(true, array_key_exists('variation', $o['mrr_by_plan'][0] ?? []), 'A plan row names its variation');

                FcTest::assertSame([4, 2, 2, 0, 50.0], [$o['trials']['started'], $o['trials']['converted'], $o['trials']['lost'],
                    $o['trials']['in_trial'], round((float) $o['trials']['conversion_rate'], 2)], 'Free trials started in July: two converted, two ended unpaid (canceled, failing)');

                $route = FcTest::rest('GET', 'reports/subscriptions-overview', ['params' => [
                    'startDate' => '2025-07-01', 'endDate' => '2025-07-31', 'currency' => $fcSubCurrency, 'groupKey' => 'monthly',
                ]]);
                FcTest::assertSame([200, 4], [$route['status'], (int) ($route['data']['period']['new'] ?? -1)], 'GET /reports/subscriptions-overview');
            } finally {
                if ($transactionIds) {
                    App::db()->table('fct_order_transactions')->whereIn('id', $transactionIds)->delete();
                }
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
