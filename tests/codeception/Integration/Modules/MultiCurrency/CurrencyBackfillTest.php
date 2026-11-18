<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencySnapshot;
use FluentCart\Database\DataBackfills;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\SubscriptionFactory;

/**
 * Old orders and subscriptions get a currency snapshot — and a non-base order
 * is never given a guessed rate. dev-docs/multi-currency/02-database-schema.md, "Data backfills".
 */
class CurrencyBackfillTest extends DatabaseTestCase
{
    public function test_orders_that_predate_snapshots_are_classified_without_guessing_a_rate(): void
    {
        $customer = (new CustomerFactory())->create();
        $usd = (new OrderFactory())->create(['customer_id' => $customer->id, 'currency' => 'USD']);
        $eur = (new OrderFactory())->create(['customer_id' => $customer->id, 'currency' => 'EUR', 'rate' => 1]);
        $this->makeLegacy('fct_orders', [$usd->id, $eur->id], ['user_tz' => 'UTC']);
        $updatedAtBefore = $this->getRow('fct_orders', $eur->id)->updated_at;

        $this->assertContains('order_currency_snapshot', DataBackfills::getPending());
        $result = DataBackfills::processPending();

        $this->assertSame('completed', $result['status']);

        $usdFx = Order::query()->find($usd->id)->config['fx'];
        $this->assertSame(CurrencySnapshot::SOURCE_LEGACY_BASE, $usdFx['source']);
        $this->assertSame('1.000000000000', $usdFx['rate']);

        $eurOrder = Order::query()->find($eur->id);
        $this->assertSame(CurrencySnapshot::SOURCE_LEGACY_UNKNOWN, $eurOrder->config['fx']['source']);
        $this->assertFalse(CurrencySnapshot::hasTrustedRate($eurOrder->config['fx']), 'a EUR order at rate 1 must not be treated as 1 EUR = 1 USD');
        $this->assertSame('UTC', $eurOrder->config['user_tz'], 'existing config keys survive');
        $this->assertSame('EUR', $eurOrder->currency, 'amounts and currency are never rewritten');
        $this->assertSame($updatedAtBefore, $this->getRow('fct_orders', $eur->id)->updated_at, 'a backfill is not an edit');
        $this->assertNotContains('order_currency_snapshot', DataBackfills::getPending());
    }

