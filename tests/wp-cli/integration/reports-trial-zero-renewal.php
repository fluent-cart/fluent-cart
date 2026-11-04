<?php
/**
 * A free trial becomes a paying subscription at its first renewal that took money. A renewal marked paid at 0 is an
 * order, not a payment: it neither starts the subscription on the Subscriptions page nor puts the customer in a cohort.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\RetentionSnapshotService;
use FluentCart\App\Services\Report\SubscriptionReportService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcTrialCurrency = 'XTS';

return [
    [
        'id'            => 'reports-trial-zero-renewal',
        'name'          => 'A trial starts paying at its first renewal that took money, not at a renewal paid at 0',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC',
        'run'           => function () use ($fcTrialCurrency) {
            try {
                $db = App::db();
                $foreign = (int) $db->table('fct_orders')->where('currency', $fcTrialCurrency)->count()
                    + (int) $db->table('fct_subscriptions')->where('currency', $fcTrialCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' rows already use currency ' . $fcTrialCurrency);
                }
                FcFixture::customer();
                $base = 1690000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 20;
                $order = function (array $attributes) use ($fcTrialCurrency) {
                    return FcFixture::reportOrder(array_merge([
                        'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'currency' => $fcTrialCurrency,
                        'subtotal' => 0, 'total_amount' => 0, 'total_paid' => 0,
                    ], $attributes));
                };
                // A 14-day free trial from 1 February
                $parent = $order(['type' => Status::ORDER_TYPE_SUBSCRIPTION, 'created_at' => '2025-02-01 10:00:00', 'updated_at' => '2025-02-01 10:00:00']);
                $row = FcDomainFixture::subscription($parent, $base + 2, $base + 3, Status::SUBSCRIPTION_ACTIVE, 'trial-zero-renewal',
                    ['currency' => $fcTrialCurrency, 'canceled_at' => null, 'billing_interval' => 'monthly', 'trial_days' => 14,
                        'recurring_amount' => 2000, 'recurring_total' => 2000]);
                $db->table('fct_subscriptions')->where('id', (int) $row->id)->update(['created_at' => '2025-02-01 10:00:00']);
                // Its first renewal was marked paid at 0 on 15 February; the first payment came on 15 March
                $order(['type' => Status::ORDER_TYPE_RENEWAL, 'parent_id' => (int) $parent->id,
                    'created_at' => '2025-02-15 10:00:00', 'updated_at' => '2025-02-15 10:00:00']);
                $order(['type' => Status::ORDER_TYPE_RENEWAL, 'parent_id' => (int) $parent->id, 'subtotal' => 2000, 'total_amount' => 2000,
                    'total_paid' => 2000, 'created_at' => '2025-03-15 10:00:00', 'updated_at' => '2025-03-15 10:00:00']);

                $period = function ($start, $end) use ($fcTrialCurrency) {
                    return SubscriptionReportService::make()->getOverview([
                        'startDate' => new DateTime($start), 'endDate' => new DateTime($end), 'currency' => $fcTrialCurrency,
                        'variationIds' => [], 'groupKey' => 'monthly', 'paymentStatus' => Status::getReportStatuses(),
                    ], false)['period'];
                };
                FcTest::assertSame([0, 1], [$period('2025-02-01 00:00:00', '2025-02-28 23:59:59')['new'], $period('2025-03-01 00:00:00', '2025-03-31 23:59:59')['new']],
                    'New on the Subscriptions page in March, when it first paid; not in February');

                $snapshots = new class extends RetentionSnapshotService {
                    public function firstPaid(): array
                    {
                        return $this->firstPaidRenewals();
                    }
                };
                FcTest::assertSame('2025-03-15', substr((string) ($snapshots->firstPaid()[(int) $parent->id] ?? ''), 0, 10),
                    'The cohort month is the first renewal that took money');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-trial-late-payment',
        'name'          => 'A trial paid after its invoice month starts in the month it was paid',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC',
        'run'           => function () use ($fcTrialCurrency) {
            try {
                $db = App::db();
                $foreign = (int) $db->table('fct_orders')->where('currency', $fcTrialCurrency)->count()
                    + (int) $db->table('fct_subscriptions')->where('currency', $fcTrialCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' rows already use currency ' . $fcTrialCurrency);
                }
                FcFixture::customer();
                $base = 1700000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 20;
                $order = function (array $attributes) use ($fcTrialCurrency) {
                    return FcFixture::reportOrder(array_merge([
                        'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'currency' => $fcTrialCurrency,
                        'subtotal' => 0, 'total_amount' => 0, 'total_paid' => 0,
                    ], $attributes));
                };
                $parent = $order(['type' => Status::ORDER_TYPE_SUBSCRIPTION, 'created_at' => '2025-09-15 10:00:00', 'updated_at' => '2025-09-15 10:00:00']);
                $row = FcDomainFixture::subscription($parent, $base + 2, $base + 3, Status::SUBSCRIPTION_ACTIVE, 'trial-late-payment',
                    ['currency' => $fcTrialCurrency, 'canceled_at' => null, 'billing_interval' => 'monthly', 'trial_days' => 14,
                        'recurring_amount' => 3000, 'recurring_total' => 3000]);
                $db->table('fct_subscriptions')->where('id', (int) $row->id)->update(['created_at' => '2025-09-15 10:00:00']);
                // The trial-end invoice is created on 29 September and paid on 3 October
                $order(['type' => Status::ORDER_TYPE_RENEWAL, 'parent_id' => (int) $parent->id, 'subtotal' => 3000, 'total_amount' => 3000,
                    'total_paid' => 3000, 'created_at' => '2025-09-29 10:00:00', 'completed_at' => '2025-10-03 10:00:00',
                    'updated_at' => '2025-10-03 10:00:00']);

                $period = function ($start, $end) use ($fcTrialCurrency) {
                    return SubscriptionReportService::make()->getOverview([
                        'startDate' => new DateTime($start), 'endDate' => new DateTime($end), 'currency' => $fcTrialCurrency,
                        'variationIds' => [], 'groupKey' => 'monthly', 'paymentStatus' => Status::getReportStatuses(),
                    ], false)['period'];
                };
                FcTest::assertSame([0, 1], [$period('2025-09-01 00:00:00', '2025-09-30 23:59:59')['new'], $period('2025-10-01 00:00:00', '2025-10-31 23:59:59')['new']],
                    'New in October, when the payment completed, as Cohorts and Retention count it');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
