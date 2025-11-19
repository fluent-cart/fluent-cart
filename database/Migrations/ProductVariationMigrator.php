<?php

namespace FluentCart\Database\Migrations;

use FluentCart\Framework\Database\Schema;

class ProductVariationMigrator extends Migrator
{
    public static string $tableName = 'fct_product_variations';

    public static function getSqlSchema(): string
    {
        $indexPrefix = static::getDbPrefix() . 'fct_pd_var_';
        return "`id` BIGINT(20) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `post_id` BIGINT(20) UNSIGNED NOT NULL,
                `media_id` BIGINT(20) UNSIGNED NULL,
                `serial_index` INT(5) NULL,
                `sold_individually` TINYINT(1) UNSIGNED NULL DEFAULT 0,
                `variation_title` VARCHAR(192) NOT NULL,
                `variation_identifier` VARCHAR(100) NULL,
                `manage_stock` TINYINT(1) NULL DEFAULT 0,
                `payment_type` VARCHAR(50) NULL,
                `stock_status` VARCHAR(30) NULL DEFAULT 'out-of-stock',
                `backorders` TINYINT(1) UNSIGNED NULL DEFAULT 0,
                `total_stock` INT(11) NULL DEFAULT 0,
                `on_hold` INT(11) NULL DEFAULT 0,
                `committed` INT(11) NULL DEFAULT 0,
                `available` INT(11) NULL DEFAULT 0,
                `fulfillment_type` VARCHAR(100) NULL DEFAULT 'physical', /* physicl, digital, service, mixed*/
                `item_status` VARCHAR(30) NULL DEFAULT 'active',
                `manage_cost` VARCHAR(30) NULL DEFAULT 'false',
                `item_price` double DEFAULT 0 NOT NULL,
                `item_cost` double DEFAULT 0 NOT NULL,
                `compare_price` double DEFAULT 0 NULL,
                `shipping_class` BIGINT(20) NULL,
                `other_info` longtext NULL,
                `downloadable` VARCHAR(30) NULL DEFAULT 'false',
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,
                INDEX `{$indexPrefix}_post_id_idx` (`post_id` ASC),
                INDEX `{$indexPrefix}_stock_status_idx` (`stock_status` ASC)";
    }
}
