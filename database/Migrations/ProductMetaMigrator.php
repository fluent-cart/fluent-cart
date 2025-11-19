<?php

namespace FluentCart\Database\Migrations;

use FluentCart\Framework\Database\Schema;

class ProductMetaMigrator extends Migrator
{

    public static string $tableName = 'fct_product_meta';

    public static function getSqlSchema(): string
    {
        $indexPrefix = static::getDbPrefix() . 'fct_pm_';

        return "`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `object_id` BIGINT UNSIGNED NOT NULL,
                `object_type` VARCHAR(192) NULL,
                `meta_key` VARCHAR(192) NOT NULL,
                `meta_value` LONGTEXT NULL DEFAULT NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,

                 INDEX `{$indexPrefix}_meta_key` (`meta_key` ASC),";
    }
}
