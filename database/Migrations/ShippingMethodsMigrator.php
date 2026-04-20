<?php

namespace FluentCart\Database\Migrations;

class ShippingMethodsMigrator extends Migrator
{
    public static string $tableName = 'fct_shipping_methods';

    public static function getSqlSchema(): string
    {
        $indexPrefix = static::getDbPrefix() . 'fct_sm_';
        return "`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `zone_id` BIGINT UNSIGNED NOT NULL,
                `title` VARCHAR(192) NOT NULL,
                `type` VARCHAR(50) NOT NULL,
                `settings` LONGTEXT NULL,
                `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `states` json DEFAULT NULL,
                `amount` DECIMAL(10, 2) NULL DEFAULT 0.00,
                `order` INT UNSIGNED NOT NULL DEFAULT 0,
                `meta` json DEFAULT NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,

                INDEX `{$indexPrefix}_zone_id_idx` (`zone_id` ASC),
                INDEX `{$indexPrefix}_order_idx` (`order` ASC)";
    }

    public static function migrated()
    {
        static::addStatesColumn();
        static::modifyStatesToJson();
        static::addMetaColumn();
        static::changeAmountToDecimal();
    }

    public static function addStatesColumn()
    {
        // "ALTER TABLE %i ADD COLUMN `states` JSON NULL AFTER `is_enabled`"
        static::addColumnIfNotExists('states', 'JSON NULL', 'is_enabled');
    }

    public static function modifyStatesToJson()
    {
        // "ALTER TABLE %i MODIFY COLUMN `states` JSON NULL"
        static::modifyColumnIfExists('states', 'JSON NULL');
    }

    public static function addMetaColumn()
    {
        // "ALTER TABLE %i ADD COLUMN `meta` JSON NULL AFTER `order`"
        static::addColumnIfNotExists('meta', 'JSON NULL', 'order');
    }

    public static function changeAmountToDecimal()
    {
        // "ALTER TABLE %i MODIFY COLUMN `amount` DECIMAL(10, 2) NULL DEFAULT 0.00"
        static::modifyColumnIfExists('amount', 'DECIMAL(10, 2) NULL DEFAULT 0.00');
    }
}
