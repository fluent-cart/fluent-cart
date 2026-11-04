<?php

namespace FluentCart\Database\Migrations;

class ShipmentsMigrator extends Migrator
{
    public static string $tableName = "fct_shipments";

    public static function getSqlSchema(): string
    {
        $indexPrefix = static::getDbPrefix() . 'fct_shp_';

        return "`id`              BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `order_id`        BIGINT UNSIGNED NOT NULL,
                `carrier_slug`    VARCHAR(50)  NOT NULL DEFAULT 'manual',
                `carrier_name`    VARCHAR(100) NOT NULL DEFAULT '',
                `tracking_number` VARCHAR(255) NOT NULL DEFAULT '',
                `tracking_url`    VARCHAR(500) NOT NULL DEFAULT '',
                `items`           LONGTEXT     NULL COMMENT '[{order_item_id: N, qty: N}]',
                `status`          VARCHAR(20)  NOT NULL DEFAULT 'dispatched',
                `notes`           TEXT         NULL,
                `notify_customer` TINYINT(1)   NOT NULL DEFAULT 1,
                `meta`            LONGTEXT     NULL,
                `shipped_at`      DATETIME     NULL,
                `created_at`      DATETIME     NULL,
                `updated_at`      DATETIME     NULL,
                INDEX `{$indexPrefix}order_id` (`order_id` ASC)";
    }
}
