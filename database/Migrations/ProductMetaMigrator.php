<?php

namespace FluentCart\Database\Migrations;

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

    public static function migrated()
    {
        static::dropCompositeUniqueIndex();
    }

    public static function dropCompositeUniqueIndex()
    {
        // "ALTER TABLE %i DROP INDEX %i" (index: {prefix}fct_pm__comp_unq)
        static::dropIndexIfExists(static::getDbPrefix() . 'fct_pm__comp_unq');
    }
}
