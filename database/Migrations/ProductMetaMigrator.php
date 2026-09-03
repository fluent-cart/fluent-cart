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

                 INDEX `{$indexPrefix}_meta_key` (`meta_key` ASC),
                 INDEX `{$indexPrefix}_object_meta` (`object_id` ASC, `meta_key` ASC),";
    }

    public static function migrated()
    {
        static::dropCompositeUniqueIndex();
        static::addObjectMetaIndex();
    }

    public static function addObjectMetaIndex()
    {
        // "ALTER TABLE %i ADD INDEX `{prefix}fct_pm__object_meta` (`object_id`, `meta_key`)"
        // Every read of this table looks a row up by owner: `object_id = ? AND meta_key = ?`
        // (variant thumbnails, product licence meta). The only index was on `meta_key`,
        // which is not selective — one key dominates the table — so those lookups scanned
        // it. Composite and non-unique on purpose: the unique variant was dropped above
        // because a single object legitimately holds repeated keys.
        static::addIndexIfNotExists(static::getDbPrefix() . 'fct_pm__object_meta', ['object_id', 'meta_key']);
    }

    public static function dropCompositeUniqueIndex()
    {
        // "ALTER TABLE %i DROP INDEX %i" (index: {prefix}fct_pm__comp_unq)
        static::dropIndexIfExists(static::getDbPrefix() . 'fct_pm__comp_unq');
    }
}
