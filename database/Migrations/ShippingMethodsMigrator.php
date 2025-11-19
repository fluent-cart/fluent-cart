<?php

namespace FluentCart\Database\Migrations;

use FluentCart\Framework\Database\Schema;

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
                `amount` BIGINT UNSIGNED NULL DEFAULT 0,
                `order` INT UNSIGNED NOT NULL DEFAULT 0,
                `meta` json DEFAULT NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,

                INDEX `{$indexPrefix}_zone_id_idx` (`zone_id` ASC),
                INDEX `{$indexPrefix}_order_idx` (`order` ASC)";
    }
}
