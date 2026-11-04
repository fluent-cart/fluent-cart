<?php
/**
 * A canceled subscription keeps its paid term: it stays live (health cards, MRR by plan, the Retention page's current
 * month) until the term ends, and the health cards show it as canceling. A refunded one ends at once.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\SubscriptionReportService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcCancelingCurrency = 'XTS';

return [
    [
        'id'            => 'reports-subscription-canceling',
        'name'          => 'A canceled subscription stays live until its paid term ends and shows as canceling',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC',
        'run'           => function () use ($fcCancelingCurrency) {
            try {
                $db = App::db();
                $foreign = (int) $db->table('fct_orders')->where('currency', $fcCancelingCurrency)->count()
                    + (int) $db->table('fct_subscriptions')->where('currency', $fcCancelingCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' rows already use currency ' . $fcCancelingCurrency);
                }
                FcFixture::customer();
                $base = 1680000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 20;
                $n = 0;
                $ago = function ($days) {
                    return gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
                };
                $sub = function ($status, $createdAt, $cents, array $extra = [], $paidCents = null) use (&$n, $base, $fcCancelingCurrency, $db) {
                    $n++;
                    $paid = $paidCents ?? $cents;
                    $parent = FcFixture::reportOrder([
                        'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'currency' => $fcCancelingCurrency,
                        'type' => Status::ORDER_TYPE_SUBSCRIPTION, 'subtotal' => $paid, 'total_amount' => $paid, 'total_paid' => $paid,
                        'created_at' => $createdAt, 'updated_at' => $createdAt,
                    ]);
                    FcFixture::reportOrderItem((int) $parent->id, ['post_id' => $base + $n * 2, 'object_id' => $base + $n * 2 + 1,
                        'payment_type' => 'subscription', 'unit_price' => $paid, 'subtotal' => $paid, 'line_total' => $paid]);
                    $row = FcDomainFixture::subscription($parent, $base + $n * 2, $base + $n * 2 + 1, $status, 'canceling-' . $n,
                        array_merge(['currency' => $fcCancelingCurrency, 'canceled_at' => null, 'billing_interval' => 'monthly',
                            'recurring_amount' => $cents, 'recurring_total' => $cents, 'next_billing_date' => null], $extra));
                    $db->table('fct_subscriptions')->where('id', (int) $row->id)->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);
                };

                $sub(Status::SUBSCRIPTION_ACTIVE, $ago(10), 1000);
                // Bought 10 days ago, canceled 2 days ago: paid until 20 days from now
                $sub(Status::SUBSCRIPTION_CANCELED, $ago(10), 2000, ['canceled_at' => $ago(2), 'config' => ['cancellation_reason' => 'cancelled_by_customer']]);
                // Refunded: gone at once
                $sub(Status::SUBSCRIPTION_CANCELED, $ago(10), 500, ['canceled_at' => $ago(2), 'config' => ['cancellation_reason' => 'refunded']]);
                // A discounted first month (paid 5 on a 20 plan), canceled: in MRR at the 5 it paid, not 20
                $sub(Status::SUBSCRIPTION_CANCELED, $ago(5), 2000, ['canceled_at' => $ago(1), 'trial_days' => 30,
                    'config' => ['cancellation_reason' => 'cancelled_by_customer', 'is_trial_days_simulated' => 'yes']], 500);
                // Replaced by an upgrade with no cancel reason stored: the new plan carries on, this one does not stay live
                $sub(Status::SUBSCRIPTION_CANCELED, $ago(10), 1500, ['canceled_at' => $ago(2), 'config' => ['upgraded_to_sub_id' => 999999999]]);
                // Canceled long ago: its paid month is over
                $sub(Status::SUBSCRIPTION_CANCELED, '2025-01-05 10:00:00', 700, ['canceled_at' => '2025-01-06 10:00:00']);

                $service = SubscriptionReportService::make();
                $health = $service->getHealth(['currency' => $fcCancelingCurrency, 'variationIds' => []]);
                FcTest::assertSame([3, 35.0, ['count' => 2, 'mrr' => 25.0]], [$health['subscriptions'], $health['mrr'], $health['canceling']],
                    'Live: the active plan and the two canceled ones still paid up (the discounted one at the 5 it paid); not the refund nor the old cancel');

                $params = [
                    'startDate' => new DateTime(gmdate('Y-m-01 00:00:00')), 'endDate' => new DateTime(gmdate('Y-m-d 23:59:59')),
                    'currency' => $fcCancelingCurrency, 'variationIds' => [], 'groupKey' => 'monthly', 'paymentStatus' => Status::getReportStatuses(),
                ];
                $overview = $service->getOverview($params);
                FcTest::assertSame(35.0, round(array_sum(array_column($overview['mrr_by_plan'], 'mrr')), 2), 'MRR by plan counts them too');

                $rows = $service->getRetentionData($params);
                $month = end($rows);
                FcTest::assertSame('35.00', $month['mrr'], 'The Retention page\'s current month counts their MRR');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
