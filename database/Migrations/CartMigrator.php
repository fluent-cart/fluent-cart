<?php

namespace FluentCart\Database\Migrations;

use FluentCart\Framework\Database\Schema;

class CartMigrator extends Migrator
{

    public static string $tableName = 'fct_carts';


    const CUSTOMER_RECOVERY_INDEX = 'idx_carts_customer_recovery';

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
                UNIQUE KEY cart_hash (cart_hash),
                INDEX `" . self::CUSTOMER_RECOVERY_INDEX . "` (`customer_id` ASC, `cart_hash` ASC),
                INDEX `" . static::userCartIndex() . "` (`user_id` ASC, `cart_group` ASC, `updated_at` ASC)";
    }
    public static function migrated()
    {
        static::addCustomerRecoveryIndex();
        static::addLookupIndexes();
    }

    // Declared here, not in getSqlSchema(), so fresh installs and upgrades both get them.
    // Speeds up cart_group lookups and DailyScheduler::deleteOldCarts()'s updated_at sweep.
    public static function addLookupIndexes(): void
    {
        static::addIndexIfNotExists(
            static::getDbPrefix() . 'fct_carts__group_stage_user',
            ['cart_group', 'stage', 'user_id']
        );

        static::addIndexIfNotExists(
            static::getDbPrefix() . 'fct_carts__updated_at',
            'updated_at'
        );
    }

    public static function addCustomerRecoveryIndex(): void
    {
        static::addIndexIfNotExists(self::CUSTOMER_RECOVERY_INDEX, ['customer_id', 'cart_hash']);
    }

    public static function hasCustomerRecoveryIndex(): bool
    {
        return static::hasIndex(self::CUSTOMER_RECOVERY_INDEX);
    }

    /**
     * A logged-in shopper's latest open cart (CartCurrency::requestCart(),
     * CartResource's user fallback): user_id + cart_group, newest first.
     */
    public static function addUserCartIndex(): void
    {
        static::addIndexIfNotExists(static::userCartIndex(), ['user_id', 'cart_group', 'updated_at']);
    }

    public static function hasUserCartIndex(): bool
    {
        return static::hasIndex(static::userCartIndex());
    }

    private static function userCartIndex(): string
    {
        return static::getDbPrefix() . 'fct_carts_user_group_updated_idx';
    }
}
