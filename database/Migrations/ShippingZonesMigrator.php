<?php

namespace FluentCart\Database\Migrations;


class ShippingZonesMigrator extends Migrator
{
    public static string $tableName = 'fct_shipping_zones';

    public static function getSqlSchema(): string
    {
        $indexPrefix = static::getDbPrefix() . 'fct_sz_';
        return "`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT," .
            "`shipping_class_id` BIGINT UNSIGNED NULL," .
            "`name` VARCHAR(192) NOT NULL," .
            "`region` VARCHAR(192) NOT NULL," .
            "`meta` JSON DEFAULT NULL," .
            "`order` INT UNSIGNED NOT NULL DEFAULT 0," .
            "`created_at` DATETIME NULL," .
            "`updated_at` DATETIME NULL," .
            "INDEX `{$indexPrefix}_order_idx` (`order` ASC)," .
            "INDEX `{$indexPrefix}_class_id_idx` (`shipping_class_id` ASC)";
    }

    public static function migrated()
    {
        // Keep legacy column shape self-healing on every migrate run
        static::renameColumnIfExists('regions', 'region', 'VARCHAR(192) NOT NULL');

        // Ensure shipping_class_id exists on upgrades (fresh installs get it from getSqlSchema)
        static::addColumnIfNotExists('shipping_class_id', 'BIGINT UNSIGNED NULL', 'id');
        static::addIndexIfNotExists(static::getDbPrefix() . 'fct_sz__class_id_idx', 'shipping_class_id');
        static::addColumnIfNotExists('meta', 'JSON DEFAULT NULL', 'region');
    }
}
