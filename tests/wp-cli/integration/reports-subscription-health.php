<?php
/**
 * Subscription health cards by status, and the Retention table's current month on the same rules:
 * a stale expire_at on a live subscription is ignored, a simulated trial counts at the price paid now,
 * a real trial counts 0, failing and paused stay out of MRR.
 */

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\SubscriptionReportService;

// No store row uses the ISO test currency, so every report scoped to it reads only these fixtures.
$fcHealthCurrency = 'XTS';

return [
    [
        'id'            => 'reports-subscription-health',
        'name'          => 'Subscription health cards and the current Retention month agree on MRR',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcHealthCurrency) {
            try {
                $db = App::db();
                $foreign = (int) $db->table('fct_subscriptions')->where('currency', $fcHealthCurrency)->count()
                    + (int) $db->table('fct_orders')->where('currency', $fcHealthCurrency)->count();
                if ($foreign !== 0) {
                    throw new RuntimeException('Refusing to seed: ' . $foreign . ' rows already use currency ' . $fcHealthCurrency);
                }
                FcFixture::customer();
                $base = 1800000000 + (hexdec(substr(hash('sha256', FcFixture::identity()), 0, 6)) % 1000000) * 20;

                // [status, interval, recurring_total, extra subscription attributes, parent paid, parent line]
                $plans = [
                    'active-stale-expiry' => [Status::SUBSCRIPTION_ACTIVE, 'yearly', 12000, ['expire_at' => '2001-06-01 00:00:00'], 12000, 12000],
                    'active-quarterly'    => [Status::SUBSCRIPTION_ACTIVE, 'quarterly', 3000, [], 3000, 3000],
                    'expiring-monthly'    => [Status::SUBSCRIPTION_EXPIRING, 'monthly', 500, [], 500, 500],
                    // A discounted first year: 120.00 paid now, 240.00 at renewal
                    'simulated-trial'     => [Status::SUBSCRIPTION_TRIALING, 'yearly', 24000, ['config' => ['is_trial_days_simulated' => 'yes']], 12000, 12000],
                    'real-trial'          => [Status::SUBSCRIPTION_TRIALING, 'yearly', 6000, [], 0, 0],
                    'failing'             => [Status::SUBSCRIPTION_FAILING, 'yearly', 12000, ['next_billing_date' => '2001-03-01 00:00:00'], 12000, 12000],
                    'paused'              => [Status::SUBSCRIPTION_PAUSED, 'monthly', 100, [], 100, 100],
                    'canceled'            => [Status::SUBSCRIPTION_CANCELED, 'yearly', 9900, ['canceled_at' => '2001-04-01 00:00:00'], 9900, 9900],
                ];

                $index = 0;
                foreach ($plans as $suffix => $plan) {
                    [$status, $interval, $amount, $extra, $paid, $line] = $plan;
                    $order = FcFixture::reportOrder([
                        'status'         => Status::ORDER_COMPLETED,
                        'payment_status' => $paid > 0 ? 'paid' : 'pending',
                        'currency'       => $fcHealthCurrency,
                        'type'           => Status::ORDER_TYPE_SUBSCRIPTION,
                        'subtotal'       => $line,
                        'tax_total'      => 0,
                        'total_amount'   => $paid,
                        'total_paid'     => $paid,
                        'created_at'     => '2001-01-10 10:00:00',
                        'updated_at'     => '2001-01-10 10:00:00',
                    ]);
                    $productId = $base + ($index++ * 2);
                    FcFixture::reportOrderItem((int) $order->id, [
                        'post_id' => $productId, 'object_id' => $productId + 1, 'payment_type' => 'subscription',
                        'unit_price' => $line, 'subtotal' => $line, 'line_total' => $line,
                    ]);
                    $subscription = FcDomainFixture::subscription($order, $productId, $productId + 1, $status, 'health-' . $suffix, array_merge([
                        'currency'          => $fcHealthCurrency,
                        'billing_interval'  => $interval,
                        'recurring_amount'  => $amount,
                        'recurring_total'   => $amount,
                        'bill_times'        => 0,
                        'canceled_at'       => null,
                        'next_billing_date' => gmdate('Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS),
                    ], $extra));
                    $db->table('fct_subscriptions')->where('id', (int) $subscription->id)
                        ->update(['created_at' => '2001-01-10 10:00:00']);
                }

                $service = SubscriptionReportService::make();
                $health = $service->getHealth(['currency' => $fcHealthCurrency, 'variationIds' => []]);

                // 120.00 / 12 + 30.00 / 3 + 5.00 = 25.00 active; 120.00 / 12 = 10.00 paid now
                FcTest::assertSame(['count' => 3, 'mrr' => 25.0], $health['active'], 'Active and expiring at the renewal price, the stale expiry ignored, quarterly ÷ 3');
                FcTest::assertSame(['count' => 1, 'mrr' => 10.0, 'renewal_mrr' => 20.0, 'uplift' => 10.0], $health['discounted'], 'A simulated trial counts at the price paid now; the uplift is what renewal adds');
                FcTest::assertSame(35.0, $health['mrr'], 'MRR = active + discounted first cycles');
                FcTest::assertSame(4, $health['subscriptions'], 'Paying subscriptions');
                FcTest::assertSame(8.75, $health['arpu'], 'ARPU = MRR / paying subscriptions');
                FcTest::assertSame(['count' => 1, 'renewal_mrr' => 5.0], $health['trial'], 'A real trial stays out of MRR');
                FcTest::assertSame(['count' => 1, 'mrr' => 10.0], $health['failing'], 'Failing is its own at-risk figure');
                FcTest::assertSame(['count' => 1, 'mrr' => 1.0], $health['paused'], 'Paused is shown apart');

                $rows = $service->getRetentionData([
                    'startDate'    => new DateTime(gmdate('Y-m-01 00:00:00')),
                    'endDate'      => new DateTime(gmdate('Y-m-d 23:59:59')),
                    'currency'     => $fcHealthCurrency,
                    'variationIds' => [],
                ]);
                $month = end($rows);
                FcTest::assertSame(['35.00', '5'], [$month['mrr'], $month['active_subscriptions']],
                    'The Retention month ending today has the cards\' MRR; its count adds the real trial');

                // Cohort snapshots on the same rules: all-product cohorts retained this month = the MRR card
                $snapshots = new class extends \FluentCart\App\Services\Report\RetentionSnapshotService {
                    public function retainedCents($subscriptions, string $month): array
                    {
                        // The rows of the month's cohorts, store-wide (all products, all intervals)
                        $aggregates = $this->aggregate($subscriptions, [$month], null, $this->firstPaidRenewals());
                        $rows = $this->publishedRows($this->buildRows(['all' => ['' => $aggregates['XTS']['all'][''] ?? []]], [$month], 'XTS', 0));

                        return array_column($rows, 'retained_mrr');
                    }
                };
                $subscriptions = $db->table('fct_subscriptions')->where('currency', $fcHealthCurrency)
                    ->whereNotIn('status', SubscriptionReportService::RETENTION_EXCLUDED_STATUSES)
                    ->where('bill_times', '<=', 0)->orderBy('customer_id')->orderBy('created_at')->get();
                FcTest::assertSame((int) round($health['mrr'] * 100), array_sum($snapshots->retainedCents($subscriptions, gmdate('Y-m'))),
                    'Σ all-product cohorts retained MRR for the current month = the health MRR card');
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
