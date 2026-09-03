<?php

namespace FluentCart\Database\Migrations;

use FluentCart\Framework\Database\Schema;

class TaxRatesMigrator extends Migrator
{

    public static string $tableName = 'fct_tax_rates';

    public static function getSqlSchema(): string
    {
        $prefix = static::getDbPrefix();
        $indexPrefix = $prefix . 'fct_txr_';

        // postcode is text to allow multiple postcodes like: 12345, 23456, 34567 or ranges like: 12345-12350
        return "`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `class_id` BIGINT UNSIGNED NOT NULL,
                `country` VARCHAR(45) NULL,
                `state` VARCHAR(45) NULL,
                `postcode` TEXT NULL,
                `city` VARCHAR(45) NULL,
                `rate` VARCHAR(45) NULL,
                `name` VARCHAR(45) NULL,
                `group` VARCHAR(45) NULL,
                `priority` INT UNSIGNED NULL DEFAULT 1,
                `is_compound` TINYINT UNSIGNED NULL DEFAULT 0,
                `for_shipping` DECIMAL(10, 2) NULL DEFAULT NULL,
                `for_order` TINYINT UNSIGNED NULL DEFAULT 0,

                 INDEX `{$indexPrefix}_txr_class_idx` (`class_id` ASC),
                 INDEX `{$indexPrefix}_priority_idx` (`priority` ASC)";
    }

    public static function migrated()
    {
        static::addGroupColumn();
        static::upgradeShippingOverridePrecision();
        static::fixPostcodeRangeSeparator();
    }

    public static function addGroupColumn()
    {
        // "ALTER TABLE %i ADD COLUMN `group` VARCHAR(45) NULL AFTER `name`"
        static::addColumnIfNotExists('group', 'VARCHAR(45) NULL', 'name');
    }

    public static function upgradeShippingOverridePrecision()
    {
        // Before changing the column type, convert the old TINYINT boolean sentinel (1 = "yes,
        // apply the product rate to shipping") to NULL, which carries the same meaning in the
        // new DECIMAL schema ("inherit rate from the rate column"). This guard runs only while
        // the column is still TINYINT — once it is DECIMAL the query is a no-op because no
        // legitimate UI-entered percentage would equal exactly 1.00 on an upgraded install.
        // for_shipping = 0 (no shipping tax) and NULL (inherit) are left untouched.
        $wpdb      = Schema::db();
        $tableName = static::getTableName();

        $colType = '';
        foreach (Schema::getColumnsWithTypes(static::$tableName) as $col) {
            if (isset($col['column_name']) && $col['column_name'] === 'for_shipping') {
                $colType = isset($col['data_type']) ? $col['data_type'] : '';
                break;
            }
        }

        if (strpos($colType, 'tinyint') !== false) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query($wpdb->prepare(
                "UPDATE %i SET `for_shipping` = NULL WHERE `for_shipping` = 1",
                $tableName
            ));
        }

        // Shipping tax overrides are edited as percentages in the admin UI and must
        // preserve decimal precision across both new installs and upgrades.
        static::modifyColumnIfExists('for_shipping', 'DECIMAL(10, 2) NULL DEFAULT NULL');
    }

    public static function fixPostcodeRangeSeparator()
    {
        // The postcode range separator changed from "..." (legacy) and "::" (intermediate) to
        // "-" in the current version. Rows still using the old separators will not match ranges
        // at all; this one-time replacement fixes them silently on upgrade.
        $wpdb      = Schema::db();
        $tableName = static::getTableName();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($wpdb->prepare(
            "UPDATE %i SET `postcode` = REPLACE(`postcode`, '...', '-') WHERE `postcode` LIKE '%%...%%'",
            $tableName
        ));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($wpdb->prepare(
            "UPDATE %i SET `postcode` = REPLACE(`postcode`, '::', '-') WHERE `postcode` LIKE '%%::%%'",
            $tableName
        ));

        // Product category tax overrides in fct_meta carry the same postcode value inside
        // their JSON meta_value — normalize those rows the same way.
        $metaTable = static::getDbPrefix() . 'fct_meta';
        $lastId    = 0;
        $batchSize = 200;

        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT `id`, `meta_value` FROM %i
                  WHERE `object_type` = 'tax_override'
                    AND `meta_key`    = 'product_category_override'
                    AND (`meta_value` LIKE '%%...%%' OR `meta_value` LIKE '%%::%%')
                    AND `id` > %d
                  ORDER BY `id` ASC
                  LIMIT %d",
                $metaTable,
                $lastId,
                $batchSize
            ), ARRAY_A);

            if (!is_array($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int) $row['id'];

                $data = json_decode($row['meta_value'], true);
                if (!is_array($data) || !isset($data['postcode'])) {
                    continue;
                }

                $normalized = str_replace(['...', '::'], '-', $data['postcode']);
                if ($normalized === $data['postcode']) {
                    continue;
                }

                $data['postcode'] = $normalized;
                $encoded          = wp_json_encode($data);
                if ($encoded === false) {
                    continue;
                }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->update(
                    $metaTable,
                    ['meta_value' => $encoded],
                    ['id' => $row['id']],
                    ['%s'],
                    ['%d']
                );
            }
        } while (count($rows) === $batchSize);
    }
}
