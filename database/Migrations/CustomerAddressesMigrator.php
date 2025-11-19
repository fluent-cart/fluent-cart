<?php

namespace FluentCart\Database\Migrations;

use FluentCart\Framework\Database\Schema;

class CustomerAddressesMigrator extends Migrator
{

    public static string $tableName = 'fct_customer_addresses';

    public static function getSqlSchema(): string
    {
        $indexPrefix = static::getDbPrefix() . 'fct_cus_ad_';
        return "`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `customer_id` BIGINT UNSIGNED NOT NULL,
                `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
                `type` VARCHAR(20) NOT NULL DEFAULT 'billing',
                `status` VARCHAR(20) NOT NULL DEFAULT 'active',
                `label` VARCHAR(50) NOT NULL DEFAULT '',
                `name` VARCHAR(192) NULL,
                `address_1` VARCHAR(192) NULL,
                `address_2` VARCHAR(192) NULL,
                `city` VARCHAR(192) NULL,
                `state` VARCHAR(192) NULL,
                `phone` VARCHAR(192) NULL,
                `email` VARCHAR(192) NULL,
                `postcode` VARCHAR(32) NULL,
                `country` VARCHAR(100) NULL,
                `meta` JSON DEFAULT NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,

                 INDEX `{$indexPrefix}_customer_is_primary` (`customer_id` ASC, `is_primary` ASC),
                 INDEX `{$indexPrefix}_type` (`type` ASC),
                 INDEX `{$indexPrefix}_status` (`status` ASC)";
    }
}

