<?php

namespace FluentCart\Database\Migrations;

use FluentCart\Framework\Database\Schema;

class OrderDownloadPermissionsMigrator extends Migrator
{

    public static string $tableName = 'fct_order_download_permissions';

    public static function getSqlSchema(): string
    {
        $indexPrefix = static::getDbPrefix() . 'fct_odp_';
        return "`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `order_id` BIGINT(20) UNSIGNED NOT NULL,
                `variation_id` BIGINT(20) UNSIGNED NOT NULL,
                `download_id` BIGINT(20) UNSIGNED NOT NULL,
                `download_count` INT(11) NULL,
                `download_limit` INT(11) NULL,
                `access_expires` DATETIME NULL,
                `customer_id` BIGINT(20) UNSIGNED NOT NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,

                 INDEX `{$indexPrefix}_order_id_idx` (`order_id` ASC),
                 INDEX `{$indexPrefix}_download_id_idx` (`download_id` ASC),
                 INDEX `{$indexPrefix}_variation_id_idx` (`variation_id` ASC)";
    }
}
