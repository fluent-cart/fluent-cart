<?php

namespace FluentCart\Database\Migrations;


use FluentCart\Framework\Database\Schema;

class OrderMetaMigrator extends Migrator
{

    public static string $tableName = 'fct_order_meta';

    public static function getSqlSchema(): string
    {
        $indexPrefix = static::getDbPrefix() . 'fct_om_';
        return "`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `order_id` BIGINT(20) NULL,
                `meta_key` VARCHAR(192) NOT NULL,
                `meta_value` LONGTEXT NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,

                INDEX `{$indexPrefix}_ord_id_idx` (`order_id` ASC)";
    }
}
