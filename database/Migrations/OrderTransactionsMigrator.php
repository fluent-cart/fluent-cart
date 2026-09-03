<?php

namespace FluentCart\Database\Migrations;

use FluentCart\Framework\Database\Schema;

class OrderTransactionsMigrator extends Migrator
{

    public static string $tableName = "fct_order_transactions";

    public static function getSqlSchema(): string
    {
        $indexPrefix = static::getDbPrefix() . 'fct_ot_';

        return "`id` BIGINT(20) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
            `order_id` BIGINT UNSIGNED NOT NULL DEFAULT '0',
            `order_type` VARCHAR(100) NOT NULL DEFAULT '',
            `transaction_type` varchar(192) DEFAULT 'charge',
            `subscription_id` int(11) NULL,
            `card_last_4` VARCHAR(4) NULL,
            `card_brand` varchar(100),
            `vendor_charge_id` VARCHAR(192) NOT NULL DEFAULT '',
            `payment_method` VARCHAR(100) NOT NULL DEFAULT '',
            `payment_mode` VARCHAR(100) NOT NULL DEFAULT '',
            `payment_method_type` VARCHAR(100) NOT NULL DEFAULT '',
            `status` VARCHAR(20) NOT NULL DEFAULT '',
            `currency` VARCHAR(10) NOT NULL DEFAULT '', -- Add the currency column here
            `total` BIGINT NOT NULL DEFAULT '0',
            `rate` BIGINT NOT NULL DEFAULT '1',
            `uuid` VARCHAR(100) NULL DEFAULT '',
            `meta` json DEFAULT NULL,
            `created_at` DATETIME NULL,
            `updated_at` DATETIME NULL,

            INDEX `{$indexPrefix}_ven_charge_id` (`vendor_charge_id`(64) ASC),
            INDEX `{$indexPrefix}_payment_method_idx` (`payment_method` ASC),
            INDEX `{$indexPrefix}_status_idx` (`status` ASC),
            INDEX `{$indexPrefix}_order_id_idx` (`order_id` ASC),
            INDEX `{$indexPrefix}_subscription_id_idx` (`subscription_id` ASC)";
    }

    /**
     * Runs on activation via Migrator::migrate() AND on in-place plugin
     * updates via the version-gated block in
     * DBMigrator::maybeMigrateDBChanges(). addIndexIfNotExists() is
     * idempotent, so double delivery is harmless.
     */
    public static function migrated()
    {
        // Subscription views eager-load transactions filtered by
        // subscription_id (SubscriptionController, customer portal) — without
        // this, every detail view scans the transactions table.
        static::addIndexIfNotExists(static::getDbPrefix() . 'fct_ot__subscription_id_idx', 'subscription_id');

        // card_last_4 was int(4), which silently strips a leading zero
        // (e.g. "0042" -> 42) on write. Widen to VARCHAR so gateway-provided
        // last-4 strings survive intact. modifyColumnIfExists is idempotent.
        static::modifyColumnIfExists('card_last_4', 'VARCHAR(4) NULL');
    }
}
