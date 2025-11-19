<?php

namespace FluentCart\Database\Migrations;

use FluentCart\Framework\Database\Schema;

class OrdersMigrator extends Migrator
{

    public static string $tableName = 'fct_orders';


    public static function getSqlSchema(): string
    {
        $indexPrefix = static::getDbPrefix() . 'fct_ord_';

        return "`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `status` VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft / pending / on-hold / processing / completed / failed / refunded / partial-refund',
                `parent_id` BIGINT UNSIGNED NULL,
                `receipt_number` BIGINT UNSIGNED NULL,
                `invoice_no` VARCHAR(192) NULL DEFAULT '',
                `fulfillment_type` VARCHAR(20) NULL DEFAULT 'physical',
                `type` VARCHAR(20) NOT NULL DEFAULT 'payment',
                `mode` ENUM('live', 'test') NOT NULL DEFAULT 'live' COMMENT 'live / test',
                `shipping_status` VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'unshipped / shipped / delivered / unshippable',
                `customer_id` BIGINT UNSIGNED NULL,
                `payment_method` VARCHAR(100) NOT NULL,
                `payment_status` VARCHAR(20) NOT NULL DEFAULT '',
                `payment_method_title` VARCHAR(100) NOT NULL,
                `currency` VARCHAR(10) NOT NULL,
                `subtotal` BIGINT NOT NULL DEFAULT '0',
                `discount_tax` BIGINT NOT NULL DEFAULT '0',
                `manual_discount_total` BIGINT NOT NULL DEFAULT '0',
                `coupon_discount_total` BIGINT NOT NULL DEFAULT '0',
                `shipping_tax` BIGINT NOT NULL DEFAULT '0',
                `shipping_total` BIGINT NOT NULL DEFAULT '0',
                `tax_total` BIGINT NOT NULL DEFAULT '0',
                `total_amount` BIGINT NOT NULL DEFAULT '0',
                `total_paid` BIGINT NOT NULL DEFAULT '0',
                `total_refund` BIGINT NOT NULL DEFAULT '0',
                `rate` DECIMAL(12,4) NOT NULL DEFAULT '1.0000',
                `tax_behavior` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0 => no_tax, 1 => exclusive, 2 => inclusive',
                `note` TEXT NOT NULL DEFAULT '',
                `ip_address` TEXT NOT NULL DEFAULT '',
                `completed_at` DATETIME NULL DEFAULT NULL,
                `refunded_at` DATETIME NULL DEFAULT NULL,
                `uuid` VARCHAR(100) NOT NULL,
                `config` json DEFAULT NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,

                INDEX `{$indexPrefix}_invoice_no` (`invoice_no`(191) ASC),
                INDEX `{$indexPrefix}_status_type` (`type` ASC),
                INDEX `{$indexPrefix}_customer_id` (`customer_id` ASC),
                INDEX `{$indexPrefix}_date_created_completed` (`created_at` ASC, `completed_at` ASC)";
    }
}
