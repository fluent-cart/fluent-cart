<?php

namespace Tests\Integration\Database;

use FluentCart\Database\DBMigrator;
use FluentCart\Database\Migrations\RetentionSnapshotsMigrator;
use Tests\Support\DatabaseTestCase;

/**
 * Stores that were updated (never re-activated) since 1.3.20 have no
 * retention snapshots table. The upgrade must create it, not fatal on it.
 */
class RetentionSnapshotsUpgradeTest extends DatabaseTestCase
{
    const CURRENCY_OPTION = '_fluent_cart_currency_schema_version';

    private int $administratorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->administratorId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->administratorId);
        set_current_screen('dashboard');
        delete_option(self::CURRENCY_OPTION);
    }

    protected function tearDown(): void
    {
        delete_option(self::CURRENCY_OPTION);
        update_option('_fluent_cart_db_version', FLUENTCART_DB_VERSION, 'no');
        wp_set_current_user(0);
        wp_delete_user($this->administratorId);
        set_current_screen('front');
        parent::tearDown();
    }

    public function test_an_update_without_the_table_creates_it_instead_of_failing(): void
    {

        $created = $this->withoutRetentionTable(function () {
            update_option('_fluent_cart_db_version', '1.0.56', 'no');
            DBMigrator::maybeMigrateDBChanges();
        });

        $this->assertTrue($created['table']);
        $this->assertTrue($created['currency']);
        $this->assertSame(FLUENTCART_DB_VERSION, get_option('_fluent_cart_db_version'));
        $this->assertSame(DBMigrator::CURRENCY_SCHEMA_VERSION, get_option(self::CURRENCY_OPTION), 'the currency upgrade completes instead of retrying forever');
    }

    public function test_the_currency_upgrade_creates_a_missing_table(): void
    {
        $created = $this->withoutRetentionTable(function () {
            DBMigrator::maybeMigrateCurrencySchema();
        });

        $this->assertTrue($created['table']);
        $this->assertSame(DBMigrator::CURRENCY_SCHEMA_VERSION, get_option(self::CURRENCY_OPTION));
    }

    public function test_the_rebuild_flag_skips_a_missing_table(): void
    {
        delete_option('fluent_cart_retention_snapshots_stale');
        $this->withoutRetentionTable(function () {
            RetentionSnapshotsMigrator::markRebuildNeededIfPopulated();
        });

        $this->assertFalse(get_option('fluent_cart_retention_snapshots_stale'), 'nothing to rebuild without a table');
    }

    public function test_the_upgrade_cancels_queued_background_builds(): void
    {
        if (!function_exists('as_schedule_single_action')) {
            $this->markTestSkipped('Action Scheduler is not loaded');
        }
        // The background build is gone; a run queued before the update would fail with no handler
        as_schedule_single_action(time() + 60, 'fluent_cart_generate_retention_snapshots', [], 'fluent-cart-snapshots');

        RetentionSnapshotsMigrator::markRebuildNeededIfPopulated();

        $this->assertFalse(as_has_scheduled_action('fluent_cart_generate_retention_snapshots'));
    }

    public function test_the_currency_upgrade_widens_the_rate_columns(): void
    {
        global $wpdb;
        \Tests\Support\Guard\DestructiveDbGuard::assertDbAllowed(DB_HOST, DB_NAME, __METHOD__);
        $table = $wpdb->prefix . 'fct_retention_snapshots';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
        $wpdb->query("ALTER TABLE `{$table}` MODIFY `retention_rate_mrr` DECIMAL(5,2) NULL");
        $this->assertFalse(RetentionSnapshotsMigrator::hasWideRateColumns());

        DBMigrator::maybeMigrateCurrencySchema();

        $this->assertSame([true, DBMigrator::CURRENCY_SCHEMA_VERSION], [RetentionSnapshotsMigrator::hasWideRateColumns(), get_option(self::CURRENCY_OPTION)]);
    }

    public function test_duplicate_snapshot_rows_do_not_block_the_cohort_key(): void
    {
        global $wpdb;
        \Tests\Support\Guard\DestructiveDbGuard::assertDbAllowed(DB_HOST, DB_NAME, __METHOD__);
        $table = $this->fullTableName('fct_retention_snapshots');
        $index = $wpdb->prefix . 'fct_rs_cohort_dims_idx';

        remove_all_filters('query');
        $suppressed = $wpdb->suppress_errors(true);
        try {
            $wpdb->dbh->query("DELETE FROM `{$table}`");
            $wpdb->dbh->query("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
            // Two snapshot runs at once, with no unique key in place, wrote the same row twice
            foreach ([10, 12] as $retained) {
                $wpdb->dbh->query("INSERT INTO `{$table}` (cohort, period, product_id, currency, billing_interval, rebuy_adjusted, retained_customers)
                    VALUES ('2026-06', '2026-09', 2603, 'USD', '', 0, {$retained})");
            }

            RetentionSnapshotsMigrator::addCohortDimensions();

            $this->assertNotEmpty($wpdb->get_results("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$index}'"), 'the unique cohort key is added');
            $this->assertSame(['12'], $wpdb->get_col("SELECT retained_customers FROM `{$table}`"), 'the newer duplicate is kept');
        } finally {
            $wpdb->suppress_errors($suppressed);
            $wpdb->dbh->query("DELETE FROM `{$table}`");
            RetentionSnapshotsMigrator::migrate();
            add_filter('query', [$this, '_create_temporary_tables']);
            add_filter('query', [$this, '_drop_temporary_tables']);
        }
    }

    /**
     * Drop the real table and run $callback with real DDL (WPTestCase would
     * otherwise turn CREATE into a session-only TEMPORARY table). Returns
     * what the callback left behind, before the table is restored.
     */
    private function withoutRetentionTable(callable $callback): array
    {
        global $wpdb;
        \Tests\Support\Guard\DestructiveDbGuard::assertDbAllowed(DB_HOST, DB_NAME, __METHOD__);

        remove_all_filters('query');
        $wpdb->dbh->query('DROP TABLE IF EXISTS `' . $this->fullTableName('fct_retention_snapshots') . '`');
        $suppressed = $wpdb->suppress_errors(true);
        try {
            $callback();

            return [
                'table'    => $this->tableExists('fct_retention_snapshots'),
                'currency' => $this->tableExists('fct_retention_snapshots') && $this->hasColumn('fct_retention_snapshots', 'currency'),
            ];
        } finally {
            $wpdb->suppress_errors($suppressed);
            // Leave a real table for the tests that follow
            RetentionSnapshotsMigrator::migrate();
            add_filter('query', [$this, '_create_temporary_tables']);
            add_filter('query', [$this, '_drop_temporary_tables']);
        }
    }
}
