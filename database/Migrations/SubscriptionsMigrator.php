<?php

namespace FluentCart\Database\Migrations;

use FluentCart\Framework\Database\Schema;

class SubscriptionsMigrator extends Migrator
{

    public static string $tableName = "fct_subscriptions";


    public static function getSqlSchema(): string
    {
        $indexPrefix = static::getDbPrefix() . 'fct_index_';

        return "`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `uuid` VARCHAR(100) NOT NULL,
                `customer_id` BIGINT(20) UNSIGNED NOT NULL,
                `parent_order_id` BIGINT(20) UNSIGNED NOT NULL,
                `product_id` BIGINT(20) UNSIGNED NOT NULL,
                `item_name` TEXT NOT NULL,
                `quantity` INT NOT NULL DEFAULT '1',
                `variation_id` BIGINT(20) UNSIGNED NOT NULL,
                `billing_interval` VARCHAR(45) NULL,
                `signup_fee` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `initial_tax_total` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `recurring_amount` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `recurring_tax_total` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `recurring_total` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `bill_times` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                `bill_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `expire_at` DATETIME NULL,
                `trial_ends_at` DATETIME NULL,
                `canceled_at` DATETIME NULL,
                `restored_at` DATETIME NULL,
                `collection_method` ENUM('automatic', 'manual', 'system') NOT NULL DEFAULT 'automatic',
                `next_billing_date` DATETIME NULL,
                `trial_days` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `vendor_customer_id` VARCHAR(45) NULL,
                `vendor_plan_id` VARCHAR(45) NULL,
                `vendor_subscription_id` VARCHAR(45) NULL,
                `status` VARCHAR(45) NULL,
                `original_plan` LONGTEXT NULL,
                `vendor_response` LONGTEXT NULL,
                `current_payment_method` VARCHAR(45) NULL,
                `config` json DEFAULT NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,

                 INDEX `{$indexPrefix}_order_subscription_idx` (`parent_order_id` ASC)";
    }
}
