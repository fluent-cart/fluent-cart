<?php
/**
 * Plan upgrades and downgrades: the subscription a customer moved away from is no churn and no lost renewal, the one
 * they moved to is no new subscription (it runs from the first plan's start), and the price difference is expansion
 * or contraction MRR on the Subscriptions page.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\SubscriptionReportService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcUpgradeCurrency = 'XTS';

return [
    [
        'id'            => 'reports-subscription-upgrades',
        'name'          => 'An upgrade or downgrade is no churn, no new subscription and no lost renewal; its difference is expansion MRR',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC', // fixtures are UTC days; reports-site-timezone covers other zones
        'run'           => function () use ($fcUpgradeCurrency) {
            try {
                $db = App::db();
                $foreign = (int) $db->table('fct_orders')->where('currency', $fcUpgradeCurrency)->count()
                    + (int) $db->table('fct_subscriptions')->where('currency', $fcUpgradeCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' rows already use currency ' . $fcUpgradeCurrency);
                }
                FcFixture::customer();
                $base = 1670000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 20;
                $n = 0;
                $sub = function ($status, $createdAt, $cents, array $extra = [], $paidCents = null) use (&$n, $base, $fcUpgradeCurrency, $db) {
                    $n++;
                    $paid = $paidCents ?? $cents;
                    $parent = FcFixture::reportOrder([
                        'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'currency' => $fcUpgradeCurrency,
                        'type' => Status::ORDER_TYPE_SUBSCRIPTION, 'subtotal' => $paid, 'total_amount' => $paid, 'total_paid' => $paid,
                        'created_at' => $createdAt, 'updated_at' => $createdAt,
                    ]);
                    $row = FcDomainFixture::subscription($parent, $base + $n * 2, $base + $n * 2 + 1, $status, 'upgrade-' . $n,
                        array_merge(['currency' => $fcUpgradeCurrency, 'canceled_at' => null, 'billing_interval' => 'monthly',
                            'recurring_amount' => $cents, 'recurring_total' => $cents], $extra));
                    $db->table('fct_subscriptions')->where('id', (int) $row->id)->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);

                    return (int) $row->id;
                };
                $link = function ($fromId, $toId) use ($db) {
                    $config = function ($id) use ($db) {
                        return json_decode((string) $db->table('fct_subscriptions')->where('id', $id)->value('config'), true) ?: [];
                    };
                    $db->table('fct_subscriptions')->where('id', $fromId)->update(['config' => json_encode(array_merge($config($fromId),
                        ['upgraded_to_sub_id' => $toId, 'cancellation_reason' => 'upgraded_to_new_plan']))]);
                    $db->table('fct_subscriptions')->where('id', $toId)->update(['config' => json_encode(array_merge($config($toId), ['upgraded_from_sub_id' => $fromId]))]);

                    return $toId;
                };

                // An upgrade on 15 March: 10 a month to 25 a month
                $old = $sub(Status::SUBSCRIPTION_CANCELED, '2025-01-10 10:00:00', 1000, ['canceled_at' => '2025-03-15 10:00:00']);
                $link($old, $sub(Status::SUBSCRIPTION_ACTIVE, '2025-03-15 10:00:00', 2500));
                // A downgrade on 20 March: 30 a month to 20 a month
                $old = $sub(Status::SUBSCRIPTION_CANCELED, '2025-01-05 10:00:00', 3000, ['canceled_at' => '2025-03-20 10:00:00']);
                $link($old, $sub(Status::SUBSCRIPTION_ACTIVE, '2025-03-20 10:00:00', 2000));
                // A new subscription in March
                $sub(Status::SUBSCRIPTION_ACTIVE, '2025-03-02 10:00:00', 1000);
                // New in March and upgraded in March: new at its upgraded price, no expansion on top
                $old = $sub(Status::SUBSCRIPTION_CANCELED, '2025-03-03 10:00:00', 1000, ['canceled_at' => '2025-03-10 10:00:00']);
                $link($old, $sub(Status::SUBSCRIPTION_ACTIVE, '2025-03-10 10:00:00', 2500));
                // A chain: January 10 a month, February 20, March 22; it runs from January
                $first = $sub(Status::SUBSCRIPTION_CANCELED, '2025-01-20 10:00:00', 1000, ['canceled_at' => '2025-02-05 10:00:00']);
                $second = $link($first, $sub(Status::SUBSCRIPTION_CANCELED, '2025-02-05 10:00:00', 2000, ['canceled_at' => '2025-03-25 10:00:00']));
                $link($second, $sub(Status::SUBSCRIPTION_ACTIVE, '2025-03-25 10:00:00', 2200));
                // An upgrade paid with a prorated credit (stored as a discounted first cycle): 10 a month to 30, 2 paid now.
                // It compares at the full plan price: +20, no contraction from the credit
                $old = $sub(Status::SUBSCRIPTION_CANCELED, '2025-01-15 10:00:00', 1000, ['canceled_at' => '2025-02-20 10:00:00']);
                $link($old, $sub(Status::SUBSCRIPTION_TRIALING, '2025-02-20 10:00:00', 3000, ['trial_days' => 20,
                    'config' => ['is_trial_days_simulated' => 'yes']], 200));
                // Another credit upgrade (10 to 30 on 5 March), refunded on 20 March: it leaves at its full 30, as it came in
                $old = $sub(Status::SUBSCRIPTION_CANCELED, '2025-01-12 10:00:00', 1000, ['canceled_at' => '2025-03-05 10:00:00']);
                $link($old, $sub(Status::SUBSCRIPTION_CANCELED, '2025-03-05 10:00:00', 3000, ['trial_days' => 20, 'canceled_at' => '2025-03-20 10:00:00',
                    'config' => ['is_trial_days_simulated' => 'yes', 'cancellation_reason' => 'refunded']], 200));

                $params = function (array $extra = []) use ($fcUpgradeCurrency) {
                    return array_merge([
                        'startDate' => new DateTime('2025-01-01 00:00:00'), 'endDate' => new DateTime('2025-03-31 23:59:59'),
                        'currency' => $fcUpgradeCurrency, 'variationIds' => [], 'groupKey' => 'monthly',
                        'paymentStatus' => Status::getReportStatuses(),
                    ], $extra);
                };
                $service = SubscriptionReportService::make();

                $rows = array_column($service->getRetentionData($params()), null, 'group');
                FcTest::assertSame([5, 0, 2], [$rows['2025-01']['new_subscriptions'], $rows['2025-02']['new_subscriptions'], $rows['2025-03']['new_subscriptions']],
                    'New: the five first plans in January (the chain and both credit upgrades too), two new subscriptions in March; the plans moved to are not new');
                FcTest::assertSame([1, 1, 5, '6'], [$rows['2025-03']['churned_subscriptions'], $rows['2025-03']['left_by_reason']['canceled'],
                    $rows['2025-03']['start_subscriptions'], $rows['2025-03']['active_subscriptions']],
                    'March: no churn from the plan changes, one from the refund; five at the start, six at the end');
                FcTest::assertSame([0, 0], [$rows['2025-02']['renewals_lost'], $rows['2025-03']['renewals_lost']], 'A plan moved away from is no lost renewal');

                $march = $service->getOverview($params(['startDate' => new DateTime('2025-03-01 00:00:00')]), false)['period'];
                FcTest::assertSame([2, 1], [$march['new'], $march['churned']], 'Subscriptions page: two new, one churned (the refund)');
                FcTest::assertSame([20.0, 30.0, 52.0, 10.0, 32.0], [round((float) $march['new_mrr'], 2), round((float) $march['churned_mrr'], 2),
                    round((float) $march['expansion_mrr'], 2), round((float) $march['contraction_mrr'], 2), round((float) $march['net_mrr'], 2)],
                    'New 10 + 10 (at first prices); upgrades +15 +15 +2 +20, downgrade -10; the refunded upgrade leaves at its full 30; net 32');
                $chart = array_column($service->getOverview($params(['startDate' => new DateTime('2025-03-01 00:00:00')]), false)['chart'], null, 'group');
                FcTest::assertSame([20.0, 52.0, 10.0], [(float) ($chart['2025-03']['new_mrr'] ?? -1), (float) ($chart['2025-03']['expansion_mrr'] ?? -1),
                    (float) ($chart['2025-03']['contraction_mrr'] ?? -1)], 'The movement chart carries the plan changes, so its net matches the card');
                $february = $service->getOverview($params(['startDate' => new DateTime('2025-02-01 00:00:00'), 'endDate' => new DateTime('2025-02-28 23:59:59')]), false)['period'];
                FcTest::assertSame([0, 30.0, 0.0], [$february['new'], round((float) $february['expansion_mrr'], 2), round((float) $february['contraction_mrr'], 2)],
                    'February: the chain\'s first upgrade (+10, kept though that plan moved on again) and the credit upgrade at full price (+20)');
                $january = $service->getOverview($params(['endDate' => new DateTime('2025-01-31 23:59:59')]), false)['period'];
                FcTest::assertSame([5, 70.0], [$january['new'], round((float) $january['new_mrr'], 2)],
                    'January: five new at their first prices, 10 + 30 + 10 + 10 + 10; later upgrades add only their difference');
                $health = $service->getHealth(['currency' => $fcUpgradeCurrency, 'variationIds' => []]);
                FcTest::assertSame([132.0, 0], [$health['mrr'], $health['discounted']['count']],
                    'Live MRR counts the credit upgrade at its full 30, not as a discounted first cycle: 25 + 20 + 10 + 25 + 22 + 30');

                // A product filter reads plans: a change to another product leaves this one and joins that one
                $firstProduct = $base + 2 + 1;
                $filtered = array_column($service->getRetentionData($params(['variationIds' => [$firstProduct]])), null, 'group');
                FcTest::assertSame([1, 1], [$filtered['2025-01']['new_subscriptions'], $filtered['2025-03']['churned_subscriptions']],
                    'Filtered to the first upgrade\'s old plan: it started in January and left that plan in March');

                // Cancel and rebuy is a Pro report
                if (class_exists(\FluentCartPro\App\Modules\Reports\Services\SubscriptionInsightsService::class)) {
                    $rebuys = \FluentCartPro\App\Modules\Reports\Services\SubscriptionInsightsService::make()
                        ->getRebuys($params(['endDate' => new DateTime('2025-06-30 23:59:59')]));
                    FcTest::assertSame(1, $rebuys['ended'], 'A plan moved away from is no ended term; only the refunded upgrade ended');
                }
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-subscription-upgrade-history',
        'name'          => 'Retention counts each plan of an upgrade chain at its own price, from the first plan of a long chain',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC',
        'run'           => function () use ($fcUpgradeCurrency) {
            try {
                $db = App::db();
                $foreign = (int) $db->table('fct_orders')->where('currency', $fcUpgradeCurrency)->count()
                    + (int) $db->table('fct_subscriptions')->where('currency', $fcUpgradeCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' rows already use currency ' . $fcUpgradeCurrency);
                }
                FcFixture::customer();
                $base = 1680000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 20;
                $n = 0;
                $sub = function ($status, $createdAt, $cents, array $extra = []) use (&$n, $base, $fcUpgradeCurrency, $db) {
                    $n++;
                    $parent = FcFixture::reportOrder([
                        'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'currency' => $fcUpgradeCurrency,
                        'type' => Status::ORDER_TYPE_SUBSCRIPTION, 'subtotal' => $cents, 'total_amount' => $cents, 'total_paid' => $cents,
                        'created_at' => $createdAt, 'updated_at' => $createdAt,
                    ]);
                    $row = FcDomainFixture::subscription($parent, $base + $n * 2, $base + $n * 2 + 1, $status, 'upgrade-history-' . $n,
                        array_merge(['currency' => $fcUpgradeCurrency, 'canceled_at' => null, 'billing_interval' => 'monthly',
                            'recurring_amount' => $cents, 'recurring_total' => $cents], $extra));
                    $db->table('fct_subscriptions')->where('id', (int) $row->id)->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);

                    return (int) $row->id;
                };
                $link = function ($fromId, $toId) use ($db) {
                    $config = function ($id) use ($db) {
                        return json_decode((string) $db->table('fct_subscriptions')->where('id', $id)->value('config'), true) ?: [];
                    };
                    $db->table('fct_subscriptions')->where('id', $fromId)->update(['config' => json_encode(array_merge($config($fromId),
                        ['upgraded_to_sub_id' => $toId, 'cancellation_reason' => 'upgraded_to_new_plan']))]);
                    $db->table('fct_subscriptions')->where('id', $toId)->update(['config' => json_encode(array_merge($config($toId), ['upgraded_from_sub_id' => $fromId]))]);

                    return $toId;
                };
                $moved = function ($createdAt, $cents, $leftAt) use ($sub) {
                    return $sub(Status::SUBSCRIPTION_CANCELED, $createdAt, $cents, ['canceled_at' => $leftAt]);
                };

                // 10 a month from 15 May, upgraded to 25 on 15 July
                $link($moved('2025-05-15 10:00:00', 1000, '2025-07-15 10:00:00'), $sub(Status::SUBSCRIPTION_ACTIVE, '2025-07-15 10:00:00', 2500));
                // 10 from 10 May, then 20, 30 and 40 on the 10th of June, July and August
                $b = $link($moved('2025-05-10 10:00:00', 1000, '2025-06-10 10:00:00'), $moved('2025-06-10 10:00:00', 2000, '2025-07-10 10:00:00'));
                $c = $link($b, $moved('2025-07-10 10:00:00', 3000, '2025-08-10 10:00:00'));
                $link($c, $sub(Status::SUBSCRIPTION_ACTIVE, '2025-08-10 10:00:00', 4000));

                $params = [
                    'startDate' => new DateTime('2025-05-01 00:00:00'), 'endDate' => new DateTime('2025-09-30 23:59:59'),
                    'currency' => $fcUpgradeCurrency, 'variationIds' => [], 'groupKey' => 'monthly', 'paymentStatus' => Status::getReportStatuses(),
                ];
                $service = SubscriptionReportService::make();
                $rows = array_column($service->getRetentionData($params), null, 'group');
                $column = function ($key) use ($rows) {
                    return array_map(function ($month) use ($rows, $key) {
                        return round((float) ($rows[$month][$key] ?? -1), 2);
                    }, ['2025-05', '2025-06', '2025-07', '2025-08', '2025-09']);
                };

                FcTest::assertSame([20.0, 30.0, 55.0, 65.0, 65.0], $column('mrr'),
                    'Period-end MRR: each plan at its own price while it ran (10 + 10, 10 + 20, 25 + 30, then 25 + 40)');
                FcTest::assertSame([0.0, 20.0, 30.0, 55.0, 65.0], $column('start_mrr'), 'Start MRR is the previous month\'s end');
                FcTest::assertSame([[2.0, 0.0, 0.0, 0.0, 0.0], 20.0], [$column('new_subscriptions'), $column('new_subscriptions_mrr')[0]],
                    'Two new subscriptions in May at their first prices; the plans moved to are not new');

                $overview = $service->getOverview($params, false)['period'];
                FcTest::assertSame([2, 20.0, 45.0, 65.0], [$overview['new'], round((float) $overview['new_mrr'], 2),
                    round((float) $overview['expansion_mrr'], 2), round((float) $overview['net_mrr'], 2)],
                    'Subscriptions page: new 10 + 10 in May, upgrades +15 +10 +10 +10; net 65 equals the MRR now');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
