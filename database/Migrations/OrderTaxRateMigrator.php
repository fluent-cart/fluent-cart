<?php

namespace FluentCart\Database\Migrations;

class OrderTaxRateMigrator extends Migrator
{

    public static string $tableName = 'fct_order_tax_rate';

    public static function getSqlSchema(): string
    {
        return "`id` BIGINT(20) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
				`order_id` BIGINT(20) UNSIGNED NOT NULL,
                `tax_rate_id` BIGINT(20) UNSIGNED NOT NULL,
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
