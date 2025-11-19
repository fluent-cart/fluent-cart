<?php

namespace FluentCart\Database\Migrations;

use FluentCart\Framework\Database\Schema;

class TaxRatesMigrator extends Migrator
{

    public static string $tableName = 'fct_tax_rates';

    public static function getSqlSchema(): string
    {
        $prefix = static::getDbPrefix();
        $indexPrefix = $prefix . 'fct_txr_';

        // postcode is text to allow multiple postcodes like: 12345, 23456, 34567 or ranges like: 12345...12350
        return "`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `class_id` BIGINT UNSIGNED NOT NULL,
                `country` VARCHAR(45) NULL,
                `state` VARCHAR(45) NULL,
                `postcode` TEXT NULL,
                `city` VARCHAR(45) NULL,
                `rate` VARCHAR(45) NULL,
                `name` VARCHAR(45) NULL,
                `group` VARCHAR(45) NULL,
                `priority` INT UNSIGNED NULL DEFAULT 1,
                `is_compound` TINYINT UNSIGNED NULL DEFAULT 0,
                `for_shipping` TINYINT UNSIGNED NULL DEFAULT NULL,
                `for_order` TINYINT UNSIGNED NULL DEFAULT 0,

                 INDEX `{$indexPrefix}_txr_class_idx` (`class_id` ASC),
                 INDEX `{$indexPrefix}_priority_idx` (`priority` ASC)";
    }
}
