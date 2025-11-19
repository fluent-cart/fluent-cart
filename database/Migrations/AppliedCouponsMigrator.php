<?php

namespace FluentCart\Database\Migrations;


use FluentCart\Framework\Database\Schema;

class AppliedCouponsMigrator extends Migrator
{

	public static string $tableName = 'fct_applied_coupons';

	public static function getSqlSchema(): string
	{
        $indexPrefix = 'fct_acoup_';
		return "`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
            `order_id` BIGINT UNSIGNED NOT NULL,
            `coupon_id` BIGINT UNSIGNED NULL,
            `customer_id` BIGINT UNSIGNED NULL,
			`code` VARCHAR(100) NOT NULL ,
			`amount` double NOT NULL,
			`created_at` DATETIME NULL ,
			`updated_at` DATETIME NULL,
        	INDEX `{$indexPrefix}_code_idx` (`code`)";
	}
}
