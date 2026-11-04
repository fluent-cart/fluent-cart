<?php

namespace Tests\Integration\Database;

use FluentCart\Database\DBMigrator;
use Tests\Support\DatabaseTestCase;

/**
 * Spec: database.md #179-192 — DBMigrator behavior and edge cases.
 */
class DBMigratorTest extends DatabaseTestCase
{
    // =========================================================================
    // DBMigrator (#179-183)
    // =========================================================================

    /** #179 */
    public function test_migrate_creates_all_35_tables(): void
    {
        $tables = [
            'fct_meta', 'fct_atts_groups', 'fct_atts_relations', 'fct_atts_terms',
            'fct_carts', 'fct_coupons', 'fct_customer_addresses', 'fct_customer_meta',
            'fct_customers', 'fct_order_addresses', 'fct_order_download_permissions',
            'fct_order_meta', 'fct_order_operations', 'fct_order_items', 'fct_orders',
            'fct_order_tax_rate', 'fct_order_transactions', 'fct_product_details',
            'fct_product_downloads', 'fct_product_meta', 'fct_subscription_meta',
            'fct_subscriptions', 'fct_tax_classes', 'fct_tax_rates',
            'fct_product_variations', 'fct_applied_coupons', 'fct_label',
            'fct_label_relationships', 'fct_activity', 'fct_webhook_logger',
            'fct_shipping_zones', 'fct_shipping_methods', 'fct_shipping_classes',
            'fct_scheduled_actions', 'fct_retention_snapshots',
        ];

        // migrate() already ran in _bootstrap.php — verify all tables exist
        foreach ($tables as $table) {
            $this->assertTrue($this->tableExists($table), "Table {$table} should exist after migrate()");
        }
    }

    /** #180 */
    public function test_migrate_is_idempotent(): void
    {
        // Run migrate a second time — should not throw or duplicate
        DBMigrator::migrate();

        // Verify tables still exist and no errors
        $this->assertTrue($this->tableExists('fct_orders'));
        $this->assertTrue($this->tableExists('fct_customers'));
        $this->assertTrue($this->tableExists('fct_product_details'));
    }

    /** #181 */
    public function test_migrate_up_updates_db_version_option(): void
    {
        // Clear the option first
        delete_option('_fluent_cart_db_version');

        DBMigrator::migrateUp();

        $version = get_option('_fluent_cart_db_version');
        $this->assertNotEmpty($version, '_fluent_cart_db_version should be set after migrateUp()');
        $this->assertSame(FLUENTCART_DB_VERSION, $version);
    }

    /** #182 */
    public function test_maybe_migrate_db_changes_is_safe_at_current_version(): void
    {
        // Set version to current so maybeMigrateDBChanges sees no work to do
        update_option('_fluent_cart_db_version', FLUENTCART_DB_VERSION);

        DBMigrator::maybeMigrateDBChanges();

        $this->assertTrue($this->tableExists('fct_orders'));
    }

    /** #182a */
    public function test_maybe_migrate_db_changes_drops_legacy_attribute_group_title_unique_index_on_upgrade(): void
    {
        if (!$this->hasIndex('fct_atts_groups', 'title')) {
            $this->addIndex('fct_atts_groups', 'title', ['title'], true);
        }

        $this->assertTrue($this->hasIndex('fct_atts_groups', 'title'));

        update_option('_fluent_cart_db_version', '1.0.39');

        DBMigrator::maybeMigrateDBChanges();

        $this->assertFalse($this->hasIndex('fct_atts_groups', 'title'));
        $this->assertTrue($this->hasIndex('fct_atts_groups', 'slug'));
        $this->assertSame(FLUENTCART_DB_VERSION, get_option('_fluent_cart_db_version'));
    }

    /** #183 */
    public function test_individual_migrators_use_create_table_if_not_exist(): void
    {
        // Each migrator extends Migrator which calls Schema::createTableIfNotExist
        // Running migrate() twice proves this — if it used CREATE TABLE without IF NOT EXISTS, it would error
        global $wpdb;
        DBMigrator::migrate();
        $wpdb->last_error = '';
        DBMigrator::migrate();

        $this->assertSame('', $wpdb->last_error, 'a second migrate() must not raise a "table already exists" error');
    }

    // =========================================================================
    // Edge Cases (#184-192)
    // =========================================================================

    /** #184 */
    public function test_tables_use_correct_wp_prefix(): void
    {
        global $wpdb;
        $expectedPrefix = $wpdb->prefix . 'fct_';

        $tables = $wpdb->get_col("SHOW TABLES LIKE '{$expectedPrefix}%'");
        $this->assertNotEmpty($tables, "Should find tables with prefix {$expectedPrefix}");
        $this->assertGreaterThanOrEqual(35, count($tables));
    }

    /** #185 */
    public function test_orders_monetary_columns_are_bigint_not_decimal(): void
    {
        $monetaryColumns = ['subtotal', 'tax_total', 'total_amount', 'total_paid', 'total_refund'];
        foreach ($monetaryColumns as $colName) {
            $col = $this->getColumn('fct_orders', $colName);
            $this->assertNotNull($col, "Column {$colName} should exist");
            $this->assertStringContainsString('bigint', strtolower($col['Type']),
                "fct_orders.{$colName} should be BIGINT, got {$col['Type']}");
            $this->assertStringNotContainsString('decimal', strtolower($col['Type']),
                "fct_orders.{$colName} should NOT be DECIMAL");
            $this->assertStringNotContainsString('double', strtolower($col['Type']),
                "fct_orders.{$colName} should NOT be DOUBLE");
        }
    }

