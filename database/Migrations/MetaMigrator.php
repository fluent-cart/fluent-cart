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
                 INDEX `{$indexPrefix}_mto_id_idx` (`object_id` ASC),
                 INDEX `{$indexPrefix}_mt_type_key_idx` (`object_type` ASC, `meta_key` ASC)";
    }


    public static function migrated()
    {
        static::renameKeyToMetaKey();
        static::renameValueToMetaValue();
        static::addObjectTypeMetaKeyIndex();
    }

    /**
     * Options are looked up by object_type + meta_key (carrier settings read a
     * `meta_key LIKE` prefix under `object_type = 'option'`). Only `object_type`
     * was indexed, so those reads matched every option row before the meta_key
     * predicate was applied — cost grew with unrelated option metadata.
     *
     * Composite, in that order: equality on object_type, then a range scan on the
     * meta_key prefix. Name must stay identical to getSqlSchema()'s.
     */
    public static function addObjectTypeMetaKeyIndex()
    {
        static::addIndexIfNotExists(
            static::getDbPrefix() . 'fct_mt__mt_type_key_idx',
            ['object_type', 'meta_key']
        );
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
