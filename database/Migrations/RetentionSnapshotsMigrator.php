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
                `retention_rate_customers` DECIMAL(5,2) NULL,
                `retention_rate_mrr` DECIMAL(5,2) NULL,
                
                -- Period offset from cohort (Month 1, Month 2, etc)
                `period_offset` INT UNSIGNED NOT NULL DEFAULT 0,
                
                -- Timestamps
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,

                UNIQUE INDEX `{$indexPrefix}cohort_period_product_currency_idx` (`cohort`, `period`, `product_id`, `currency`),
                INDEX `{$indexPrefix}cohort_idx` (`cohort`),
                INDEX `{$indexPrefix}period_idx` (`period`),
                INDEX `{$indexPrefix}product_idx` (`product_id`),
                INDEX `{$indexPrefix}offset_idx` (`period_offset`)";
    }

    public static function migrated()
    {
        static::addCurrencyDimension();
    }

    /**
     * Snapshots are per currency (multi-currency plan §3 Phase 2, decision
     * #5): add the column, then swap the unique key to include it. Existing
     * rows keep '' and are replaced on the next snapshot run, which
     * truncates and rebuilds the table.
     */
    public static function addCurrencyDimension()
    {
        // "ALTER TABLE %i ADD COLUMN `currency` VARCHAR(10) NOT NULL DEFAULT '' AFTER `product_id`"
        static::addColumnIfNotExists('currency', "VARCHAR(10) NOT NULL DEFAULT ''", 'product_id');

        static::addIndexIfNotExists(
            static::getDbPrefix() . 'fct_rs_cohort_period_product_currency_idx',
            ['cohort', 'period', 'product_id', 'currency'],
            true
        );

        // Keep the old unique key until its replacement exists: a failed ADD
        // followed by a DROP would leave the table with no unique key at all,
        // and the duplicates written meanwhile would block the retry
        if (static::hasCurrencyDimension()) {
            static::dropIndexIfExists(static::getDbPrefix() . 'fct_rs_cohort_period_product_idx');
        }
    }

    /**
     * Snapshot rows refresh only when an admin clicks Generate Snapshots, so
     * a semantics change (per-currency rows, installment plans excluded)
     * would keep serving the old numbers indefinitely. Queue one rebuild for
     * stores that already hold snapshots; a fresh store has nothing to redo.
     */
    public static function queueRebuildIfPopulated()
    {
        if (!class_exists('ActionScheduler') || !\ActionScheduler::is_initialized()) {
            return;
        }

        if (!Schema::hasTable(static::$tableName) || !App::db()->table(static::$tableName)->exists()) {
            return;
        }

        if (as_has_scheduled_action('fluent_cart_generate_retention_snapshots', null, 'fluent-cart-snapshots')) {
            return;
        }

        as_schedule_single_action(
            time(),
            'fluent_cart_generate_retention_snapshots',
            [null, null],
            'fluent-cart-snapshots'
        );
    }

    public static function hasCurrencyDimension(): bool
    {
        return static::getColumnType('currency') !== ''
            && static::hasIndex(static::getDbPrefix() . 'fct_rs_cohort_period_product_currency_idx');
    }
}
