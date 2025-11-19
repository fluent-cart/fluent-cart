<?php

namespace FluentCart\Database\Migrations;

use FluentCart\Framework\Database\Schema;

class OrderOperationsMigrator extends Migrator
{

    public static string $tableName = 'fct_order_operations';

    public static function getSqlSchema(): string
    {
        $indexPrefix = static::getDbPrefix() . 'fct_oo_';
        return "`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `order_id` BIGINT(20) UNSIGNED NOT NULL,
                `created_via` VARCHAR(45) NULL,
                `emails_sent` tinyint(1) NULL DEFAULT 0,
                `sales_recorded` tinyint(1) NULL DEFAULT 0,
                `utm_campaign` VARCHAR(192) NULL DEFAULT '',
                `utm_term` VARCHAR(192) NULL DEFAULT '',
                `utm_source` VARCHAR(192) NULL DEFAULT '',
                `utm_medium` VARCHAR(192) NULL DEFAULT '',
                `utm_content` VARCHAR(192) NULL DEFAULT '',
                `utm_id` VARCHAR(192) NULL DEFAULT '',
                `cart_hash` VARCHAR(192) NULL DEFAULT '',
                `refer_url` VARCHAR(192) NULL DEFAULT '',
                `meta` json DEFAULT NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,
                INDEX `{$indexPrefix}_order_operations_idx` (`order_id` ASC)";

    }
}
