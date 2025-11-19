<?php

namespace FluentCart\Database\Migrations;

class OrdersItemsMigrator extends Migrator
{
    public static string $tableName = 'fct_order_items';

    public static function getSqlSchema(): string
    {
        $indexPrefix = static::getDbPrefix() . 'fct_oi_';
        return "`id` BIGINT(20) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `order_id` BIGINT UNSIGNED NOT NULL DEFAULT '0',
                `post_id` BIGINT UNSIGNED NOT NULL DEFAULT '0',
                `fulfillment_type` VARCHAR(20) NOT NULL DEFAULT 'physical',
                `payment_type` VARCHAR(20) NOT NULL DEFAULT 'onetime',
                `post_title` TEXT NOT NULL,
                `title` TEXT NOT NULL,
                `object_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                `cart_index` BIGINT UNSIGNED NOT NULL DEFAULT '0',
                `quantity` INT NOT NULL DEFAULT '1',
                `unit_price` BIGINT NOT NULL DEFAULT '0',
                `cost` BIGINT NOT NULL DEFAULT '0',
                `subtotal` BIGINT NOT NULL DEFAULT '0',
                `tax_amount` BIGINT NOT NULL DEFAULT '0',
                `shipping_charge` BIGINT NOT NULL DEFAULT '0',
                `discount_total` BIGINT NOT NULL DEFAULT '0',
                `line_total` BIGINT NOT NULL DEFAULT '0',
                `refund_total` BIGINT NOT NULL DEFAULT '0',
                `rate` BIGINT NOT NULL DEFAULT '1',
                `other_info` json NULL,
                `line_meta` json NULL,
                `fulfilled_quantity` INT NOT NULL DEFAULT '0',
                `referrer` TEXT NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,

                INDEX `{$indexPrefix}_ord_id_var_id_idx` (`order_id` ASC, `object_id` ASC),
                INDEX `{$indexPrefix}_post_id_idx` (`post_id` ASC)";
    }
}
