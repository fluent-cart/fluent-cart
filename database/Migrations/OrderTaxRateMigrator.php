<?php

namespace FluentCart\Database\Migrations;

class OrderTaxRateMigrator extends Migrator
{

    public static string $tableName = 'fct_order_tax_rate';

    public static function getSqlSchema(): string
    {
        return "`id` BIGINT(20) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
				`order_id` BIGINT(20) UNSIGNED NOT NULL,
                `tax_rate_id` BIGINT(20) NOT NULL,
                `shipping_tax` BIGINT NULL,
                `order_tax` BIGINT NULL,
                `total_tax` BIGINT NULL,
                `meta` json DEFAULT NULL,
                `filed_at` DATETIME NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL";
    }

    public static function migrated()
    {
        static::addMetaColumn();
        static::addFiledAtColumn();
        static::allowVirtualTaxRateIds();
        static::deduplicateOrderTaxRates();
        static::addOrderTaxRateUniqueIndex();
    }

    public static function allowVirtualTaxRateIds()
    {
        // EU VAT registration rates are virtual and use negative IDs. Keep zero
        // reserved for the no-tax sentinel while allowing those rows to persist.
        static::modifyColumnIfExists('tax_rate_id', 'BIGINT(20) NOT NULL');
    }

    public static function deduplicateOrderTaxRates()
    {
        // Keep only the latest row per (order_id, tax_rate_id) before adding the unique
        // index — duplicate keys from the old write path would cause ALTER TABLE to fail.
        //
        // "Latest" = highest id. Duplicates existed because the original prepareOtherData()
        // path did INSERT without a uniqueness guard; the last write was always authoritative
        // (it overwrote the in-memory tax_data), so keeping the highest id is correct.
        // Any rows deleted here were already superseded by a later write in the same order.
        global $wpdb;
        $table = $wpdb->prefix . static::$tableName;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("
            DELETE t1 FROM `{$table}` t1
            INNER JOIN `{$table}` t2
            ON t1.order_id = t2.order_id
            AND t1.tax_rate_id = t2.tax_rate_id
            AND t1.id < t2.id
        ");
    }

    public static function addOrderTaxRateUniqueIndex()
    {
        static::addIndexIfNotExists('uniq_order_tax_rate', ['order_id', 'tax_rate_id'], true);
    }

    public static function addMetaColumn()
    {
        // "ALTER TABLE %i ADD COLUMN `meta` JSON"
        static::addColumnIfNotExists('meta', 'JSON');
    }

    public static function addFiledAtColumn()
    {
        // "ALTER TABLE %i ADD COLUMN `filed_at` DATETIME NULL AFTER `meta`"
        static::addColumnIfNotExists('filed_at', 'DATETIME NULL', 'meta');
    }
}
