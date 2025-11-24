<?php

namespace FluentCartPro\App\Modules\Licensing\Database\Migrations;

class LicensesMigrator
{

    use DropTable;

    public static $tableName = 'fct_licenses';

    /**
     * Migrate the table.
     *
     * @return void
     */
    public static function migrate()
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();

        $table = static::getTableName();

        $indexPrefix = $wpdb->prefix . 'fct_sl_';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            $sql = "CREATE TABLE $table (
                `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `status` VARCHAR(45) NULL DEFAULT 'active',
                `limit` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                `activation_count` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                `license_key` VARCHAR(192) NULL,
                `product_id` BIGINT(20) UNSIGNED NOT NULL,
                `variation_id` BIGINT(20) UNSIGNED NOT NULL,
                `order_id` BIGINT(20) UNSIGNED NOT NULL,
                `parent_id` BIGINT(20) UNSIGNED NULL,
                `customer_id` BIGINT(20) UNSIGNED NOT NULL,
                `expiration_date` DATETIME NULL,
                `last_reminder_sent` DATETIME NULL,
                `last_reminder_type` VARCHAR(50) NULL,
                `subscription_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                `config` json DEFAULT NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                 INDEX `{$indexPrefix}_order_id_idx` (`order_id` ASC),
                 INDEX `{$indexPrefix}_license_key_idx` (`license_key`),
                 INDEX `{$indexPrefix}_product_id_idx` (`product_id` ASC)
            ) $charsetCollate;";
            dbDelta($sql);
        } else {
            // check if config column exists
            $columnExists = $wpdb->get_results("SHOW COLUMNS FROM `$table` LIKE 'config'");
            if (empty($columnExists)) {
                $wpdb->query("ALTER TABLE `$table` ADD `config` json NULL AFTER `subscription_id`;");
            }
        }
    }
}
