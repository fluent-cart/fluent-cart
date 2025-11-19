<?php

namespace FluentCart\Database\Migrations;

use FluentCart\Framework\Database\Schema;

class OrderTaxRateMigrator extends Migrator
{

    public static string $tableName = 'fct_order_tax_rate';

    public static function getSqlSchema(): string
    {
        return "`id` BIGINT(20) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
				`order_id` BIGINT(20) UNSIGNED NOT NULL,
                `tax_rate_id` BIGINT(20) UNSIGNED NOT NULL,
                `shipping_tax` BIGINT NULL,
                `order_tax` BIGINT NULL,
                `total_tax` BIGINT NULL,
                `meta` json DEFAULT NULL,
                `filed_at` DATETIME NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL";
    }
}
