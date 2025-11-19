<?php

namespace FluentCart\Database\Migrations;

class ShippingClassesMigrator extends Migrator
{
    public static string $tableName = 'fct_shipping_classes';

    public static function getSqlSchema(): string
    {
        $indexPrefix = static::getDbPrefix() . 'fct_sc_';
        return "`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `name` VARCHAR(192) NOT NULL,
                `cost` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
                `per_item` TINYINT(1) NOT NULL DEFAULT 0,
                `type` VARCHAR(20) NOT NULL DEFAULT 'fixed',
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,

                INDEX `{$indexPrefix}_name_idx` (`name` ASC)";
    }
}
