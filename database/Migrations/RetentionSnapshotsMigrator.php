<?php

namespace FluentCart\Database\Migrations;

use FluentCart\App\App;
use FluentCart\Framework\Database\Schema;

class RetentionSnapshotsMigrator extends Migrator
{
    public static string $tableName = "fct_retention_snapshots";

    public static function getSqlSchema(): string
    {
        $indexPrefix = static::getDbPrefix() . 'fct_rs_';

        return "`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `cohort` VARCHAR(7) NOT NULL COMMENT 'YYYY-MM format, when customer first subscribed',
                `period` VARCHAR(7) NOT NULL COMMENT 'YYYY-MM format, the month being measured',
                `product_id` BIGINT(20) UNSIGNED NULL COMMENT 'NULL means all products combined',
                `currency` VARCHAR(10) NOT NULL DEFAULT '' COMMENT 'MRR is only summable within one currency',
                `billing_interval` VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'Empty means all intervals combined',
                `rebuy_adjusted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1: a subscription bought again counts as kept',
                
                -- Cohort baseline (at cohort month)
                `cohort_customers` INT UNSIGNED NOT NULL DEFAULT 0,
                `cohort_mrr` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                
                -- Retention at this period
                `retained_customers` INT UNSIGNED NOT NULL DEFAULT 0,
                `retained_mrr` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                
                -- Movement tracking
                `new_customers` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Recyclers who came back this period',
                `churned_customers` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Left this period',
                
                -- Pre-calculated rates (for fast queries)
                `retention_rate_customers` DECIMAL(10,2) NULL,
                `retention_rate_mrr` DECIMAL(10,2) NULL,
                
                -- Period offset from cohort (Month 1, Month 2, etc)
                `period_offset` INT UNSIGNED NOT NULL DEFAULT 0,
                
                -- Timestamps
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,

                UNIQUE INDEX `{$indexPrefix}cohort_dims_idx` (`cohort`, `period`, `product_id`, `currency`, `billing_interval`, `rebuy_adjusted`),
                INDEX `{$indexPrefix}cohort_idx` (`cohort`),
                INDEX `{$indexPrefix}period_idx` (`period`),
                INDEX `{$indexPrefix}product_idx` (`product_id`),
                INDEX `{$indexPrefix}offset_idx` (`period_offset`)";
    }

    public static function migrated()
    {
        static::addCurrencyDimension();
        static::addCohortDimensions();
        static::widenRateColumns();
    }

    /**
     * MRR retention passes 999.99% when upgrades grow a cohort past ten times its start; MySQL without strict mode
     * (WordPress turns it off) stored 999.99 in the old DECIMAL(5,2)
     */
    public static function widenRateColumns()
    {
        foreach (['retention_rate_customers', 'retention_rate_mrr'] as $column) {
            if (static::getColumnType($column) !== 'decimal(10,2)') {
                static::modifyColumnIfExists($column, 'DECIMAL(10,2) NULL');
            }
        }
    }

    public static function hasWideRateColumns(): bool
    {
        return static::getColumnType('retention_rate_customers') === 'decimal(10,2)'
            && static::getColumnType('retention_rate_mrr') === 'decimal(10,2)';
    }

    /**
     * Snapshots per billing interval, and with or without cancel-and-rebuy counted as kept. Same order as the currency
     * column: add the columns and the wider unique key, then drop the older keys once the new one exists.
     */
    public static function addCohortDimensions()
    {
        // "ALTER TABLE %i ADD COLUMN `billing_interval` VARCHAR(20) NOT NULL DEFAULT '' AFTER `currency`"
        static::addColumnIfNotExists('billing_interval', "VARCHAR(20) NOT NULL DEFAULT ''", 'currency');
        // "ALTER TABLE %i ADD COLUMN `rebuy_adjusted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `billing_interval`"
        static::addColumnIfNotExists('rebuy_adjusted', 'TINYINT(1) NOT NULL DEFAULT 0', 'billing_interval');

        if (!static::hasIndex(static::getDbPrefix() . 'fct_rs_cohort_dims_idx')) {
            static::deleteDuplicateCohortRows();
        }
        static::addIndexIfNotExists(
            static::getDbPrefix() . 'fct_rs_cohort_dims_idx',
            ['cohort', 'period', 'product_id', 'currency', 'billing_interval', 'rebuy_adjusted'],
            true
        );

        if (static::hasCohortDimensions()) {
            static::dropIndexIfExists(static::getDbPrefix() . 'fct_rs_cohort_period_product_currency_idx');
            static::dropIndexIfExists(static::getDbPrefix() . 'fct_rs_cohort_period_product_currency_interval_idx');
        }
    }

