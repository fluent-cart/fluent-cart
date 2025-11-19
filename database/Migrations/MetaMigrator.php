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


    public static function dropTable()
    {

        if(defined('FLUENTCART_PRESERVER_DEV_META')) {
            return;
        }

        Schema::dropTableIfExists(static::getTableName(false));
    }
}
