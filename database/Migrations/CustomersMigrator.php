<?php

namespace FluentCart\Database\Migrations;

use FluentCart\Framework\Database\Schema;

class CustomersMigrator extends Migrator
{

    public static string $tableName = 'fct_customers';

    public static function getSqlSchema(): string
    {
        $indexPrefix = static::getDbPrefix() . 'fct_cus_';

        return "`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `user_id` BIGINT UNSIGNED DEFAULT NULL,
                `contact_id` BIGINT UNSIGNED NOT NULL DEFAULT '0',
                `email` VARCHAR(192) NOT NULL DEFAULT '',
                `first_name` VARCHAR(192) NOT NULL DEFAULT '',
                `last_name` VARCHAR(192) NOT NULL DEFAULT '',
                `status` VARCHAR(45) NULL DEFAULT 'active',
                `purchase_value` json NULL,
                `purchase_count` BIGINT UNSIGNED NOT NULL DEFAULT '0',
                `ltv` BIGINT NOT NULL DEFAULT '0',
                `first_purchase_date` DATETIME NULL,
                `last_purchase_date` DATETIME NULL,
                `aov` DECIMAL(18,2) NULL,
                `notes` LONGTEXT NOT NULL,
                `uuid` VARCHAR(100) NULL DEFAULT '',
                `country` VARCHAR(45) NULL,
                `city` VARCHAR(45) NULL,
                `state` VARCHAR(45) NULL,
                `postcode` VARCHAR(45) NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,

                INDEX `{$indexPrefix}_email` (`email` ASC),
                INDEX `{$indexPrefix}_user_id` (`user_id` ASC)";
    }
}