    /** #186 */
    public function test_product_variations_item_price_is_double(): void
    {
        $col = $this->getColumn('fct_product_variations', 'item_price');
        $this->assertStringContainsString('double', strtolower($col['Type']));
    }

    /** #187 */
    public function test_product_details_prices_are_double(): void
    {
        foreach (['min_price', 'max_price'] as $colName) {
            $col = $this->getColumn('fct_product_details', $colName);
            $this->assertStringContainsString('double', strtolower($col['Type']),
                "fct_product_details.{$colName} should be DOUBLE");
        }
    }

    /** #188 */
    public function test_shipping_methods_amount_is_decimal(): void
    {
        $col = $this->getColumn('fct_shipping_methods', 'amount');
        $this->assertStringContainsString('decimal(10,2)', strtolower($col['Type']));
    }

    /** #189 */
    public function test_json_columns_accept_valid_json(): void
    {
        global $wpdb;

        // All four JSON-capable columns from the spec — each gets a type check
        // AND a write+read round-trip.
        $jsonColumns = [
            ['fct_orders', 'config'],
            ['fct_order_items', 'other_info'],
            ['fct_coupons', 'conditions'],
            ['fct_customers', 'purchase_value'],
        ];

        // Verify column types are JSON or TEXT
        foreach ($jsonColumns as [$table, $column]) {
            $col = $this->getColumn($table, $column);
            $this->assertNotNull($col, "Column {$table}.{$column} should exist");
            $type = strtolower($col['Type']);
            $this->assertTrue(
                str_contains($type, 'json') || str_contains($type, 'text'),
                "Column {$table}.{$column} should be JSON or TEXT type, got {$type}"
            );
        }

        $testJson = '{"round_trip": true, "nested": {"key": "value"}}';
        $ids = [];

        // Round-trip 1: fct_orders.config
        $table = $wpdb->prefix . 'fct_orders';
        $wpdb->insert($table, [
            'status' => 'draft', 'payment_method' => 'test',
            'payment_method_title' => 'Test', 'currency' => 'USD',
            'uuid' => wp_generate_uuid4(), 'config' => $testJson,
        ]);
        $ids[] = ['fct_orders', $wpdb->insert_id];
        $this->assertJsonRoundTrip($table, 'config', $wpdb->insert_id);

        // Round-trip 2: fct_order_items.other_info
        $table = $wpdb->prefix . 'fct_order_items';
        $wpdb->insert($table, [
            'post_title' => 'Test', 'title' => 'Test', 'other_info' => $testJson,
        ]);
        $ids[] = ['fct_order_items', $wpdb->insert_id];
        $this->assertJsonRoundTrip($table, 'other_info', $wpdb->insert_id);

        // Round-trip 3: fct_coupons.conditions
        $table = $wpdb->prefix . 'fct_coupons';
        $wpdb->insert($table, [
            'title' => 'Test Coupon', 'code' => 'TEST_JSON_' . wp_rand(),
            'type' => 'percent', 'amount' => 10, 'status' => 'active',
            'notes' => '', 'conditions' => $testJson,
        ]);
        $ids[] = ['fct_coupons', $wpdb->insert_id];
        $this->assertJsonRoundTrip($table, 'conditions', $wpdb->insert_id);

        // Round-trip 4: fct_customers.purchase_value
        $table = $wpdb->prefix . 'fct_customers';
        $wpdb->insert($table, [
            'email' => 'jsontest@example.com', 'notes' => '',
            'purchase_value' => $testJson,
        ]);
        $ids[] = ['fct_customers', $wpdb->insert_id];
        $this->assertJsonRoundTrip($table, 'purchase_value', $wpdb->insert_id);

        // Clean up all inserted rows
        foreach ($ids as [$shortTable, $id]) {
            $wpdb->delete($wpdb->prefix . $shortTable, ['id' => $id]);
        }
    }

    /** #190 */
    public function test_carts_has_no_auto_increment_id(): void
    {
        $col = $this->getColumn('fct_carts', 'id');
        if ($col !== null) {
            $this->assertStringNotContainsString('auto_increment', strtolower($col['Extra']),
                'fct_carts should not have auto_increment id');
        } else {
            // No id column at all — also valid
            $this->assertNull($col);
        }
    }

    /** #191 */
    public function test_tax_rates_has_no_timestamp_columns(): void
    {
        $columns = $this->getColumnNames('fct_tax_rates');
        $this->assertNotContains('created_at', $columns, 'fct_tax_rates should not have created_at');
        $this->assertNotContains('updated_at', $columns, 'fct_tax_rates should not have updated_at');
    }

    /** #192 */
    public function test_shipping_zones_meta_column_exists_via_migrated_hook(): void
    {
        // meta is added via migrated() hook, not in the initial CREATE TABLE SQL
        $col = $this->getColumn('fct_shipping_zones', 'meta');
        $this->assertNotNull($col, 'fct_shipping_zones.meta should exist (added via migrated() hook)');
    }
}
