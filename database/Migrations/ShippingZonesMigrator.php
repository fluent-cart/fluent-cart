<?php

namespace FluentCart\Database\Migrations;


class ShippingZonesMigrator extends Migrator
{
    public static string $tableName = 'fct_shipping_zones';

    public static function getSqlSchema(): string
    {
        $indexPrefix = static::getDbPrefix() . 'fct_sz_';
        return "`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT," .
            "`name` VARCHAR(192) NOT NULL," .
            "`region` VARCHAR(192) NOT NULL," .
            "`order` INT UNSIGNED NOT NULL DEFAULT 0," .
            "`created_at` DATETIME NULL," .
            "`updated_at` DATETIME NULL," .
            "INDEX `{$indexPrefix}_order_idx` (`order` ASC)";
    }
}
