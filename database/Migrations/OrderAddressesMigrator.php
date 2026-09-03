<?php

namespace FluentCart\Database\Migrations;

class OrderAddressesMigrator extends Migrator
{
    public static string $tableName = 'fct_order_addresses';

    /**
     * Named once and reused by getSqlSchema(), migrated() and hasOrderIdTypeIndex(),
     * so the three can never drift into declaring, creating and checking different
     * index names.
     */
    const ORDER_ID_TYPE_INDEX = 'idx_order_addresses_order_id_type';

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
                `updated_at` DATETIME NULL,

                INDEX `" . self::ORDER_ID_TYPE_INDEX . "` (`order_id` ASC, `type` ASC)";
    }

    public static function migrated()
    {
        static::addMetaColumn();
        // Same index as getSqlSchema(), by the SAME name on purpose: the schema
        // above covers fresh installs (the table is created with it), this call
        // self-heals tables created before the index was declared. A different
        // name here would leave those installs carrying two identical indexes.
        static::addIndexIfNotExists(self::ORDER_ID_TYPE_INDEX, ['order_id', 'type']);
    }

    /**
     * Whether the composite index is actually present on the table.
     *
     * Public because the DataBackfills delivery path has to VERIFY its work rather
     * than assume it: addIndexIfNotExists() returns void and its ALTER goes through
     * $wpdb->query(), which returns false on failure instead of throwing, so a
     * backfill that cannot see the outcome would retire its slug with no index
     * created. Kept here rather than reimplemented in DataBackfills so this class
     * stays the only place that knows the index name.
     */
    public static function hasOrderIdTypeIndex(): bool
    {
        return static::hasIndex(self::ORDER_ID_TYPE_INDEX);
    }

    public static function addMetaColumn()
    {
        // "ALTER TABLE %i ADD COLUMN `meta` JSON DEFAULT NULL AFTER `country`"
        static::addColumnIfNotExists('meta', 'JSON DEFAULT NULL', 'country');
    }
}
