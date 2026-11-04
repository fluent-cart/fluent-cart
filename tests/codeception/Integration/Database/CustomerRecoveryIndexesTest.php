<?php

namespace Tests\Integration\Database;

use FluentCart\Database\DBMigrator;
use FluentCart\Database\Migrations\CartMigrator;
use FluentCart\Database\Migrations\OrderDownloadPermissionsMigrator;
use FluentCart\Database\Migrations\SubscriptionsMigrator;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Traits\BuildsRecoveryIndexTables;

class CustomerRecoveryIndexesTest extends DatabaseTestCase
{
    use BuildsRecoveryIndexTables;

    private int $administratorId;
    private $originalVersion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalVersion = get_option('_fluent_cart_db_version');
        $this->administratorId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->administratorId);
        set_current_screen('dashboard');
        update_option('_fluent_cart_db_version', FLUENTCART_DB_VERSION);
        delete_option('_fluent_cart_customer_recovery_indexes_version');
        $this->createRecoveryIndexTables();
    }

    protected function tearDown(): void
    {
        $this->dropRecoveryIndexTables();
        delete_option('_fluent_cart_customer_recovery_indexes_version');
        update_option('_fluent_cart_db_version', $this->originalVersion);
        wp_set_current_user(0);
        wp_delete_user($this->administratorId);
        set_current_screen('front');
        parent::tearDown();
    }

    public function test_fresh_schemas_include_the_recovery_indexes(): void
    {
        $this->dropRecoveryIndexTables();
        $this->createRecoveryIndexTables(false);
        foreach ([SubscriptionsMigrator::class, OrderDownloadPermissionsMigrator::class, CartMigrator::class] as $class) {
            $this->assertTrue($class::hasCustomerRecoveryIndex());
            $class::addCustomerRecoveryIndex();
            $this->assertTrue($class::hasCustomerRecoveryIndex(), 'Reapplying activation must be harmless');
        }
    }

    public function test_current_version_admin_boot_upgrades_existing_tables_and_is_idempotent(): void
    {
        global $wpdb;
        $before = [];
        foreach ([SubscriptionsMigrator::class, OrderDownloadPermissionsMigrator::class, CartMigrator::class] as $class) {
            $this->assertFalse($class::hasCustomerRecoveryIndex());
            $this->seedRecoveryIndexRows($class, 1);
            $before[$class] = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i', $class::getTableName()), ARRAY_A);
        }
        DBMigrator::maybeMigrateDBChanges();
        $this->assertSame('1', get_option('_fluent_cart_customer_recovery_indexes_version'));
        $this->assertSame(FLUENTCART_DB_VERSION, get_option('_fluent_cart_db_version'));
        $ddl = [];
        $filter = function ($query) use (&$ddl) {
            if (stripos($query, 'ALTER TABLE') === 0) {
                $ddl[] = $query;
            }
            return $query;
        };
        add_filter('query', $filter);
        try {
            DBMigrator::maybeMigrateDBChanges();
            $this->assertSame([], $ddl, 'A completed upgrade must not attempt more ALTERs');
        } finally {
            remove_filter('query', $filter);
        }
        foreach ($before as $class => $rows) {
            $this->assertTrue($class::hasCustomerRecoveryIndex());
            $this->assertSame($rows, $wpdb->get_results($wpdb->prepare('SELECT * FROM %i', $class::getTableName()), ARRAY_A));
        }
    }

    public function test_failed_index_creation_keeps_the_upgrade_pending_for_retry(): void
    {
        $filter = function ($query) {
            if (stripos($query, 'ALTER TABLE') === 0 && strpos($query, OrderDownloadPermissionsMigrator::CUSTOMER_RECOVERY_INDEX) !== false) {
                return 'SELECT 1'; // Simulate a DDL operation that leaves no index.
            }
            return $query;
        };
        add_filter('query', $filter);
        try {
            DBMigrator::maybeMigrateDBChanges();
            $this->assertFalse(get_option('_fluent_cart_customer_recovery_indexes_version'));
            $this->assertFalse(OrderDownloadPermissionsMigrator::hasCustomerRecoveryIndex());
        } finally {
            remove_filter('query', $filter);
        }
        DBMigrator::maybeMigrateDBChanges();
        $this->assertSame('1', get_option('_fluent_cart_customer_recovery_indexes_version'));
        $this->assertTrue(OrderDownloadPermissionsMigrator::hasCustomerRecoveryIndex());
    }

    public function test_customer_requests_do_not_run_index_ddl(): void
    {
        wp_set_current_user(0);
        set_current_screen('front');
        DBMigrator::maybeMigrateDBChanges();
        $this->assertFalse(get_option('_fluent_cart_customer_recovery_indexes_version'));
        $this->assertFalse(CartMigrator::hasCustomerRecoveryIndex());
    }

    /** @dataProvider indexedResources */
    public function test_recovery_batch_and_existence_queries_use_customer_indexes(string $class, string $key): void
    {
        global $wpdb;
        $this->seedRecoveryIndexRows($class, 1500);
        $query = $wpdb->prepare('EXPLAIN FORMAT=TRADITIONAL SELECT %i FROM %i WHERE customer_id = %d ORDER BY %i LIMIT 100', $key, $class::getTableName(), 42, $key);
        $before = $wpdb->get_row($query, ARRAY_A);
        $this->assertEmpty($before['possible_keys'], 'Legacy tables have no customer lookup index');
        DBMigrator::maybeMigrateDBChanges();
        $after = $wpdb->get_row($query, ARRAY_A);
        $this->assertSame($class::CUSTOMER_RECOVERY_INDEX, $after['key']);
        $this->assertSame('ref', $after['type']);
        $this->assertLessThan(100, (int) $after['rows']);
        $exists = $wpdb->get_row($wpdb->prepare('EXPLAIN FORMAT=TRADITIONAL SELECT 1 FROM %i WHERE customer_id = %d LIMIT 1', $class::getTableName(), 42), ARRAY_A);
        $this->assertSame($class::CUSTOMER_RECOVERY_INDEX, $exists['key']);
        $columns = $wpdb->get_col($wpdb->prepare('SHOW INDEX FROM %i WHERE Key_name = %s', $class::getTableName(), $class::CUSTOMER_RECOVERY_INDEX), 4);
        $this->assertSame(['customer_id', $key], $columns);
    }

    public function indexedResources(): array
    {
        return [[SubscriptionsMigrator::class, 'id'], [OrderDownloadPermissionsMigrator::class, 'id'], [CartMigrator::class, 'cart_hash']];
    }
}