    public function test_subscriptions_get_their_currency_column_and_parent_snapshot(): void
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id, 'type' => 'subscription', 'currency' => 'EUR']);
        $subscription = (new SubscriptionFactory())->create([
            'customer_id'     => $customer->id,
            'parent_order_id' => $order->id,
            'product_id'      => 1,
            'variation_id'    => 1,
        ]);
        $this->makeLegacy('fct_orders', [$order->id]);
        $this->makeLegacy('fct_subscriptions', [$subscription->id]);
        global $wpdb;
        $wpdb->update($this->fullTableName('fct_subscriptions'), ['currency' => ''], ['id' => $subscription->id]);

        DataBackfills::processPending();

        $fresh = Subscription::query()->find($subscription->id);
        $this->assertSame('EUR', $fresh->getAttributes()['currency']);
        $this->assertSame(CurrencySnapshot::SOURCE_LEGACY_UNKNOWN, $fresh->config['fx']['source']);
    }

    public function test_a_subscription_in_another_currency_than_its_parent_is_never_given_the_parent_rate(): void
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id, 'type' => 'subscription', 'currency' => 'USD']);
        $subscription = (new SubscriptionFactory())->create([
            'customer_id'     => $customer->id,
            'parent_order_id' => $order->id,
            'product_id'      => 1,
            'variation_id'    => 1,
        ]);
        $this->makeLegacy('fct_orders', [$order->id]);
        $this->makeLegacy('fct_subscriptions', [$subscription->id], ['currency' => 'EUR']);
        global $wpdb;
        $wpdb->update($this->fullTableName('fct_subscriptions'), ['currency' => ''], ['id' => $subscription->id]);

        DataBackfills::processPending();

        $fresh = Subscription::query()->find($subscription->id);
        $this->assertSame('EUR', $fresh->getAttributes()['currency']);
        $this->assertSame('EUR', $fresh->config['fx']['currency']);
        $this->assertSame(CurrencySnapshot::SOURCE_LEGACY_UNKNOWN, $fresh->config['fx']['source']);
    }

    public function test_a_failed_chunk_write_is_retried_not_skipped(): void
    {
        global $wpdb;
        $customer = (new CustomerFactory())->create();
        $ids = [];
        for ($i = 0; $i < 2; $i++) {
            $ids[] = (new OrderFactory())->create(['customer_id' => $customer->id])->id;
        }
        $this->makeLegacy('fct_orders', $ids);

        // Break only the backfill's batched write to the orders table
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

        $this->assertNotSame('completed', $failed['status'], 'a failed write is not completion');
        $this->assertContains('order_currency_snapshot', DataBackfills::getPending());
        foreach ($ids as $id) {
            $this->assertArrayNotHasKey('fx', (array) Order::query()->find($id)->config);
        }

        DataBackfills::processPending();

        foreach ($ids as $id) {
            $this->assertSame(CurrencySnapshot::SOURCE_LEGACY_BASE, Order::query()->find($id)->config['fx']['source'], 'the same rows are retried');
        }
    }

    public function test_a_subscription_write_landing_mid_chunk_survives_the_backfill(): void
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id, 'type' => 'subscription']);
        $subscription = (new SubscriptionFactory())->create([
            'customer_id'     => $customer->id,
            'parent_order_id' => $order->id,
            'product_id'      => 1,
            'variation_id'    => 1,
        ]);
        $this->makeLegacy('fct_subscriptions', [$subscription->id]);
        global $wpdb;
        $wpdb->update($this->fullTableName('fct_subscriptions'), ['currency' => ''], ['id' => $subscription->id]);

        $this->runWithConcurrentWrite('fct_subscriptions', $subscription->id, "`currency` = ''");

        $row = $this->getRow('fct_subscriptions', $subscription->id);
        $config = json_decode($row->config, true);
        $this->assertSame('customer asked', $config['cancel_reason'] ?? null, 'a concurrent config write survives');
        $this->assertTrue(CurrencySnapshot::isSnapshot($config['fx'] ?? null));
        $this->assertNotSame('', $row->currency);
        $this->assertSame('2030-01-02 03:04:05', $row->updated_at, 'a concurrent updated_at is not reverted');
    }

    public function test_an_order_write_landing_mid_chunk_survives_the_backfill(): void
    {
        $order = (new OrderFactory())->create(['customer_id' => (new CustomerFactory())->create()->id]);
        $this->makeLegacy('fct_orders', [$order->id]);

        $this->runWithConcurrentWrite('fct_orders', $order->id, 'JSON_EXTRACT');

        $row = $this->getRow('fct_orders', $order->id);
        $config = json_decode($row->config, true);
        $this->assertSame('customer asked', $config['cancel_reason'] ?? null, 'a concurrent config write survives');
        $this->assertTrue(CurrencySnapshot::isSnapshot($config['fx'] ?? null));
        $this->assertSame('2030-01-02 03:04:05', $row->updated_at, 'a concurrent updated_at is not reverted');
    }

    public function test_an_order_with_a_null_config_still_gets_a_snapshot(): void
    {
        $order = (new OrderFactory())->create(['customer_id' => (new CustomerFactory())->create()->id]);
        global $wpdb;
        $wpdb->query($wpdb->prepare("UPDATE {$this->fullTableName('fct_orders')} SET config = NULL WHERE id = %d", $order->id));

        $this->assertNotSame('locked', DataBackfills::processPending()['status']);

        $config = json_decode($this->getRow('fct_orders', $order->id)->config, true);
        $this->assertSame(CurrencySnapshot::SOURCE_LEGACY_BASE, $config['fx']['source'] ?? null);
    }

    public function test_backfill_resumes_from_its_cursor_across_budgeted_runs(): void
    {
        $customer = (new CustomerFactory())->create();
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = (new OrderFactory())->create(['customer_id' => $customer->id])->id;
        }
        $this->makeLegacy('fct_orders', $ids);

        $budget = function ($budget, $context) {
            return $context['slug'] === 'order_currency_snapshot'
                ? ['chunk_size' => 2, 'max_chunks_per_run' => 1]
                : $budget;
        };
        add_filter('fluent_cart/data_backfills/chunk_budget', $budget, 10, 2);

        $first = DataBackfills::processPending();
        $this->assertSame('running', $first['status']);

        for ($i = 0; $i < 5 && DataBackfills::processPending()['status'] !== 'completed'; $i++) {
            // resumes from the persisted cursor
        }
        remove_filter('fluent_cart/data_backfills/chunk_budget', $budget, 10);

        foreach ($ids as $id) {
            $this->assertSame(CurrencySnapshot::SOURCE_LEGACY_BASE, Order::query()->find($id)->config['fx']['source']);
        }
    }

    /**
     * Run the backfills while another request commits a config change to
     * $id right after the backfill has read its chunk — the window a live
     * cancel or gateway update can land in during an upgrade.
     */
    private function runWithConcurrentWrite(string $table, int $id, string $fetchMarker): void
    {
        global $wpdb;
        $fullTable = $this->fullTableName($table);
        $state = ['fetched' => false, 'written' => false, 'busy' => false];

        $interleave = function ($query) use (&$state, $fullTable, $id, $wpdb, $fetchMarker) {
            if ($state['busy'] || $state['written']) {
                return $query;
            }
            if (!$state['fetched']) {
                $isFetch = stripos(ltrim($query), 'select') === 0
                    && strpos($query, $fullTable) !== false
                    && strpos($query, $fetchMarker) !== false
                    && stripos($query, 'order by') !== false
                    && stripos($query, 'for update') === false;
                $state['fetched'] = $isFetch;
                return $query;
            }
            $state['busy'] = true;
            $wpdb->query($wpdb->prepare(
                "UPDATE {$fullTable} SET config = JSON_SET(config, '$.cancel_reason', 'customer asked'), updated_at = %s WHERE id = %d",
                '2030-01-02 03:04:05',
                $id
            ));
            $state['busy'] = false;
            $state['written'] = true;

            return $query;
        };

        add_filter('query', $interleave);
        DataBackfills::processPending();
        remove_filter('query', $interleave);

        $this->assertTrue($state['written'], 'the concurrent write ran inside the backfill');
    }

    /**
     * Strip config.fx straight in the table, the way a pre-snapshot row looks.
     */
    private function makeLegacy(string $table, array $ids, array $config = []): void
    {
        global $wpdb;
        foreach ($ids as $id) {
            $wpdb->update($this->fullTableName($table), ['config' => json_encode($config ?: new \stdClass())], ['id' => $id]);
        }
    }
}
