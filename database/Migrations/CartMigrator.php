<?php

namespace FluentCart\Database\Migrations;

use FluentCart\Framework\Database\Schema;

class CartMigrator extends Migrator
{

    public static string $tableName = 'fct_carts';


    public static function getSqlSchema(): string
    {
        return "`customer_id` BIGINT(20) UNSIGNED NULL,
                `user_id` BIGINT(20) UNSIGNED NULL,
                `order_id` BIGINT(20) UNSIGNED NULL,
                `cart_hash` varchar(192) NOT NULL,
                `checkout_data` longtext NULL,
                `cart_data` longtext NULL,
                `utm_data` longtext NULL,
                `coupons` longtext NULL,
                `first_name` varchar(192) NULL,
                `last_name` varchar(192) NULL,
			    `email` varchar(192) NULL,
                `stage` VARCHAR(30) NULL DEFAULT 'draft', /* draft | pending | in-complete | completed */
                `cart_group` VARCHAR(30) NULL DEFAULT 'global',
                `user_agent` VARCHAR(192) NULL,
                `ip_address` VARCHAR(50) NULL,
                `completed_at` TIMESTAMP NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,
                `deleted_at` TIMESTAMP NULL,
                UNIQUE KEY cart_hash (cart_hash)";
    }
}
