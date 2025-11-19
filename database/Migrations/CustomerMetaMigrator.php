<?php

namespace FluentCart\Database\Migrations;

use FluentCart\Framework\Database\Schema;

class CustomerMetaMigrator extends Migrator
{

    public static string $tableName = 'fct_customer_meta';

    public static function getSqlSchema(): string
    {
        $indexPrefix = static::getDbPrefix() . 'fct_cm_';

        return "`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `customer_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                `meta_key` VARCHAR(192) NULL DEFAULT NULL,
                `meta_value` LONGTEXT NULL DEFAULT NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,

                 INDEX `{$indexPrefix}_meta_key` (`meta_key` ASC),
                 INDEX `{$indexPrefix}_customer_id` (`customer_id` ASC)";
    }
}
