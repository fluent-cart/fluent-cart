<?php

namespace Tests\Integration\Database;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Activity;
use FluentCart\App\Models\Order;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencySnapshot;
use FluentCart\Database\DataBackfills;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\SubscriptionFactory;

/**
 * A backfill that fails is retried from its cursor, retired after three
 * failures in a row, and tried again once per plugin version. A finished
 * backfill never runs again.
 */
class DataBackfillFailureTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;
    private SubscriptionFactory $subscriptionFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory     = new CustomerFactory();
        $this->orderFactory        = new OrderFactory();
        $this->subscriptionFactory = new SubscriptionFactory();

        $option = (array) fluent_cart_get_option('_db_migrations', [], false);
        $option['backfills'] = [];
        unset($option['backfill_failures']);
        fluent_cart_update_option('_db_migrations', $option);
    }

    public function test_a_throw_stalls_the_queue_and_keeps_the_job_pending(): void
    {
        global $wpdb;
        $throw = function ($budget, $context) {
            if ($context['slug'] === 'completed_next_billing_date') {
                throw new \RuntimeException('forced failure');
            }
            return $budget;
        };
        add_filter('fluent_cart/data_backfills/chunk_budget', $throw, 10, 2);

        $result = DataBackfills::processPending();
        remove_filter('fluent_cart/data_backfills/chunk_budget', $throw, 10);

        $this->assertSame('stalled', $result['status']);
        $this->assertContains('installment_payments', $result['completed'], 'jobs before the failed one still finish');
        $this->assertContains('completed_next_billing_date', $result['pending']);
        $this->assertContains('order_currency_snapshot', $result['pending'], 'later jobs wait behind the failed one');

        $failure = fluent_cart_get_option('_db_migrations', [], false)['backfill_failures']['completed_next_billing_date'];
        $this->assertSame(1, $failure['count']);
        $this->assertSame('forced failure', $failure['error']);

        $other = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $lockName = 'fct_db_backfill_' . md5(DB_NAME . '|' . $wpdb->prefix);
        $this->assertSame('1', (string) $other->get_var($other->prepare('SELECT IS_FREE_LOCK(%s)', $lockName)), 'the lock is released after a throw');
    }

    public function test_three_failures_retire_the_job_and_the_next_one_runs(): void
    {
        global $wpdb;
        $customer = $this->customerFactory->create();
        for ($i = 0; $i < 3; $i++) {
            $order = $this->orderFactory->create(['customer_id' => $customer->id]);
            $wpdb->update($this->fullTableName('fct_orders'), ['config' => json_encode(new \stdClass())], ['id' => $order->id]);
        }

        $budget = function ($budget, $context) {
            return $context['slug'] === 'order_currency_snapshot'
                ? ['chunk_size' => 1, 'max_chunks_per_run' => 1]
                : $budget;
        };
        add_filter('fluent_cart/data_backfills/chunk_budget', $budget, 10, 2);

        $this->assertSame('running', DataBackfills::processPending()['status']);
        $cursor = (int) fluent_cart_get_option('_fluent_cart_order_fx_cursor', 0, false);
        $this->assertGreaterThan(0, $cursor);

        $ordersTable = $this->fullTableName('fct_orders');
        $breakWrite = function ($query) use ($ordersTable) {
            return (stripos($query, 'UPDATE') === 0 && strpos($query, $ordersTable) !== false && strpos($query, '$.fx') !== false)
                ? 'UPDATE fct_no_such_table SET id = 1'
                : $query;
        };
        add_filter('query', $breakWrite);
        $suppressed = $wpdb->suppress_errors(true);
        $second = DataBackfills::processPending();
        $third = DataBackfills::processPending();
        $fourth = DataBackfills::processPending();
        $wpdb->suppress_errors($suppressed);
        remove_filter('query', $breakWrite);
        remove_filter('fluent_cart/data_backfills/chunk_budget', $budget, 10);

        $this->assertSame('stalled', $second['status']);
        $this->assertSame('stalled', $third['status']);
        $this->assertContains('order_currency_snapshot', $third['pending']);

        $this->assertSame('completed', $fourth['status'], 'a retired job no longer keeps the queue open');
        $this->assertContains('subscription_currency', $fourth['completed'], 'the next job runs once the failed one is retired');
        $this->assertNotContains('order_currency_snapshot', $fourth['completed']);
        $this->assertNotContains('order_currency_snapshot', DataBackfills::getPending());

        $option = fluent_cart_get_option('_db_migrations', [], false);
        $this->assertSame('failed', $option['backfills']['order_currency_snapshot']);
        $this->assertSame($cursor, (int) fluent_cart_get_option('_fluent_cart_order_fx_cursor', 0, false), 'the cursor is kept for a later retry');

        $warnings = Activity::query()
            ->where('status', 'warning')
            ->where('content', 'like', '%"order_currency_snapshot"%')
            ->get();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('fct_no_such_table', $warnings->first()->content);
        $this->assertStringContainsString('Last processed id: ' . $cursor . '.', $warnings->first()->content);
        $this->assertSame(0, Activity::query()
            ->where('status', 'info')
            ->where('content', 'like', '%"order_currency_snapshot" finished%')
            ->count(), 'a retired job is not logged as completed');
    }

    public function test_a_failure_after_progress_starts_the_count_again(): void
    {
        global $wpdb;
        $customer = $this->customerFactory->create();
        $first = $this->orderFactory->create(['customer_id' => $customer->id]);
        $second = $this->orderFactory->create(['customer_id' => $customer->id]);
        foreach ([$first->id, $second->id] as $id) {
            $wpdb->update($this->fullTableName('fct_orders'), ['config' => json_encode(new \stdClass())], ['id' => $id]);
        }

        $option = (array) fluent_cart_get_option('_db_migrations', [], false);
        $option['backfill_failures'] = [
            'order_currency_snapshot' => ['count' => 2, 'error' => 'earlier failure', 'version' => FLUENTCART_VERSION],
        ];
        fluent_cart_update_option('_db_migrations', $option);

        $budget = function ($budget, $context) {
            return $context['slug'] === 'order_currency_snapshot'
                ? ['chunk_size' => 1, 'max_chunks_per_run' => 5]
                : $budget;
        };
        add_filter('fluent_cart/data_backfills/chunk_budget', $budget, 10, 2);

        $ordersTable = $this->fullTableName('fct_orders');
        $secondId = (int) $second->id;
        $breakSecondWrite = function ($query) use ($ordersTable, $secondId) {
            return (stripos($query, 'UPDATE') === 0 && strpos($query, $ordersTable) !== false && preg_match('/WHERE id = ' . $secondId . '$/', $query))
                ? 'UPDATE fct_no_such_table SET id = 1'
                : $query;
        };
        add_filter('query', $breakSecondWrite);
        $suppressed = $wpdb->suppress_errors(true);
        $result = DataBackfills::processPending();
        $wpdb->suppress_errors($suppressed);
        remove_filter('query', $breakSecondWrite);
        remove_filter('fluent_cart/data_backfills/chunk_budget', $budget, 10);

        $this->assertSame('stalled', $result['status']);
        $this->assertContains('order_currency_snapshot', DataBackfills::getPending(), 'a job that moved forward is not retired');
        $this->assertSame(CurrencySnapshot::SOURCE_LEGACY_BASE, Order::query()->find($first->id)->config['fx']['source']);
        $this->assertSame((int) $first->id, (int) fluent_cart_get_option('_fluent_cart_order_fx_cursor', 0, false));
        $this->assertSame(1, fluent_cart_get_option('_db_migrations', [], false)['backfill_failures']['order_currency_snapshot']['count']);
    }

    public function test_progress_in_a_job_with_its_own_loop_starts_the_count_again(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id, 'type' => 'subscription']);
        $ids = [];
        for ($i = 0; $i < 2; $i++) {
            $ids[] = (int) $this->subscriptionFactory->create([
                'customer_id'       => $customer->id,
                'parent_order_id'   => $order->id,
                'product_id'        => 1,
                'variation_id'      => 1,
                'status'            => Status::SUBSCRIPTION_COMPLETED,
                'bill_times'        => 3,
                'bill_count'        => 3,
                'next_billing_date' => '2025-12-01 09:30:00',
            ])->id;
        }

        $option = (array) fluent_cart_get_option('_db_migrations', [], false);
        $option['backfill_failures'] = [
            'completed_next_billing_date' => ['count' => 2, 'error' => 'earlier failure', 'version' => FLUENTCART_VERSION],
        ];
        fluent_cart_update_option('_db_migrations', $option);

        $budget = function ($budget, $context) {
            return $context['slug'] === 'completed_next_billing_date'
                ? ['chunk_size' => 1, 'max_chunks_per_run' => 5]
                : $budget;
        };
        $secondId = $ids[1];
        $throwOnSecond = function ($payload) use ($secondId) {
            if (in_array($secondId, array_map('intval', $payload['ids']), true)) {
                throw new \RuntimeException('forced failure');
            }
        };
        add_filter('fluent_cart/data_backfills/chunk_budget', $budget, 10, 2);
        add_action('fluent_cart/data_backfills/chunk_selected', $throwOnSecond);
        $result = DataBackfills::processPending();
        remove_action('fluent_cart/data_backfills/chunk_selected', $throwOnSecond);
        remove_filter('fluent_cart/data_backfills/chunk_budget', $budget, 10);

        $this->assertSame('stalled', $result['status']);
        $this->assertNull($this->getRow('fct_subscriptions', $ids[0])->next_billing_date);
        $this->assertSame($ids[0], (int) fluent_cart_get_option('_fluent_cart_completed_billing_date_cursor', 0, false));
        $this->assertContains('completed_next_billing_date', DataBackfills::getPending(), 'a job that moved forward is not retired');
        $this->assertSame(1, fluent_cart_get_option('_db_migrations', [], false)['backfill_failures']['completed_next_billing_date']['count']);
    }

    public function test_a_clean_call_clears_the_failure_count(): void
    {
        $throw = function ($budget, $context) {
            if ($context['slug'] === 'order_currency_snapshot') {
                throw new \RuntimeException('forced failure');
            }
            return $budget;
        };
        add_filter('fluent_cart/data_backfills/chunk_budget', $throw, 10, 2);
        $failed = DataBackfills::processPending();
        remove_filter('fluent_cart/data_backfills/chunk_budget', $throw, 10);

        $this->assertSame('stalled', $failed['status']);
        $this->assertSame(1, fluent_cart_get_option('_db_migrations', [], false)['backfill_failures']['order_currency_snapshot']['count']);

        $this->assertSame('completed', DataBackfills::processPending()['status']);

        $option = fluent_cart_get_option('_db_migrations', [], false);
        $this->assertSame('yes', $option['backfills']['order_currency_snapshot']);
        $this->assertArrayNotHasKey('order_currency_snapshot', (array) ($option['backfill_failures'] ?? []));
    }

    public function test_a_broken_write_stores_the_database_error(): void
    {
        global $wpdb;
        $order = $this->orderFactory->create(['customer_id' => $this->customerFactory->create()->id]);
        $wpdb->update($this->fullTableName('fct_orders'), ['config' => json_encode(new \stdClass())], ['id' => $order->id]);

        $ordersTable = $this->fullTableName('fct_orders');
        $breakWrite = function ($query) use ($ordersTable) {
            return (stripos($query, 'UPDATE') === 0 && strpos($query, $ordersTable) !== false && strpos($query, '$.fx') !== false)
                ? 'UPDATE fct_no_such_table SET id = 1'
                : $query;
        };
        add_filter('query', $breakWrite);
        $suppressed = $wpdb->suppress_errors(true);
        $result = DataBackfills::processPending();
        $wpdb->suppress_errors($suppressed);
        remove_filter('query', $breakWrite);

        $this->assertSame('stalled', $result['status']);
        $failure = fluent_cart_get_option('_db_migrations', [], false)['backfill_failures']['order_currency_snapshot'];
        $this->assertSame(1, $failure['count']);
        $this->assertStringContainsString('fct_no_such_table', $failure['error']);
        $this->assertSame(FLUENTCART_VERSION, $failure['version']);
    }

    public function test_a_finished_job_never_runs_again(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id, 'type' => 'subscription']);
        $subscription = $this->subscriptionFactory->create([
            'customer_id'       => $customer->id,
            'parent_order_id'   => $order->id,
            'product_id'        => 1,
            'variation_id'      => 1,
            'status'            => Status::SUBSCRIPTION_COMPLETED,
            'bill_times'        => 3,
            'bill_count'        => 3,
            'next_billing_date' => '2025-12-01 09:30:00',
        ]);

        $option = (array) fluent_cart_get_option('_db_migrations', [], false);
        $option['backfills'] = array_fill_keys(array_keys(DataBackfills::getRegistry()), 'yes');
        $option['backfill_failures'] = [
            'completed_next_billing_date' => ['count' => 3, 'error' => 'earlier failure', 'version' => '0.0.1'],
        ];
        fluent_cart_update_option('_db_migrations', $option);

        $this->assertSame([], DataBackfills::getPending());
        $result = DataBackfills::processPending();

        $this->assertSame('completed', $result['status']);
        $this->assertSame([], $result['completed']);
        $this->assertSame('2025-12-01 09:30:00', $this->getRow('fct_subscriptions', $subscription->id)->next_billing_date, 'a finished job does not touch rows again');
    }

    public function test_a_retired_job_waits_for_a_new_version_then_resumes_from_its_cursor(): void
    {
        global $wpdb;
        $customer = $this->customerFactory->create();
        $first = $this->orderFactory->create(['customer_id' => $customer->id]);
        $second = $this->orderFactory->create(['customer_id' => $customer->id]);
        foreach ([$first->id, $second->id] as $id) {
            $wpdb->update($this->fullTableName('fct_orders'), ['config' => json_encode(new \stdClass())], ['id' => $id]);
        }

        $option = (array) fluent_cart_get_option('_db_migrations', [], false);
        $option['backfills'] = ['order_currency_snapshot' => 'failed'];
        $option['backfill_failures'] = [
            'order_currency_snapshot' => ['count' => 3, 'error' => 'earlier failure', 'version' => FLUENTCART_VERSION],
        ];
        fluent_cart_update_option('_db_migrations', $option);
        fluent_cart_update_option('_fluent_cart_order_fx_cursor', (int) $first->id);

        $this->assertNotContains('order_currency_snapshot', DataBackfills::getPending());
        $this->assertSame('completed', DataBackfills::processPending()['status']);
        $this->assertArrayNotHasKey('fx', (array) Order::query()->find($second->id)->config, 'a retired job does not run on the same version');

        $option = (array) fluent_cart_get_option('_db_migrations', [], false);
        $option['backfill_failures']['order_currency_snapshot']['version'] = '0.0.1';
        fluent_cart_update_option('_db_migrations', $option);

        $this->assertContains('order_currency_snapshot', DataBackfills::getPending(), 'a new plugin version retries a retired job');

        $ordersTable = $this->fullTableName('fct_orders');
        $breakWrite = function ($query) use ($ordersTable) {
            return (stripos($query, 'UPDATE') === 0 && strpos($query, $ordersTable) !== false && strpos($query, '$.fx') !== false)
                ? 'UPDATE fct_no_such_table SET id = 1'
                : $query;
        };
        add_filter('query', $breakWrite);
        $suppressed = $wpdb->suppress_errors(true);
        $failed = DataBackfills::processPending();
        $wpdb->suppress_errors($suppressed);
        remove_filter('query', $breakWrite);

        $this->assertSame('stalled', $failed['status']);
        $this->assertContains('order_currency_snapshot', DataBackfills::getPending(), 'the new version gets fresh tries');
        $this->assertSame(1, fluent_cart_get_option('_db_migrations', [], false)['backfill_failures']['order_currency_snapshot']['count']);

        $this->assertSame('completed', DataBackfills::processPending()['status']);

        $this->assertSame(CurrencySnapshot::SOURCE_LEGACY_BASE, Order::query()->find($second->id)->config['fx']['source']);
        $this->assertArrayNotHasKey('fx', (array) Order::query()->find($first->id)->config, 'the retry starts after the kept cursor');
        $this->assertSame('yes', fluent_cart_get_option('_db_migrations', [], false)['backfills']['order_currency_snapshot']);
    }

    public function test_a_spent_time_budget_stops_the_call_and_resumes(): void
    {
        global $wpdb;
        $customer = $this->customerFactory->create();
        $first = $this->orderFactory->create(['customer_id' => $customer->id]);
        $second = $this->orderFactory->create(['customer_id' => $customer->id]);
        foreach ([$first->id, $second->id] as $id) {
            $wpdb->update($this->fullTableName('fct_orders'), ['config' => json_encode(new \stdClass())], ['id' => $id]);
        }

        $option = (array) fluent_cart_get_option('_db_migrations', [], false);
        $option['backfills'] = array_fill_keys(array_keys(DataBackfills::getRegistry()), 'yes');
        unset($option['backfills']['order_currency_snapshot']);
        fluent_cart_update_option('_db_migrations', $option);

        $budget = function ($budget, $context) {
            return $context['slug'] === 'order_currency_snapshot'
                ? ['chunk_size' => 1, 'max_chunks_per_run' => 50]
                : $budget;
        };
        add_filter('fluent_cart/data_backfills/chunk_budget', $budget, 10, 2);
        add_filter('fluent_cart/data_backfills/max_seconds', '__return_zero');

        $this->assertSame('running', DataBackfills::processPending()['status']);
        $this->assertArrayNotHasKey('fx', (array) Order::query()->find($second->id)->config, 'the call stops once its time is spent');

        for ($i = 0; $i < 5 && DataBackfills::processPending()['status'] !== 'completed'; $i++) {
            // resumes from the persisted cursor
        }
        remove_filter('fluent_cart/data_backfills/max_seconds', '__return_zero');
        remove_filter('fluent_cart/data_backfills/chunk_budget', $budget, 10);

        $this->assertSame(CurrencySnapshot::SOURCE_LEGACY_BASE, Order::query()->find($first->id)->config['fx']['source']);
        $this->assertSame(CurrencySnapshot::SOURCE_LEGACY_BASE, Order::query()->find($second->id)->config['fx']['source']);
        $this->assertNotContains('order_currency_snapshot', DataBackfills::getPending());
    }

    public function test_the_billing_date_cleanup_stops_on_a_spent_time_budget_and_resumes(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id, 'type' => 'subscription']);
        $ids = [];
        for ($i = 0; $i < 2; $i++) {
            $ids[] = (int) $this->subscriptionFactory->create([
                'customer_id'       => $customer->id,
                'parent_order_id'   => $order->id,
                'product_id'        => 1,
                'variation_id'      => 1,
                'status'            => Status::SUBSCRIPTION_COMPLETED,
                'bill_times'        => 3,
                'bill_count'        => 3,
                'next_billing_date' => '2025-12-01 09:30:00',
            ])->id;
        }

        $option = (array) fluent_cart_get_option('_db_migrations', [], false);
        $option['backfills'] = array_fill_keys(array_keys(DataBackfills::getRegistry()), 'yes');
        unset($option['backfills']['completed_next_billing_date']);
        fluent_cart_update_option('_db_migrations', $option);

        $budget = function ($budget, $context) {
            return $context['slug'] === 'completed_next_billing_date'
                ? ['chunk_size' => 1, 'max_chunks_per_run' => 50]
                : $budget;
        };
        add_filter('fluent_cart/data_backfills/chunk_budget', $budget, 10, 2);
        add_filter('fluent_cart/data_backfills/max_seconds', '__return_zero');

        $this->assertSame('running', DataBackfills::processPending()['status']);
        $this->assertNull($this->getRow('fct_subscriptions', $ids[0])->next_billing_date);
        $this->assertSame('2025-12-01 09:30:00', $this->getRow('fct_subscriptions', $ids[1])->next_billing_date, 'the call stops once its time is spent');
        $this->assertSame($ids[0], (int) fluent_cart_get_option('_fluent_cart_completed_billing_date_cursor', 0, false));

        for ($i = 0; $i < 5 && DataBackfills::processPending()['status'] !== 'completed'; $i++) {
            // resumes from the persisted cursor
        }
        remove_filter('fluent_cart/data_backfills/max_seconds', '__return_zero');
        remove_filter('fluent_cart/data_backfills/chunk_budget', $budget, 10);

        $this->assertNull($this->getRow('fct_subscriptions', $ids[1])->next_billing_date);
        $this->assertNotContains('completed_next_billing_date', DataBackfills::getPending());
    }

    public function test_the_installment_repair_stops_on_a_spent_time_budget_and_resumes(): void
    {
        global $wpdb;
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id, 'type' => 'subscription']);

        // 501 rows the scan reads and skips: one full chunk of 500, then one more row
        $table = $this->fullTableName('fct_subscriptions');
        $values = [];
        for ($i = 0; $i < 501; $i++) {
            $values[] = $wpdb->prepare(
                '(%s, %d, %d, 1, %s, 1, 3, %s)',
                wp_generate_uuid4(),
                $customer->id,
                $order->id,
                'time budget seed',
                '{"is_trial_days_simulated":"no"}'
            );
        }
        $wpdb->query(
            "INSERT INTO {$table} (uuid, customer_id, parent_order_id, product_id, item_name, variation_id, bill_times, config) VALUES "
            . implode(',', $values)
        );
        $ids = array_map('intval', $wpdb->get_col(
            $wpdb->prepare("SELECT id FROM {$table} WHERE item_name = %s ORDER BY id ASC", 'time budget seed')
        ));
        $this->assertCount(501, $ids);

        $option = (array) fluent_cart_get_option('_db_migrations', [], false);
        $option['backfills'] = array_fill_keys(array_keys(DataBackfills::getRegistry()), 'yes');
        unset($option['backfills']['installment_payments']);
        fluent_cart_update_option('_db_migrations', $option);

        add_filter('fluent_cart/data_backfills/max_seconds', '__return_zero');
        $first = DataBackfills::processPending();
        $cursor = (int) fluent_cart_get_option('_fluent_cart_installment_repair_cursor', 0, false);
        $second = DataBackfills::processPending();
        remove_filter('fluent_cart/data_backfills/max_seconds', '__return_zero');

        $this->assertSame('running', $first['status'], 'the call stops after the chunk that spends the time');
        $this->assertSame($ids[499], $cursor);
        $this->assertSame('completed', $second['status']);
        $this->assertSame(['installment_payments'], $second['completed']);
    }

    public function test_one_time_budget_is_shared_by_every_backfill_in_a_call(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id, 'type' => 'subscription']);
        $subscription = $this->subscriptionFactory->create([
            'customer_id'       => $customer->id,
            'parent_order_id'   => $order->id,
            'product_id'        => 1,
            'variation_id'      => 1,
            'status'            => Status::SUBSCRIPTION_COMPLETED,
            'bill_times'        => 3,
            'bill_count'        => 3,
            'next_billing_date' => '2025-12-01 09:30:00',
        ]);

        add_filter('fluent_cart/data_backfills/max_seconds', '__return_zero');
        $first = DataBackfills::processPending();
        $dateAfterFirst = $this->getRow('fct_subscriptions', $subscription->id)->next_billing_date;
        $second = DataBackfills::processPending();
        remove_filter('fluent_cart/data_backfills/max_seconds', '__return_zero');

        $this->assertSame('running', $first['status']);
        $this->assertSame(['installment_payments'], $first['completed'], 'a backfill that ends with the time spent does not start the next one');
        $this->assertSame('2025-12-01 09:30:00', $dateAfterFirst);
        $this->assertSame(['completed_next_billing_date'], $second['completed']);
        $this->assertNull($this->getRow('fct_subscriptions', $subscription->id)->next_billing_date);
    }
}
