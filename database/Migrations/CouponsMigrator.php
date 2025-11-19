<?php

namespace FluentCart\Database\Migrations;


use FluentCart\Framework\Database\Schema;

class CouponsMigrator extends Migrator
{

    public static string $tableName = 'fct_coupons';

    public static function getSqlSchema(): string
    {
        $indexPrefix = static::getDbPrefix() . 'fct_cpn_';
        return "`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
			`title` VARCHAR(200) NOT NULL ,
			`code` VARCHAR(50) NOT NULL UNIQUE ,
            `priority` INT DEFAULT NULL,
			`type` VARCHAR(20) NOT NULL ,
            `conditions` json NULL,
			`amount` double NOT NULL ,
			`use_count` INT DEFAULT 0 ,
			`status` VARCHAR(20) NOT NULL ,
            `notes` LONGTEXT NOT NULL,
            `stackable` VARCHAR(3) NOT NULL DEFAULT 'no',
            `show_on_checkout` VARCHAR(3) NOT NULL DEFAULT 'yes',
            `start_date` TIMESTAMP NULL,
			`end_date` TIMESTAMP  NULL,
			`created_at` DATETIME NULL,
			`updated_at` DATETIME NULL,

            INDEX `{$indexPrefix}_code_idx` (`code` ASC),
            INDEX `{$indexPrefix}_status_idx` (`status` ASC)";
    }
}
