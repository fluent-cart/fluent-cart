<?php

namespace FluentCart\Database\Migrations;


use FluentCart\Framework\Database\Schema;

class MetaMigrator extends Migrator
{
    public static string $tableName = 'fct_meta';

    public static function getSqlSchema(): string
    {
        $indexPrefix = static::getDbPrefix() . 'fct_mt_';
        return "`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `object_type` VARCHAR(50) NOT NULL,
                `object_id` BIGINT NULL,
                `meta_key` VARCHAR(192) NOT NULL,
                `meta_value` LONGTEXT NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,
                 INDEX `{$indexPrefix}_mt_idx` (`object_type` ASC),
                 INDEX `{$indexPrefix}_mto_id_idx` (`object_id` ASC)";
    }


    public static function migrated()
    {
        static::renameKeyToMetaKey();
        static::renameValueToMetaValue();
    }

    public static function renameKeyToMetaKey()
    {
        // "ALTER TABLE %i CHANGE `key` `meta_key` VARCHAR(192)"
        static::renameColumnIfExists('key', 'meta_key', 'VARCHAR(192)');
    }

    public static function renameValueToMetaValue()
    {
        // "ALTER TABLE %i CHANGE `value` `meta_value` LONGTEXT"
        static::renameColumnIfExists('value', 'meta_value', 'LONGTEXT');
    }

    public static function dropTable()
    {

        if(defined('FLUENTCART_PRESERVER_DEV_META')) {
            return;
        }

        Schema::dropTableIfExists(static::getTableName(false));
    }
}
