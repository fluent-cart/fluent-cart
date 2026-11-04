<?php

namespace Tests\Integration\Services\Report;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\Report\RetentionSnapshotService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\SubscriptionFactory;

/**
 * The browser builds snapshots in short steps (no Action Scheduler, no long request): each step reads one range of
 * customers into a build table, the last step publishes. The result must equal a one-step build, and the published
 * table must not change until the last step.
 */
class RetentionSnapshotChunkedBuildTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        global $wpdb;
        delete_option(RetentionSnapshotService::LOCK_OPTION);
        $wpdb->query("DELETE FROM {$wpdb->prefix}fct_retention_snapshots");
        $wpdb->query("DELETE FROM {$wpdb->prefix}fct_retention_snapshots_build");
        parent::tearDown();
    }

    /** Six customers with monthly plans in two cohort months, one canceled, so retention moves */
    private function seedSubscriptions(): void
    {
        global $wpdb;
        foreach ([['2004-03', 1000, null], ['2004-03', 2500, '2004-05-20'], ['2004-03', 1500, null],
            ['2004-04', 1000, null], ['2004-04', 3000, '2004-06-02'], ['2004-04', 1200, null]] as [$month, $amount, $canceledAt]) {
            $customer = (new CustomerFactory())->create();
            $order = (new OrderFactory())->create([
                'customer_id' => $customer->id, 'currency' => 'USD', 'status' => Status::ORDER_COMPLETED,
                'payment_status' => Status::PAYMENT_PAID, 'subtotal' => $amount, 'total_amount' => $amount, 'total_paid' => $amount,
            ]);
            $subscription = (new SubscriptionFactory())->create([
                'customer_id' => $customer->id, 'parent_order_id' => $order->id, 'product_id' => 1, 'variation_id' => 1,
                'billing_interval' => 'monthly', 'recurring_amount' => $amount, 'recurring_total' => $amount,
                'status' => $canceledAt ? Status::SUBSCRIPTION_CANCELED : Status::SUBSCRIPTION_ACTIVE,
            ]);
            $wpdb->update($wpdb->prefix . 'fct_subscriptions', array_filter([
                'created_at'  => $month . '-10 10:00:00',
                'canceled_at' => $canceledAt ? $canceledAt . ' 10:00:00' : null,
            ]), ['id' => $subscription->id]);
        }
    }

    private function service(int $customersPerStep): RetentionSnapshotService
    {
        return new class($customersPerStep) extends RetentionSnapshotService {
            public function __construct(int $customersPerStep)
            {
                $this->customersPerStep = $customersPerStep;
            }
        };
    }

    private function publishedRows(): array
    {
        global $wpdb;

        return $wpdb->get_results("SELECT cohort, period, product_id, currency, billing_interval, rebuy_adjusted, period_offset,
            cohort_customers, cohort_mrr, retained_customers, retained_mrr, churned_customers, retention_rate_customers, retention_rate_mrr
            FROM {$wpdb->prefix}fct_retention_snapshots WHERE cohort LIKE '2004-%'
            ORDER BY cohort, period, product_id, currency, billing_interval, rebuy_adjusted", ARRAY_A);
    }

    public function test_a_build_in_small_steps_equals_a_build_in_one_step(): void
    {
        $this->seedSubscriptions();

        $this->assertTrue($this->service(100000)->generate()['success']);
        $oneStep = $this->publishedRows();
        $this->assertNotEmpty($oneStep);

        $this->assertTrue($this->service(1)->generate()['success']);

        $this->assertSame($oneStep, $this->publishedRows());
    }

    public function test_the_published_table_changes_only_on_the_last_step(): void
    {
        global $wpdb;
        $this->seedSubscriptions();
        $wpdb->insert($wpdb->prefix . 'fct_retention_snapshots', [
            'cohort' => '1999-01', 'period' => '1999-01', 'product_id' => null, 'currency' => 'USD', 'billing_interval' => '', 'rebuy_adjusted' => 0,
        ]);
        $service = $this->service(1);

        $started = $service->start();
        $this->assertTrue($started['success']);
        $this->assertGreaterThan(1, $started['steps']);

        $step = $service->step($started['token']);
        $this->assertFalse($step['done']);
        $this->assertSame(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_retention_snapshots WHERE cohort = '1999-01'"),
            'the old snapshots stay until the build is published');

        do {
            $step = $service->step($started['token']);
        } while (!$step['done']);

        $this->assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_retention_snapshots WHERE cohort = '1999-01'"));
        $this->assertFalse(get_option(RetentionSnapshotService::LOCK_OPTION), 'the last step releases the lock');
    }

    public function test_a_step_without_a_started_build_is_refused(): void
    {
        $result = $this->service(1)->step();

        $this->assertFalse($result['success']);
        $this->assertSame('no_build', $result['code']);
    }

    public function test_a_second_step_while_one_runs_is_refused_and_counts_nothing_twice(): void
    {
        $this->seedSubscriptions();
        $this->assertTrue($this->service(100000)->generate()['success']);
        $expected = $this->publishedRows();

        // The same token steps again while the first step still builds its range (a retried or doubled request)
        $service = new class extends RetentionSnapshotService {
            public $token = '';
            public $overlap = [];

            public function __construct()
            {
                $this->customersPerStep = 2;
            }

            protected function buildRange(int $from, int $to, array $months): void
            {
                if (!$this->overlap) {
                    $this->overlap = (new RetentionSnapshotService())->step($this->token);
                }
                parent::buildRange($from, $to, $months);
            }
        };
        $started = $service->start();
        $service->token = $started['token'];
        do {
            $step = $service->step($started['token']);
            $this->assertTrue($step['success']);
        } while (!$step['done']);

        $this->assertSame([false, 'already_running'], [$service->overlap['success'], $service->overlap['code'] ?? null]);
        $this->assertSame($expected, $this->publishedRows());
    }

    public function test_a_step_reads_the_renewals_of_its_own_subscriptions(): void
    {
        global $wpdb;
        $this->seedSubscriptions();
        // The canceled plans paid one more month before they canceled, so they stay a month longer
        foreach ($wpdb->get_results("SELECT customer_id, parent_order_id, canceled_at FROM {$wpdb->prefix}fct_subscriptions WHERE canceled_at LIKE '2004-%'") as $sub) {
            (new OrderFactory())->create([
                'customer_id' => $sub->customer_id, 'parent_id' => $sub->parent_order_id, 'type' => Status::ORDER_TYPE_RENEWAL, 'currency' => 'USD',
                'status' => Status::ORDER_COMPLETED, 'payment_status' => Status::PAYMENT_PAID, 'total_amount' => 1000, 'total_paid' => 1000,
                'created_at' => gmdate('Y-m-d 10:00:00', strtotime($sub->canceled_at . ' -5 days')),
            ]);
        }
        $storeWide = new class extends RetentionSnapshotService {
            protected function paidRenewalDates(string $pick, bool $tookMoney = false, ?array $parentIds = null): array
            {
                return parent::paidRenewalDates($pick, $tookMoney, null);
            }
        };

        $this->assertTrue($storeWide->generate()['success']);
        $expected = $this->publishedRows();
        $this->assertTrue($this->service(100000)->generate()['success']);

        $this->assertSame($expected, $this->publishedRows());
    }
}
