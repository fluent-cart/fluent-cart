<?php

namespace FluentCart\Database\Migrations;

use FluentCart\Framework\Database\Schema;

class ProductDetailsMigrator extends Migrator
{
    public static string $tableName = 'fct_product_details';

    public static function getSqlSchema(): string
    {
        $indexPrefix = static::getDbPrefix() . 'fct_pd_';
        return "`id` BIGINT(20) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `post_id` BIGINT(20) UNSIGNED NOT NULL,
                `fulfillment_type` VARCHAR(100) NULL DEFAULT 'physical', /* physical, digital, service, mixed */
                `min_price` double DEFAULT 0 NOT NULL,
                `max_price` double DEFAULT 0 NOT NULL,
                `default_variation_id` BIGINT(20) UNSIGNED NULL,
                `default_media` json NULL,
                `manage_stock` TINYINT(1) NULL DEFAULT 0,
                `stock_availability` VARCHAR(100) NULL DEFAULT 'in-stock', /* computed : in-stock, out-of-stock, backorder*/
                `variation_type` VARCHAR(30) NULL DEFAULT 'simple', /* simple, simple_variation, advance_variation*/
                `manage_downloadable` TINYINT(1) NULL DEFAULT 0,
                `other_info` json NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,

                INDEX `{$indexPrefix}_product_id_idx` (`post_id` ASC),
                INDEX `{$indexPrefix}_product_stock_stockx` (`stock_availability` ASC)";
    }
}
