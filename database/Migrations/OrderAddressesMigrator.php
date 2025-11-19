<?php

namespace FluentCart\Database\Migrations;

use FluentCart\Framework\Database\Schema;

class OrderAddressesMigrator extends Migrator
{
    public static string $tableName = 'fct_order_addresses';

    public static function getSqlSchema(): string
    {
        return "`id` BIGINT(20) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `order_id` BIGINT UNSIGNED NOT NULL,
                `type` VARCHAR(20) NOT NULL DEFAULT 'billing',
                `name` VARCHAR(192) NULL,
                `address_1` VARCHAR(192) NULL,
                `address_2` VARCHAR(192) NULL,
                `city` VARCHAR(192) NULL,
                `state` VARCHAR(192) NULL,
                `postcode` VARCHAR(50) NULL,
                `country` VARCHAR(100) NULL,
                `meta` JSON DEFAULT NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL";
    }
}