    /**
     * Rows written twice while no unique key covered them (two snapshot runs at once) would block the key. The table
     * is a cache: keep the newest of each, the next snapshot run rewrites them all.
     */
    protected static function deleteDuplicateCohortRows()
    {
        $table = static::getTableName();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        Schema::db()->query(Schema::db()->prepare(
            "DELETE older FROM %i AS older JOIN %i AS newer
                ON newer.cohort = older.cohort AND newer.period = older.period AND newer.product_id <=> older.product_id
                AND newer.currency = older.currency AND newer.billing_interval = older.billing_interval
                AND newer.rebuy_adjusted = older.rebuy_adjusted AND newer.id > older.id",
            $table,
            $table
        ));
    }

    public static function hasCohortDimensions(): bool
    {
        return static::getColumnType('billing_interval') !== '' && static::getColumnType('rebuy_adjusted') !== ''
            && static::hasIndex(static::getDbPrefix() . 'fct_rs_cohort_dims_idx');
    }

    /**
     * Snapshots are per currency (dev-docs/multi-currency/02-database-schema.md):
     * add the column, then swap the unique key to include it. Existing
     * rows keep '' and are replaced on the next snapshot run, which
     * truncates and rebuilds the table.
     */
    public static function addCurrencyDimension()
    {
        // "ALTER TABLE %i ADD COLUMN `currency` VARCHAR(10) NOT NULL DEFAULT '' AFTER `product_id`"
        static::addColumnIfNotExists('currency', "VARCHAR(10) NOT NULL DEFAULT ''", 'product_id');

        // The cohort key supersedes this one; re-adding it would fail on rows split by interval
        if (!static::hasCohortDimensions() && static::getColumnType('billing_interval') === '') {
            static::addIndexIfNotExists(
                static::getDbPrefix() . 'fct_rs_cohort_period_product_currency_idx',
                ['cohort', 'period', 'product_id', 'currency'],
                true
            );
        }

        // Keep the old unique key until its replacement exists: a failed ADD
        // followed by a DROP would leave the table with no unique key at all,
        // and the duplicates written meanwhile would block the retry
        if (static::hasCurrencyDimension()) {
            static::dropIndexIfExists(static::getDbPrefix() . 'fct_rs_cohort_period_product_idx');
        }
    }

    /**
     * Snapshot rows refresh only when someone rebuilds them, so a change to the snapshot rules would keep serving the
     * old numbers. Flag stores that hold snapshots: the Cohorts page rebuilds on its next visit (the browser runs the
     * build in short steps; nothing runs in the background). A fresh store has nothing to redo.
     */
    public static function markRebuildNeededIfPopulated()
    {
        // Builds no longer run in the background; a run queued before the update would fail with no handler
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('fluent_cart_generate_retention_snapshots');
        }

        if (!Schema::hasTable(static::$tableName) || !App::db()->table(static::$tableName)->exists()) {
            return;
        }

        update_option('fluent_cart_retention_snapshots_stale', 1, false);
    }

    public static function hasCurrencyDimension(): bool
    {
        // The cohort key holds the currency too
        return static::getColumnType('currency') !== ''
            && (static::hasIndex(static::getDbPrefix() . 'fct_rs_cohort_period_product_currency_idx') || static::hasCohortDimensions());
    }
}
