<?php

namespace Tests\Integration\Database;

use FluentCart\Database\DBMigrator;
use Tests\Support\DatabaseTestCase;

/**
 * The currency columns (wide exchange rates, subscription currency) record
 * their own completion. The DB version is written before the upgrade's steps
 * run, so an ALTER that fails or is cut off on a large orders table would
 * otherwise never run again — and every rate after it would be rounded into
 * the old DECIMAL(12,4) column with no error anywhere.
 */
class CurrencySchemaUpgradeTest extends DatabaseTestCase
{
    const OPTION = '_fluent_cart_currency_schema_version';

    private int $administratorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->administratorId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->administratorId);
        set_current_screen('dashboard');
        delete_option(self::OPTION);
    }

    protected function tearDown(): void
    {
        delete_option(self::OPTION);
        wp_set_current_user(0);
        wp_delete_user($this->administratorId);
        set_current_screen('front');
        parent::tearDown();
    }

    public function test_a_failed_rate_widen_keeps_the_upgrade_pending_for_retry(): void
    {
        $ordersTable = $this->fullTableName('fct_orders');
        $altered = [];
        // The column reports its pre-upgrade type and the ALTER leaves it
        // unchanged — a lock timeout or a killed request, without real DDL
        $fail = function ($query) use ($ordersTable, &$altered) {
            if (stripos($query, 'SHOW COLUMNS FROM') === 0 && strpos($query, $ordersTable) !== false && strpos($query, "'rate'") !== false) {
                return "SELECT 'rate' AS Field, 'decimal(12,4)' AS Type";
            }
            if (stripos($query, 'ALTER TABLE') === 0 && strpos($query, $ordersTable) !== false) {
                $altered[] = $query;
                return 'SELECT 1';
            }
            return $query;
        };

        add_filter('query', $fail);
        try {
            DBMigrator::maybeMigrateDBChanges();
        } finally {
            remove_filter('query', $fail);
        }

        $this->assertNotEmpty($altered, 'the widen was attempted');
        $this->assertFalse(get_option(self::OPTION), 'a failed widen is not recorded as done');

        DBMigrator::maybeMigrateDBChanges();

        $this->assertSame(DBMigrator::CURRENCY_SCHEMA_VERSION, get_option(self::OPTION), 'the next admin load retries and completes it');
    }

    public function test_a_failed_retention_index_keeps_the_upgrade_pending_for_retry(): void
    {
        global $wpdb;
        $index = $wpdb->prefix . 'fct_rs_cohort_period_product_currency_idx';
        // The index reports missing and its ALTER does nothing
        $fail = function ($query) use ($index) {
            if (stripos($query, 'SHOW INDEX FROM') === 0 && strpos($query, $index) !== false) {
                return 'SELECT 1 FROM DUAL WHERE 1 = 0';
            }
            if (stripos($query, 'ALTER TABLE') === 0 && strpos($query, $index) !== false) {
                return 'SELECT 1';
            }
            return $query;
        };

        add_filter('query', $fail);
        try {
            DBMigrator::maybeMigrateDBChanges();
        } finally {
            remove_filter('query', $fail);
        }
        $this->assertFalse(get_option(self::OPTION), 'a failed retention index is not recorded as done');

        DBMigrator::maybeMigrateDBChanges();
        $this->assertSame(DBMigrator::CURRENCY_SCHEMA_VERSION, get_option(self::OPTION));
    }

    public function test_the_old_retention_key_is_kept_until_its_replacement_exists(): void
    {
        global $wpdb;
        $newIndex = $wpdb->prefix . 'fct_rs_cohort_period_product_currency_idx';
        $oldIndex = $wpdb->prefix . 'fct_rs_cohort_period_product_idx';
        $dropped = [];
        // The new index fails to build; the old one is still there
        $fail = function ($query) use ($newIndex, $oldIndex, &$dropped) {
            if (stripos($query, 'SHOW INDEX FROM') === 0 && strpos($query, $newIndex) !== false) {
                return 'SELECT 1 FROM DUAL WHERE 1 = 0';
            }
            if (stripos($query, 'SHOW INDEX FROM') === 0 && strpos($query, $oldIndex) !== false) {
                return "SELECT '{$oldIndex}' AS Key_name";
            }
            if (stripos($query, 'ALTER TABLE') === 0 && strpos($query, $newIndex) !== false) {
                return 'SELECT 1';
            }
            if (stripos($query, 'ALTER TABLE') === 0 && stripos($query, 'DROP') !== false && strpos($query, $oldIndex) !== false) {
                $dropped[] = $query;
                return 'SELECT 1';
            }
            return $query;
        };

        add_filter('query', $fail);
        try {
            DBMigrator::maybeMigrateDBChanges();
        } finally {
            remove_filter('query', $fail);
        }

        $this->assertSame([], $dropped, 'without its replacement the table would have no unique key');
        $this->assertFalse(get_option(self::OPTION));
    }

    public function test_the_currency_schema_completes_without_a_rates_table(): void
    {
        // The rates table belongs to FluentCart Pro; a core-only store never has it
        $ratesQueries = $this->withoutRatesTable(function () {
            DBMigrator::maybeMigrateDBChanges();
        });

        $this->assertSame([], $ratesQueries, 'core neither creates nor checks the rates table');
        $this->assertSame(DBMigrator::CURRENCY_SCHEMA_VERSION, get_option(self::OPTION), 'otherwise every admin load retries forever');
    }

    public function test_core_migrations_do_not_create_the_rates_table(): void
    {
        $ratesQueries = $this->withoutRatesTable(function () {
            DBMigrator::migrate();
        });

        $this->assertSame([], $ratesQueries);
    }

    public function test_a_failed_user_cart_index_keeps_the_upgrade_pending_for_retry(): void
    {
        global $wpdb;
        $index = $wpdb->prefix . 'fct_carts_user_group_updated_idx';
        $fail = function ($query) use ($index) {
            if (stripos($query, 'SHOW INDEX FROM') === 0 && strpos($query, $index) !== false) {
                return 'SELECT 1 FROM DUAL WHERE 1 = 0';
            }
            if (stripos($query, 'ALTER TABLE') === 0 && strpos($query, $index) !== false) {
                return 'SELECT 1';
            }
            return $query;
        };

        add_filter('query', $fail);
        try {
            DBMigrator::maybeMigrateDBChanges();
        } finally {
            remove_filter('query', $fail);
        }
        $this->assertFalse(get_option(self::OPTION), 'a failed index is not recorded as done');

        DBMigrator::maybeMigrateDBChanges();
        $this->assertSame(DBMigrator::CURRENCY_SCHEMA_VERSION, get_option(self::OPTION));
    }

    public function test_the_shoppers_cart_lookup_uses_the_user_cart_index(): void
    {
        DBMigrator::maybeMigrateDBChanges();
        global $wpdb;
        $table = $wpdb->prefix . 'fct_carts';
        // A store's accumulated carts: many users, guests, completed carts
        for ($i = 0; $i < 300; $i++) {
            $wpdb->insert($table, [
                'cart_hash'  => md5('explain-' . $i . wp_generate_password(6, false)),
                'user_id'    => $i % 3 ? 100000 + $i : null,
                'cart_group' => $i % 5 ? 'global' : 'instant',
                'stage'      => $i % 4 ? 'draft' : 'completed',
                'cart_data'  => '[]',
                'created_at' => gmdate('Y-m-d H:i:s', time() - $i * 60),
                'updated_at' => gmdate('Y-m-d H:i:s', time() - $i * 60),
            ]);
        }
        $wpdb->query("ANALYZE TABLE {$table}");

        $plan = $wpdb->get_row($wpdb->prepare(
            "EXPLAIN FORMAT=TRADITIONAL SELECT * FROM {$table} WHERE user_id = %d AND stage != %s AND cart_group = %s ORDER BY updated_at DESC LIMIT 1",
            100001,
            'completed',
            'global'
        ), ARRAY_A);

        $this->assertSame($wpdb->prefix . 'fct_carts_user_group_updated_idx', $plan['key'], 'not a scan of every cart: ' . wp_json_encode($plan));
    }

    public function test_a_store_on_an_earlier_schema_version_runs_the_new_steps(): void
    {
        update_option(self::OPTION, '3', false);

        DBMigrator::maybeMigrateDBChanges();

        $this->assertSame(DBMigrator::CURRENCY_SCHEMA_VERSION, get_option(self::OPTION));
    }

    public function test_a_completed_upgrade_attempts_no_more_alters(): void
    {
        DBMigrator::maybeMigrateDBChanges();
        $this->assertSame(DBMigrator::CURRENCY_SCHEMA_VERSION, get_option(self::OPTION));

        $ddl = [];
        $capture = function ($query) use (&$ddl) {
            if (stripos($query, 'ALTER TABLE') === 0 || stripos($query, 'SHOW COLUMNS') === 0) {
                $ddl[] = $query;
            }
            return $query;
        };
        add_filter('query', $capture);
        try {
            DBMigrator::maybeMigrateDBChanges();
        } finally {
            remove_filter('query', $capture);
        }

        $this->assertSame([], $ddl);
    }

    public function test_customer_requests_do_not_run_the_currency_ddl(): void
    {
        wp_set_current_user(0);
        set_current_screen('front');

        DBMigrator::maybeMigrateDBChanges();

        $this->assertFalse(get_option(self::OPTION));
    }

    /**
     * Run $callback as if the rates table did not exist; returns the queries
     * that reached it (each fails, as on a store without the table).
     */
    private function withoutRatesTable(callable $callback): array
    {
        global $wpdb;
        $ratesTable = $this->fullTableName('fct_currency_rates');
        $queries = [];
        $missing = function ($query) use ($ratesTable, $wpdb, &$queries) {
            if (strpos($query, $ratesTable) === false) {
                return $query;
            }
            $queries[] = $query;

            return 'SELECT * FROM ' . $wpdb->prefix . 'fct_no_such_table';
        };

        $suppressed = $wpdb->suppress_errors(true);
        add_filter('query', $missing);
        try {
            $callback();
        } finally {
            remove_filter('query', $missing);
            $wpdb->suppress_errors($suppressed);
        }

        return $queries;
    }
}
